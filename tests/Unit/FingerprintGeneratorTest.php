<?php

use Sorane\Laravel\Analytics\FingerprintGenerator;

test('it generates consistent session id hash for same inputs', function (): void {
    $request = \Illuminate\Http\Request::create('/', 'GET');
    $request->headers->set('User-Agent', 'Test Browser');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $hash1 = FingerprintGenerator::generateSessionIdHash($request);
    $hash2 = FingerprintGenerator::generateSessionIdHash($request);

    expect($hash1)->toBe($hash2);
});

test('session id hash changes daily', function (): void {
    $request = \Illuminate\Http\Request::create('/', 'GET');
    $request->headers->set('User-Agent', 'Test Browser');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $hash1 = FingerprintGenerator::generateSessionIdHash($request);

    // Travel to tomorrow
    $this->travel(1)->days();

    $hash2 = FingerprintGenerator::generateSessionIdHash($request);

    expect($hash1)->not->toBe($hash2);
});

test('it generates different session id for different IPs', function (): void {
    $request1 = \Illuminate\Http\Request::create('/', 'GET');
    $request1->headers->set('User-Agent', 'Test Browser');
    $request1->server->set('REMOTE_ADDR', '127.0.0.1');

    $request2 = \Illuminate\Http\Request::create('/', 'GET');
    $request2->headers->set('User-Agent', 'Test Browser');
    $request2->server->set('REMOTE_ADDR', '192.168.1.1');

    $hash1 = FingerprintGenerator::generateSessionIdHash($request1);
    $hash2 = FingerprintGenerator::generateSessionIdHash($request2);

    expect($hash1)->not->toBe($hash2);
});

test('it generates different session id for different user agents', function (): void {
    $request1 = \Illuminate\Http\Request::create('/', 'GET');
    $request1->headers->set('User-Agent', 'Chrome Browser');
    $request1->server->set('REMOTE_ADDR', '127.0.0.1');

    $request2 = \Illuminate\Http\Request::create('/', 'GET');
    $request2->headers->set('User-Agent', 'Firefox Browser');
    $request2->server->set('REMOTE_ADDR', '127.0.0.1');

    $hash1 = FingerprintGenerator::generateSessionIdHash($request1);
    $hash2 = FingerprintGenerator::generateSessionIdHash($request2);

    expect($hash1)->not->toBe($hash2);
});

test('it generates user agent hash correctly', function (): void {
    $request = \Illuminate\Http\Request::create('/', 'GET');
    $request->headers->set('User-Agent', 'Test Browser');

    $hash = FingerprintGenerator::generateUserAgentHash($request);

    expect($hash)->not->toBeNull();
    expect($hash)->toHaveLength(64); // SHA256 hash length
});

test('it returns empty string for missing user agent', function (): void {
    $request = \Illuminate\Http\Request::create('/', 'GET');
    $request->headers->remove('User-Agent');

    $hash = FingerprintGenerator::generateUserAgentHash($request);

    expect($hash)->toBe('');
});

test('user agent hash is consistent for same user agent', function (): void {
    $request = \Illuminate\Http\Request::create('/', 'GET');
    $request->headers->set('User-Agent', 'Test Browser');

    $hash1 = FingerprintGenerator::generateUserAgentHash($request);
    $hash2 = FingerprintGenerator::generateUserAgentHash($request);

    expect($hash1)->toBe($hash2);
});

test('user agent hash differs for different user agents', function (): void {
    $request1 = \Illuminate\Http\Request::create('/', 'GET');
    $request1->headers->set('User-Agent', 'Chrome');

    $request2 = \Illuminate\Http\Request::create('/', 'GET');
    $request2->headers->set('User-Agent', 'Firefox');

    $hash1 = FingerprintGenerator::generateUserAgentHash($request1);
    $hash2 = FingerprintGenerator::generateUserAgentHash($request2);

    expect($hash1)->not->toBe($hash2);
});
