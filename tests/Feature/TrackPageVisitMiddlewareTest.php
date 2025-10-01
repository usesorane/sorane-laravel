<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Sorane\Laravel\Jobs\SendPageVisitToSoraneJob;

test('it tracks page visits for normal requests', function (): void {
    Bus::fake();
    Cache::flush(); // Clear cache to ensure throttle doesn't interfere

    $response = $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/91.0',
    ])->get('/');

    $response->assertStatus(200);

    Bus::assertDispatched(SendPageVisitToSoraneJob::class);
});

test('it does not track crawler visits', function (): void {
    Bus::fake();

    $response = $this->withHeaders([
        'User-Agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)',
    ])->get('/');

    Bus::assertNotDispatched(SendPageVisitToSoraneJob::class);
});

test('it does not track requests without user agent', function (): void {
    Bus::fake();

    $response = $this->get('/');

    Bus::assertNotDispatched(SendPageVisitToSoraneJob::class);
});

test('it respects excluded paths configuration', function (): void {
    Bus::fake();

    config(['sorane.website_analytics.excluded_paths' => ['admin', 'api']]);

    $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0',
    ])->get('/admin/dashboard');

    Bus::assertNotDispatched(SendPageVisitToSoraneJob::class);

    $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0',
    ])->get('/api/users');

    Bus::assertNotDispatched(SendPageVisitToSoraneJob::class);
});

test('it tracks allowed paths', function (): void {
    Bus::fake();
    Cache::flush();

    config(['sorane.website_analytics.excluded_paths' => ['admin']]);

    $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/91.0',
    ])->get('/products');

    Bus::assertDispatched(SendPageVisitToSoraneJob::class);
});

test('it filters suspicious user agents', function (): void {
    Bus::fake();

    $suspiciousAgents = [
        'curl/7.68.0',
        'python-requests',
        'Postman Runtime',
        'test',
        'ab', // Too short
    ];

    foreach ($suspiciousAgents as $agent) {
        $this->withHeaders(['User-Agent' => $agent])->get('/');
    }

    Bus::assertNotDispatched(SendPageVisitToSoraneJob::class);
});

test('it includes human probability score', function (): void {
    Bus::fake();
    Cache::flush();

    $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/91.0',
        'Accept' => 'text/html',
        'Accept-Language' => 'en-US',
    ])->get('/');

    Bus::assertDispatched(SendPageVisitToSoraneJob::class, function ($job): bool {
        return isset($job->getVisitData()['human_probability_score'])
            && isset($job->getVisitData()['human_probability_reasons']);
    });
});

test('it throttles duplicate visits', function (): void {
    Bus::fake();
    Cache::flush();

    $headers = ['User-Agent' => 'Mozilla/5.0'];

    // First request
    $this->withHeaders($headers)->get('/test-page');
    Bus::assertDispatchedTimes(SendPageVisitToSoraneJob::class, 1);

    // Second request within throttle window
    $this->withHeaders($headers)->get('/test-page');
    Bus::assertDispatchedTimes(SendPageVisitToSoraneJob::class, 1); // Still just 1
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

    Bus::assertNotDispatched(SendPageVisitToSoraneJob::class);
});

test('it skips internal requests', function (): void {
    Bus::fake();

    $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0',
        'X-Client-Mode' => 'passive',
    ])->get('/');

    Bus::assertNotDispatched(SendPageVisitToSoraneJob::class);
});
