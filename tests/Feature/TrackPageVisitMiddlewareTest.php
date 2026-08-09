<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Ranetrace\Laravel\Analytics\Middleware\TrackPageVisit;
use Ranetrace\Laravel\Jobs\HandlePageVisitJob;

test('it tracks page visits for normal requests', function (): void {
    Bus::fake();
    Cache::flush(); // Clear cache to ensure throttle doesn't interfere

    $response = $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/91.0',
    ])->get('/');

    $response->assertStatus(200);

    Bus::assertDispatched(HandlePageVisitJob::class);
});

test('it does not track crawler visits', function (): void {
    Bus::fake();

    $response = $this->withHeaders([
        'User-Agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)',
    ])->get('/');

    Bus::assertNotDispatched(HandlePageVisitJob::class);
});

test('it does not track requests without user agent', function (): void {
    Bus::fake();

    $response = $this->get('/');

    Bus::assertNotDispatched(HandlePageVisitJob::class);
});

test('it respects excluded paths configuration', function (): void {
    Bus::fake();

    // Real routes, so the request reaches the middleware: a routeless path
    // 404s during routing and would pass this test with the exclusion check
    // deleted entirely.
    Route::get('/admin/dashboard', fn () => response('OK'))
        ->middleware(['web', TrackPageVisit::class]);
    Route::get('/api/users', fn () => response('OK'))
        ->middleware(['web', TrackPageVisit::class]);

    config(['ranetrace.website_analytics.excluded_paths' => ['admin', 'api']]);

    $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0',
    ])->get('/admin/dashboard')->assertStatus(200);

    Bus::assertNotDispatched(HandlePageVisitJob::class);

    $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0',
    ])->get('/api/users')->assertStatus(200);

    Bus::assertNotDispatched(HandlePageVisitJob::class);
});

test('it falls back to default excluded paths when the config key is absent', function (): void {
    Bus::fake();
    Cache::flush();

    Route::get('/admin/dashboard', fn () => response('OK'))
        ->middleware(['web', TrackPageVisit::class]);

    // Simulate a published config that removed the excluded_paths key entirely.
    $analytics = config('ranetrace.website_analytics');
    unset($analytics['excluded_paths']);
    config(['ranetrace.website_analytics' => $analytics]);

    $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Accept' => 'text/html',
        'Accept-Language' => 'en-US',
    ])->get('/admin/dashboard')->assertStatus(200);

    // 'admin' is still excluded via TrackPageVisit::DEFAULT_EXCLUDED_PATHS.
    Bus::assertNotDispatched(HandlePageVisitJob::class);
});

test('it excludes a path whose first segment arrives percent-encoded', function (): void {
    Bus::fake();
    Cache::flush();

    // A real route is needed: without one the request 404s during routing and
    // never reaches the middleware, which would pass this test for the wrong
    // reason.
    Route::get('/admin/dashboard', fn () => response('OK'))
        ->middleware(['web', TrackPageVisit::class]);

    config(['ranetrace.website_analytics.excluded_paths' => ['admin']]);

    $headers = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
        'Accept-Language' => 'en-US,en;q=0.9',
    ];

    // Routes to the same excluded handler; $request->path() would still read
    // `%61dmin/dashboard` and miss the exclusion list.
    $this->withHeaders($headers)->get('/%61dmin/dashboard')->assertStatus(200);

    Bus::assertNotDispatched(HandlePageVisitJob::class);
});

test('it tracks allowed paths', function (): void {
    Bus::fake();
    Cache::flush();

    config(['ranetrace.website_analytics.excluded_paths' => ['admin']]);

    $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/91.0',
    ])->get('/products');

    Bus::assertDispatched(HandlePageVisitJob::class);
});

test('it filters suspicious user agents', function (): void {
    Bus::fake();

    $suspiciousAgents = [
        'curl/7.68.0',
        'python-requests',
        'Postman Runtime',
        'test',
        'ab', // Too short
        'Go-http-client/1.1',
        'axios/0.21.1',
        'HeadlessChrome/91.0',
        'Puppeteer/10.0',
    ];

    foreach ($suspiciousAgents as $agent) {
        $this->withHeaders(['User-Agent' => $agent])->get('/');
    }

    Bus::assertNotDispatched(HandlePageVisitJob::class);
});

