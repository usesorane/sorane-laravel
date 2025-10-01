<?php

declare(strict_types=1);

use Sorane\Laravel\Analytics\HumanProbabilityScorer;

test('it scores typical browser request as human', function (): void {
    $request = Illuminate\Http\Request::create('/', 'GET');
    $request->headers->set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    $request->headers->set('Accept', 'text/html,application/xhtml+xml');
    $request->headers->set('Accept-Language', 'en-US,en;q=0.9');
    $request->headers->set('Accept-Encoding', 'gzip, deflate, br');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $result = HumanProbabilityScorer::score($request);

    expect($result)->toHaveKeys(['score', 'classification', 'reasons']);
    expect($result['score'])->toBeGreaterThanOrEqual(50);
    expect($result['classification'])->toBeIn(['likely_human', 'possibly_human']);
});

test('it scores suspicious user agents as bot', function (): void {
    $suspiciousAgents = [
        'curl/7.68.0',
        'python-requests/2.25.1',
        'Postman Runtime/7.26.8',
        'bot/1.0',
        'crawler',
    ];

    foreach ($suspiciousAgents as $agent) {
        $request = Illuminate\Http\Request::create('/', 'GET');
        $request->headers->set('User-Agent', $agent);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        $result = HumanProbabilityScorer::score($request);

        expect($result['score'])->toBeLessThan(50);
    }
});

test('it penalizes missing user agent', function (): void {
    $request = Illuminate\Http\Request::create('/', 'GET');
    $request->headers->remove('User-Agent');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $result = HumanProbabilityScorer::score($request);

    expect($result['score'])->toBeLessThan(50);
    expect($result['reasons'])->toContain('Missing user agent');
});

test('it rewards valid referrer', function (): void {
    $request = Illuminate\Http\Request::create('/', 'GET');
    $request->headers->set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/91.0');
    $request->headers->set('Referer', 'https://google.com');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $result = HumanProbabilityScorer::score($request);

    expect($result['reasons'])->toContain('Request includes a referrer');
});

test('it rewards common browser headers', function (): void {
    $request = Illuminate\Http\Request::create('/', 'GET');
    $request->headers->set('User-Agent', 'Mozilla/5.0');
    $request->headers->set('Accept', 'text/html');
    $request->headers->set('Accept-Language', 'en-US');
    $request->headers->set('Accept-Encoding', 'gzip');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $result = HumanProbabilityScorer::score($request);

    expect($result['reasons'])->toContain('Request contains typical browser headers');
});

test('it rewards cookies', function (): void {
    $request = Illuminate\Http\Request::create('/', 'GET');
    $request->headers->set('User-Agent', 'Mozilla/5.0');
    $request->headers->set('Cookie', 'session=abc123');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $result = HumanProbabilityScorer::score($request);

    expect($result['reasons'])->toContain('Request includes cookies');
});

test('score is always between 0 and 100', function (): void {
    $userAgents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/91.0',
        'curl/7.68.0',
        'bot crawler spider',
        '',
        str_repeat('a', 1000),
    ];

    foreach ($userAgents as $ua) {
        $request = Illuminate\Http\Request::create('/', 'GET');
        if ($ua) {
            $request->headers->set('User-Agent', $ua);
        }
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        $result = HumanProbabilityScorer::score($request);

        expect($result['score'])->toBeGreaterThanOrEqual(0);
        expect($result['score'])->toBeLessThanOrEqual(100);
    }
});

test('it classifies scores correctly', function (): void {
    // This test indirectly verifies classification by checking score ranges
    $request = Illuminate\Http\Request::create('/', 'GET');
    $request->headers->set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/91.0');
    $request->headers->set('Accept', 'text/html');
    $request->headers->set('Accept-Language', 'en');
    $request->headers->set('Accept-Encoding', 'gzip');
    $request->headers->set('Cookie', 'test=1');
    $request->headers->set('Referer', 'https://google.com');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $result = HumanProbabilityScorer::score($request);

    // With all positive signals, should be likely_human
    expect($result['classification'])->toBe('likely_human');
});

test('it penalizes very short user agents', function (): void {
    $request = Illuminate\Http\Request::create('/', 'GET');
    $request->headers->set('User-Agent', 'abc'); // Very short
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $result = HumanProbabilityScorer::score($request);

    expect($result['reasons'])->toContain('User agent suspiciously short');
});

test('it penalizes very long user agents', function (): void {
    $request = Illuminate\Http\Request::create('/', 'GET');
    $request->headers->set('User-Agent', str_repeat('a', 600));
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $result = HumanProbabilityScorer::score($request);

    expect($result['reasons'])->toContain('User agent suspiciously long');
});
