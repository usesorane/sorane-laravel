<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Sorane\Laravel\Jobs\HandlePageVisitJob;

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

    config(['sorane.website_analytics.excluded_paths' => ['admin', 'api']]);

    $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0',
    ])->get('/admin/dashboard');

    Bus::assertNotDispatched(HandlePageVisitJob::class);

    $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0',
    ])->get('/api/users');

    Bus::assertNotDispatched(HandlePageVisitJob::class);
});

test('it tracks allowed paths', function (): void {
    Bus::fake();
    Cache::flush();

    config(['sorane.website_analytics.excluded_paths' => ['admin']]);

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

test('it does not track when analytics is disabled', function (): void {
    config(['sorane.website_analytics.enabled' => false]);

    // Restart the application to re-register middleware
    $this->refreshApplication();

    Bus::fake();
    config(['sorane.website_analytics.enabled' => false]);

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

    $middleware = new Sorane\Laravel\Analytics\Middleware\TrackPageVisit;
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
