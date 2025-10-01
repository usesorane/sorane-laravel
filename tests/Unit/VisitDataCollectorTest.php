<?php

use Sorane\Laravel\Analytics\VisitDataCollector;

test('it collects basic visit data', function (): void {
    $request = \Illuminate\Http\Request::create('https://example.com/test-page?utm_source=google', 'GET');
    $request->headers->set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/91.0.4472.124');
    $request->headers->set('Referer', 'https://google.com');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $data = VisitDataCollector::collect($request);

    expect($data)->toHaveKeys([
        'url',
        'path',
        'user_agent',
        'user_agent_hash',
        'referrer',
        'device_type',
        'browser_name',
        'session_id_hash',
        'timestamp',
    ]);

    expect($data['url'])->toBe('https://example.com/test-page?utm_source=google');
    expect($data['path'])->toBe('/test-page');
    expect($data['referrer'])->toBe('https://google.com');
    expect($data['utm_source'])->toBe('google');
});

test('it detects mobile devices correctly', function (): void {
    $userAgents = [
        'Mozilla/5.0 (iPhone; CPU iPhone OS 14_6 like Mac OS X) AppleWebKit/605.1.15',
        'Mozilla/5.0 (Linux; Android 11; SM-G991B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/90.0.4430.210 Mobile Safari/537.36',
        'Mozilla/5.0 (Linux; Android 10; Mobile) AppleWebKit/537.36',
    ];

    foreach ($userAgents as $ua) {
        $request = \Illuminate\Http\Request::create('/', 'GET');
        $request->headers->set('User-Agent', $ua);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        $data = VisitDataCollector::collect($request);

        expect($data['device_type'])->toBe('mobile');
    }
});

test('it detects tablets correctly', function (): void {
    $userAgents = [
        'Mozilla/5.0 (iPad; CPU OS 14_6 like Mac OS X) AppleWebKit/605.1.15',
        'Mozilla/5.0 (Linux; Android 11; SM-T870) AppleWebKit/537.36', // Android tablet
    ];

    foreach ($userAgents as $ua) {
        $request = \Illuminate\Http\Request::create('/', 'GET');
        $request->headers->set('User-Agent', $ua);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        $data = VisitDataCollector::collect($request);

        expect($data['device_type'])->toBe('tablet');
    }
});

test('it detects desktop devices correctly', function (): void {
    $userAgents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/91.0.4472.124',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
    ];

    foreach ($userAgents as $ua) {
        $request = \Illuminate\Http\Request::create('/', 'GET');
        $request->headers->set('User-Agent', $ua);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        $data = VisitDataCollector::collect($request);

        expect($data['device_type'])->toBe('desktop');
    }
});

test('it detects browsers correctly', function (): void {
    $tests = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36' => 'Chrome',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:89.0) Gecko/20100101 Firefox/89.0' => 'Firefox',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.1.1 Safari/605.1.15' => 'Safari',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36 Edg/91.0.864.59' => 'Edge',
    ];

    foreach ($tests as $ua => $expectedBrowser) {
        $request = \Illuminate\Http\Request::create('/', 'GET');
        $request->headers->set('User-Agent', $ua);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        $data = VisitDataCollector::collect($request);

        expect($data['browser_name'])->toBe($expectedBrowser);
    }
});

test('it collects utm parameters', function (): void {
    $request = \Illuminate\Http\Request::create('/', 'GET', [
        'utm_source' => 'google',
        'utm_medium' => 'cpc',
        'utm_campaign' => 'summer_sale',
        'utm_content' => 'banner',
        'utm_term' => 'laravel',
    ]);
    $request->headers->set('User-Agent', 'Test Browser');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $data = VisitDataCollector::collect($request);

    expect($data['utm_source'])->toBe('google');
    expect($data['utm_medium'])->toBe('cpc');
    expect($data['utm_campaign'])->toBe('summer_sale');
    expect($data['utm_content'])->toBe('banner');
    expect($data['utm_term'])->toBe('laravel');
});

test('it includes timestamp in ISO format', function (): void {
    $request = \Illuminate\Http\Request::create('/', 'GET');
    $request->headers->set('User-Agent', 'Test Browser');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $data = VisitDataCollector::collect($request);

    expect($data['timestamp'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
});

test('it hashes user agent', function (): void {
    $request = \Illuminate\Http\Request::create('/', 'GET');
    $request->headers->set('User-Agent', 'Test Browser');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $data = VisitDataCollector::collect($request);

    expect($data['user_agent_hash'])->not->toBeNull();
    expect($data['user_agent_hash'])->toHaveLength(64); // SHA256
    expect($data['user_agent_hash'])->not->toBe('Test Browser'); // Should be hashed
});