test('it includes human probability score', function (): void {
    Bus::fake();
    Cache::flush();

    $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/91.0',
        'Accept' => 'text/html',
        'Accept-Language' => 'en-US',
    ])->get('/');

    Bus::assertDispatched(HandlePageVisitJob::class, function ($job): bool {
        return isset($job->getVisitData()['human_probability_score'])
            && isset($job->getVisitData()['human_probability_reasons']);
    });
});

test('it throttles duplicate visits', function (): void {
    Bus::fake();
    Cache::flush();

    $headers = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
        'Accept-Language' => 'en-US,en;q=0.9',
    ];

    // First request
    $this->withHeaders($headers)->get('/test-page');
    Bus::assertDispatchedTimes(HandlePageVisitJob::class, 1);

    // Second request within throttle window
    $this->withHeaders($headers)->get('/test-page');
    Bus::assertDispatchedTimes(HandlePageVisitJob::class, 1); // Still just 1
});

test('it throttles encoded variants of one path into a single bucket', function (): void {
    Bus::fake();
    Cache::flush();

    $headers = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
        'Accept-Language' => 'en-US,en;q=0.9',
    ];

    $this->withHeaders($headers)->get('/test-page')->assertStatus(200);
    Bus::assertDispatchedTimes(HandlePageVisitJob::class, 1);

    // Same page, re-encoded request line: the router rawurldecodes and matches
    // the same route, so this must not open a fresh throttle bucket.
    $this->withHeaders($headers)->get('/%74est-page')->assertStatus(200);
    Bus::assertDispatchedTimes(HandlePageVisitJob::class, 1);

    // Lowercase hex is a third spelling of the same request.
    $this->withHeaders($headers)->get('/%74est-%70age')->assertStatus(200);
    Bus::assertDispatchedTimes(HandlePageVisitJob::class, 1);
});

test('it keeps throttling across a minute boundary within the throttle window', function (): void {
    Bus::fake();
    Cache::flush();
    config(['ranetrace.website_analytics.throttle_seconds' => 120]);

    $headers = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
        'Accept-Language' => 'en-US,en;q=0.9',
    ];

    // Pin to 10 seconds before a minute boundary.
    $this->travelTo(Illuminate\Support\Carbon::create(2026, 1, 1, 10, 0, 50));
    $this->withHeaders($headers)->get('/test-page');
    Bus::assertDispatchedTimes(HandlePageVisitJob::class, 1);

    // 30s later — now in the NEXT minute, but still inside the 120s window.
    // The old per-minute key bucket would have reset and dispatched again.
    $this->travelTo(Illuminate\Support\Carbon::create(2026, 1, 1, 10, 1, 20));
    $this->withHeaders($headers)->get('/test-page');
    Bus::assertDispatchedTimes(HandlePageVisitJob::class, 1);

    $this->travelBack();
});

test('it does not track when analytics is disabled', function (): void {
    config(['ranetrace.website_analytics.enabled' => false]);

    // Restart the application to re-register middleware
    $this->refreshApplication();

    Bus::fake();
    config(['ranetrace.website_analytics.enabled' => false]);

    $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0',
    ])->get('/');

    Bus::assertNotDispatched(HandlePageVisitJob::class);
});

test('it skips internal requests', function (): void {
    Bus::fake();

    $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0',
        'X-Client-Mode' => 'passive',
    ])->get('/');

    Bus::assertNotDispatched(HandlePageVisitJob::class);
});

