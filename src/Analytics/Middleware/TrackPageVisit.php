<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Analytics\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Jaybizzle\CrawlerDetect\CrawlerDetect;
use Ranetrace\Laravel\Analytics\BotSignals;
use Ranetrace\Laravel\Analytics\Contracts\RequestFilter;
use Ranetrace\Laravel\Analytics\HumanProbabilityScorer;
use Ranetrace\Laravel\Analytics\VisitDataCollector;
use Ranetrace\Laravel\Jobs\HandlePageVisitJob;
use Ranetrace\Laravel\Support\InternalLogger;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class TrackPageVisit
{
    /**
     * Default bot/crawler user-agent substrings to filter. The middleware
     * matches case-insensitively (`mb_stripos`). Users can add their own via
     * `ranetrace.website_analytics.extra_bot_user_agents` (merged with these
     * defaults at request time).
     *
     * @var array<int, string>
     */
    protected const array DEFAULT_BOT_USER_AGENTS = [
        'SaaSHub',
        'Mozilla/5.0 (compatible; InternetMeasurement/1.0; +https://internet-measurement.com/)',
        'ALittle Client',
        'Applebot',
        'Baiduspider',
        'BingPreview',
        'Bytespider',
        'CCBot',
        'ChatGPT-User',
        'Claude-Web',
        'ClaudeBot',
        'DataForSeoBot',
        'DotBot',
        'Facebot',
        'facebookexternalhit',
        'GPTBot',
        'ia_archiver',
        'ImagesiftBot',
        'LinkedInBot',
        'MJ12bot',
        'PetalBot',
        'Pinterestbot',
        'SemrushBot',
        'Slackbot',
        'Slurp',
        'TelegramBot',
        'Twitterbot',
        'WhatsApp',
        'YandexBot',
        'Amazon CloudFront',
        'HeadlessChrome',
        'Puppeteer',
        'Playwright',
        'PhantomJS',
        'Electron',
        'Cypress',
        'nightwatch',
        'ZoominfoBot',
        'ahrefsbot',
        'DuckDuckBot',
        'Screaming Frog',
        'serpstatbot',
        'MojeekBot',
    ];

    /**
     * Default first-path-segments excluded from analytics. Used as the in-code
     * fallback so that a published config which removed the `excluded_paths`
     * key restores these defaults rather than silently disabling all path
     * exclusion. Mirrors config/ranetrace.php → website_analytics.excluded_paths.
     *
     * @var array<int, string>
     */
    protected const array DEFAULT_EXCLUDED_PATHS = [
        'horizon', 'nova', 'telescope', 'admin', 'filament',
        'api', 'debugbar', 'storage', 'livewire', '_debugbar',
        'up', 'sanctum', '_ignition',
    ];

    /**
     * Per-worker cache of the CrawlerDetect instance — the library's internal
     * data structures are non-trivial to construct, and the middleware runs on
     * every request. One instance per worker is plenty.
     */
    private static ?CrawlerDetect $crawlerDetect = null;

    public function handle(Request $request, Closure $next): Response
    {
        // The middleware sits in every web request's path. It MUST NEVER throw
        // back into the host application — a failure here would 500 every
        // page. Per the package's Core Rule, capture is wrapped and the
        // request continues regardless.
        try {
            $this->captureVisit($request);
        } catch (Throwable $e) {
            InternalLogger::error('Failed to capture page visit', [
                'exception' => $e->getMessage(),
            ]);
        }

        return $next($request);
    }

    /**
     * Decide whether the request is a trackable page view and, if so, buffer it.
     *
     * The skips below fall into two groups, deliberately kept apart:
     *
     * - Correctness/safety skips (this method, in code): rules the package owns
     *   and must be able to fix for every installation. They are NOT expressed
     *   as `excluded_paths` entries, because a published config freezes that
     *   array — the in-code fallback only applies when the key is absent — so
     *   anything added there upstream never reaches an app that published the
     *   config.
     * - App-owned preferences (`website_analytics.excluded_paths`): which of
     *   *their* sections a host does not want in analytics. Editable, and the
     *   package never depends on an entry being present.
     */
    private function captureVisit(Request $request): void
    {
        if (! config('ranetrace.enabled', true) || ! config('ranetrace.website_analytics.enabled', false)) {
            return;
        }

        // Page-view analytics is GET-only by design. This structurally excludes
        // form submissions, broadcasting/auth calls and component-framework XHR
        // (Livewire, Inertia) without relying on any path matching.
        if (! $request->isMethod('GET')) {
            return;
        }

        // Defense-in-depth for Livewire GETs: Livewire 4 serves its endpoint
        // from /livewire-{hash}/update (the hash derives from APP_KEY), so no
        // static path can match it. The header is read directly — this package
        // does not depend on Livewire and must never reference its classes.
        if ($request->headers->has('X-Livewire')) {
            return;
        }

        if ($this->isRanetraceDashboardRequest($request)) {
            return;
        }

        $userAgent = $request->userAgent();
        if (! $userAgent) {
            return;
        }

        if ($request->header('X-Client-Mode') === 'passive') {
            return;
        }

        $excludedPaths = config('ranetrace.website_analytics.excluded_paths', self::DEFAULT_EXCLUDED_PATHS);
        // $request->path() is already trimmed of a leading slash.
        $firstSegment = explode('/', $request->path())[0];

        if (in_array($firstSegment, $excludedPaths, true)) {
            return;
        }

        $filterClass = config('ranetrace.website_analytics.request_filter');

        if ($filterClass && class_exists($filterClass)) {
            $filter = app($filterClass);

            if (! $filter instanceof RequestFilter) {
                // A misconfigured filter shouldn't crash capture (and the
                // try/catch would swallow it anyway). Skip it loudly instead.
                InternalLogger::warning('Configured request_filter does not implement RequestFilter; ignoring it', [
                    'filter' => $filterClass,
                ]);
            } elseif ($filter->shouldSkip($request)) {
                return;
            }
        }

        $minLength = config('ranetrace.website_analytics.user_agent.min_length', 10);
        $maxLength = config('ranetrace.website_analytics.user_agent.max_length', 1000);

        $userAgentLength = mb_strlen($userAgent);
        if ($userAgentLength < $minLength || $userAgentLength > $maxLength) {
            return;
        }

        foreach (BotSignals::SUSPICIOUS_USER_AGENT_PATTERNS as $pattern) {
            if (mb_stripos($userAgent, $pattern) !== false) {
                return;
            }
        }

        // CrawlerDetect is cached per-worker — its internal data structures
        // are non-trivial to construct and this runs on every request.
        self::$crawlerDetect ??= new CrawlerDetect;
        if (self::$crawlerDetect->isCrawler($userAgent)) {
            return;
        }

        $extraBots = array_merge(
            self::DEFAULT_BOT_USER_AGENTS,
            (array) config('ranetrace.website_analytics.extra_bot_user_agents', [])
        );

        foreach ($extraBots as $botUserAgent) {
            if (mb_stripos($userAgent, $botUserAgent) !== false) {
                return;
            }
        }

        $humanScore = HumanProbabilityScorer::score($request);

        if (in_array($humanScore['classification'], ['definitely_bot', 'probably_bot'], true)) {
            return;
        }

        // Real browsers almost always send Accept-Language; bots often
        // send "*/*" or empty Accept headers.
        if (! $request->header('Accept-Language')) {
            return;
        }

        $acceptHeader = $request->header('Accept');
        if (! $acceptHeader || $acceptHeader === '*/*') {
            return;
        }

        $visitData = VisitDataCollector::collect($request);
        $visitData['human_probability_score'] = $humanScore['score'];
        $visitData['human_probability_reasons'] = $humanScore['reasons'];

        // Throttle to one capture per IP + path per throttle_seconds window.
        // Cache::add is atomic (put-if-absent), so concurrent requests can't both
        // pass a check-then-set race. The key is NOT time-bucketed — the TTL alone
        // defines the window, so throttle_seconds works for any value (a minute
        // bucket would silently cap it at ~60s).
        $cacheKey = 'ranetrace:visit:'.md5(
            $request->ip().'|'.
            $visitData['path']
        );

        $throttleSeconds = config('ranetrace.website_analytics.throttle_seconds', 30);

        // Use the Ranetrace cache store (same as the buffer/pause manager) so the
        // throttle is consistent and actually shared across workers — the host's
        // default cache may be `array`, which would make this a per-process no-op.
        $throttleStore = Cache::store(config('ranetrace.batch.cache_driver', 'file'));

        if ($throttleStore->add($cacheKey, true, now()->addSeconds($throttleSeconds))) {
            if (config('ranetrace.website_analytics.queue', true)) {
                HandlePageVisitJob::dispatch($visitData);
            } else {
                HandlePageVisitJob::dispatchSync($visitData);
            }
        }
    }

    /**
     * Whether the request targets Ranetrace's own diagnostics dashboard.
     *
     * The dashboard prefix is configurable, so it cannot be expressed as a
     * static `excluded_paths` entry. The route-name check is the primary
     * signal — this runs as `web` group middleware, so the route is already
     * resolved — and the path comparison covers edge wiring where no route
     * matched. When a dashboard domain is configured, the path comparison only
     * applies on that host: the same path on the main domain is a legitimate
     * page of the host application.
     */
    private function isRanetraceDashboardRequest(Request $request): bool
    {
        if ($request->routeIs('ranetrace.*')) {
            return true;
        }

        $prefix = mb_trim((string) config('ranetrace.dashboard.path', 'ranetrace'), '/');

        if ($prefix === '') {
            return false;
        }

        $domain = config('ranetrace.dashboard.domain');

        if (is_string($domain) && $domain !== '' && $request->getHost() !== $domain) {
            return false;
        }

        return $request->is($prefix) || $request->is($prefix.'/*');
    }
}
