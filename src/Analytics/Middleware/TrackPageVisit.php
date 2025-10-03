<?php

declare(strict_types=1);

namespace Sorane\Laravel\Analytics\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Jaybizzle\CrawlerDetect\CrawlerDetect;
use Sorane\Laravel\Analytics\Contracts\RequestFilter;
use Sorane\Laravel\Analytics\HumanProbabilityScorer;
use Sorane\Laravel\Analytics\VisitDataCollector;
use Sorane\Laravel\Jobs\SendPageVisitToSoraneJob;

class TrackPageVisit
{
    public function handle(Request $request, Closure $next)
    {
        if (! config('sorane.enabled', false)) {
            return $next($request);
        }

        if (! config('sorane.website_analytics.enabled', false)) {
            return $next($request);
        }

        // Skip if the request does not have a user agent
        if (! $request->userAgent()) {
            return $next($request);
        }

        // Check for internal requests
        if ($request->header('X-Client-Mode') === 'passive') {
            return $next($request);
        }

        // Excluded paths
        $excludedPaths = config('sorane.website_analytics.excluded_paths', []);
        $firstSegment = explode('/', mb_ltrim($request->path(), '/'))[0];

        if (in_array($firstSegment, $excludedPaths, true)) {
            return $next($request);
        }

        // Request Filter
        $filterClass = config('sorane.website_analytics.request_filter');

        if ($filterClass && class_exists($filterClass)) {
            /** @var RequestFilter $filter */
            $filter = app($filterClass);

            if ($filter->shouldSkip($request)) {
                return $next($request);
            }
        }

        // Filter out unrealistic user agents
        $userAgent = $request->userAgent();

        $minLength = config('sorane.website_analytics.user_agent.min_length', 10);
        $maxLength = config('sorane.website_analytics.user_agent.max_length', 1000);

        // Check for extremely short user agents
        if (mb_strlen($userAgent) < $minLength) {
            return $next($request);
        }

        // Check for excessively long user agents
        if (mb_strlen($userAgent) > $maxLength) {
            return $next($request);
        }

        // List of suspicious patterns that might indicate a fake user agent
        $suspiciousPatterns = [
            'suspicious', 'fake', 'test', 'localhost', 'postman',
            'curl/', 'wget/', 'python-requests', 'empty',
            'clearly-fake', 'not-a-browser', 'unknown',
            'Go-http-client', 'libwww-perl', 'Apache-HttpClient',
            'node-fetch', 'axios/', 'okhttp', 'java/', 'ruby/',
            'perl/', 'scrapy', 'requests/', 'http_request',
        ];

        foreach ($suspiciousPatterns as $pattern) {
            if (mb_stripos($userAgent, $pattern) !== false) {
                return $next($request);
            }
        }

        // Skip known crawlers (1st check, using CrawlerDetect)
        $crawlerDetect = new CrawlerDetect;
        if ($crawlerDetect->isCrawler($request->userAgent())) {
            // Don't track crawlers
            return $next($request);
        }

        // Check if the request is from a bot we know, but are not detected by CrawlerDetect
        $extraBotUserAgents = [
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
            'Claudebot',
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

        foreach ($extraBotUserAgents as $botUserAgent) {
            if (mb_stripos($userAgent, $botUserAgent) !== false) {
                return $next($request);
            }
        }

        // Use the human probability scoring system
        $humanScore = HumanProbabilityScorer::score($request);

        // Skip tracking for requests that are definitely or probably bots
        $botClassifications = ['definitely_bot', 'probably_bot'];
        if (in_array($humanScore['classification'], $botClassifications, true)) {
            return $next($request);
        }

        // Additional bot detection: Check for missing Accept-Language header
        // Real browsers almost always send this header
        $acceptLanguage = $request->header('Accept-Language');
        if (! $acceptLanguage) {
            return $next($request);
        }

        // Additional bot detection: Check for suspicious Accept headers
        // Bots often send "*/*" or empty Accept headers
        $acceptHeader = $request->header('Accept');
        if ($acceptHeader === '*/*' || empty($acceptHeader)) {
            return $next($request);
        }

        // Additional bot detection: Check for data center IP ranges (optional)
        // This would require a GeoIP database or service, so it's commented out
        // if ($this->isDataCenterIp($request->ip())) {
        //     return $next($request);
        // }

        // Collect visit data
        $visitData = VisitDataCollector::collect($request);

        // Add the human probability score to the visit data
        $visitData['human_probability_score'] = $humanScore['score'];
        $visitData['human_probability_reasons'] = $humanScore['reasons'];

        // Build a throttle key, based on the IP and path
        $cacheKey = 'sorane:visit:'.md5(
            $request->ip().'|'.
            $visitData['path'].'|'.
            now()->format('Y-m-d-H-i') // Bucket by minute
        );

        // Only dispatch the job if not sent recently
        $throttleSeconds = config('sorane.website_analytics.throttle_seconds', 30);

        if (! Cache::has($cacheKey)) {
            Cache::put($cacheKey, true, now()->addSeconds($throttleSeconds));

            // Dispatch job to send visit data
            if (config('sorane.website_analytics.queue', true)) {
                SendPageVisitToSoraneJob::dispatch($visitData);
            } else {
                SendPageVisitToSoraneJob::dispatchSync($visitData);
            }
        }

        return $next($request);
    }
}