test('it filters requests without Accept-Language header', function (): void {
    Bus::fake();
    Cache::flush();

    // Create a request without Accept-Language
    $request = Illuminate\Http\Request::create('/', 'GET');
    $request->headers->set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    $request->headers->set('Accept', 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8');

    // Remove the default Accept-Language header that Laravel adds
    $request->headers->remove('Accept-Language');

    // Verify no Accept-Language header
    expect($request->header('Accept-Language'))->toBeNull();

    $middleware = new TrackPageVisit;
    $response = $middleware->handle($request, function ($req) {
        return response('OK');
    });

    Bus::assertNotDispatched(HandlePageVisitJob::class);
});

test('it filters requests with generic Accept header', function (): void {
    Bus::fake();
    Cache::flush();

    $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Accept' => '*/*',
        'Accept-Language' => 'en-US',
    ])->get('/');

    Bus::assertNotDispatched(HandlePageVisitJob::class);
});

test('it tracks requests with proper browser headers', function (): void {
    Bus::fake();
    Cache::flush();

    $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
        'Accept-Language' => 'en-US,en;q=0.9',
        'Accept-Encoding' => 'gzip, deflate, br',
    ])->get('/');

    Bus::assertDispatched(HandlePageVisitJob::class);
});

test('it ignores a misconfigured request_filter that does not implement RequestFilter', function (): void {
    Bus::fake();
    Cache::flush();

    // A class that exists but does NOT implement the RequestFilter contract.
    config(['ranetrace.website_analytics.request_filter' => stdClass::class]);

    $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
        'Accept-Language' => 'en-US,en;q=0.9',
    ])->get('/test-page')->assertStatus(200);

    // The bad filter is skipped (not invoked), so capture proceeds normally —
    // before the instanceof guard this threw and the visit was never dispatched.
    Bus::assertDispatched(HandlePageVisitJob::class);
});

test('the middleware never lets a capture failure 500 the request (failure isolation)', function (): void {
    Bus::fake();
    Cache::flush();

    // A valid RequestFilter whose shouldSkip() throws mid-capture, simulating an
    // unexpected fault inside captureVisit().
    config(['ranetrace.website_analytics.request_filter' => ThrowingRequestFilterFixture::class]);

    $response = $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Accept' => 'text/html',
        'Accept-Language' => 'en-US',
    ])->get('/test-page');

    // The throw is swallowed by the middleware's try/catch; the user still gets
    // their page (no 500) and nothing is dispatched.
    $response->assertStatus(200);
    Bus::assertNotDispatched(HandlePageVisitJob::class);
});

test('the buffered visit payload strips the raw ip and user agent (T4)', function (): void {
    Bus::fake();
    Cache::flush();

    $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Accept' => 'text/html',
        'Accept-Language' => 'en-US',
    ])->get('/');

    Bus::assertDispatched(HandlePageVisitJob::class, function ($job): bool {
        $raw = $job->getVisitData();

        // handle() buffers filterPayload($visitData); replicate that to inspect
        // exactly what reaches the buffer/API.
        $filterPayload = new ReflectionMethod($job, 'filterPayload');
        $buffered = $filterPayload->invoke($job, $raw);

        return array_key_exists('ip', $raw)                 // collected internally
            && ! array_key_exists('ip', $buffered)          // but never buffered/sent
            && ! array_key_exists('user_agent', $buffered); // only user_agent_hash ships
    });
});

test('it filters AI bot user agents', function (): void {
    Bus::fake();

    $aiBots = [
        'GPTBot/1.0',
        'ClaudeBot/1.0',
        'ChatGPT-User/1.0',
        'Claude-Web/1.0',
    ];

    foreach ($aiBots as $bot) {
        $this->withHeaders(['User-Agent' => $bot])->get('/');
    }

    Bus::assertNotDispatched(HandlePageVisitJob::class);
});

test('it filters headless browser user agents', function (): void {
    Bus::fake();

    $headlessBrowsers = [
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/91.0.4472.124 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36 Puppeteer',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36 Playwright',
    ];

    foreach ($headlessBrowsers as $browser) {
        $this->withHeaders(['User-Agent' => $browser])->get('/');
    }

    Bus::assertNotDispatched(HandlePageVisitJob::class);
});

test('it does not track non-GET requests even with human browser headers', function (): void {
    Bus::fake();
    Cache::flush();

    // A real browser form POST: everything except the verb looks human.
    captureThroughMiddleware('/contact', 'POST');

    Bus::assertNotDispatched(HandlePageVisitJob::class);
});

test('it does not track requests carrying the X-Livewire header', function (): void {
    Bus::fake();
    Cache::flush();

    // Livewire 4 serves /livewire-{hash}/update, so no excluded_paths entry can
    // match it; the header is the only stable signal.
    $this->withHeaders(humanBrowserHeaders() + ['X-Livewire' => '1'])->get('/');

    Bus::assertNotDispatched(HandlePageVisitJob::class);
});

test('it does not track visits to the package dashboard', function (): void {
    Bus::fake();
    Cache::flush();

    // Gate-denied (non-local env) still reaches the middleware — capture runs
    // before $next() — so a 403 hit must not be counted either.
    $this->withHeaders(humanBrowserHeaders())->get('/ranetrace')->assertForbidden();

    Bus::assertNotDispatched(HandlePageVisitJob::class);
});

test('it does not track the dashboard when its path is customized', function (): void {
    $this->configOverrides = ['ranetrace.dashboard.path' => 'telemetry'];
    $this->reloadApplication();

    Bus::fake();
    Cache::flush();
    $this->app['env'] = 'local';

    $this->withHeaders(humanBrowserHeaders())->get('/telemetry')->assertOk();

    Bus::assertNotDispatched(HandlePageVisitJob::class);
});

test('it skips the configured dashboard prefix even when no ranetrace route matched', function (): void {
    Bus::fake();
    Cache::flush();

    // Point the dashboard at a path served by an ordinary app route: the
    // secondary path check must still skip it.
    config(['ranetrace.dashboard.path' => 'panel']);

    captureThroughMiddleware('/panel');
    captureThroughMiddleware('/panel/checks');

    Bus::assertNotDispatched(HandlePageVisitJob::class);
});

test('the dashboard path check only applies on the configured dashboard domain', function (): void {
    Bus::fake();
    Cache::flush();

    // With a dedicated dashboard domain, the same path on the app's own domain
    // is a legitimate page and must still be tracked.
    config([
        'ranetrace.dashboard.path' => 'panel',
        'ranetrace.dashboard.domain' => 'telemetry.example.com',
    ]);

    captureThroughMiddleware('/panel');

    Bus::assertDispatched(HandlePageVisitJob::class);
});

test('it does not track health check or framework machine paths', function (string $path): void {
    Bus::fake();
    Cache::flush();

    captureThroughMiddleware($path);

    Bus::assertNotDispatched(HandlePageVisitJob::class);
})->with([
    'health check' => '/up',
    'sanctum csrf cookie' => '/sanctum/csrf-cookie',
    'ignition' => '/_ignition/health-check',
]);

test('a normal human GET is still tracked (regression)', function (): void {
    Bus::fake();
    Cache::flush();

    captureThroughMiddleware('/some-marketing-page');

    Bus::assertDispatched(HandlePageVisitJob::class);
});

/**
 * Headers a real browser sends, shared by the tests that must isolate a single
 * skip rule rather than any of the bot heuristics.
 *
 * @return array<string, string>
 */
function humanBrowserHeaders(): array
{
    return [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
        'Accept-Language' => 'en-US,en;q=0.9',
    ];
}

/**
 * Run one request straight through the middleware. Used where the assertion is
 * about a path or verb the test application has no route for — an unmatched
 * route never reaches group middleware, which would make the test vacuous.
 */
function captureThroughMiddleware(string $uri, string $method = 'GET'): void
{
    $request = Illuminate\Http\Request::create($uri, $method);

    foreach (humanBrowserHeaders() as $name => $value) {
        $request->headers->set($name, $value);
    }

    (new TrackPageVisit)->handle(
        $request,
        fn (): Illuminate\Http\Response => response('OK')
    );
}

/**
 * A valid RequestFilter whose shouldSkip() always throws — used to prove the
 * middleware's failure isolation (a fault mid-capture must not 500 the request).
 */
class ThrowingRequestFilterFixture implements Ranetrace\Laravel\Analytics\Contracts\RequestFilter
{
    public function shouldSkip(Illuminate\Http\Request $request): bool
    {
        throw new RuntimeException('request_filter exploded mid-capture');
    }
}
