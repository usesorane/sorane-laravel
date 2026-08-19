<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Ranetrace\Laravel\Support\Core;
use Ranetrace\Laravel\Utilities\RouteSecretResolver;

/**
 * The seams between this package and `ranetrace/ranetrace-php`.
 *
 * Redaction and fingerprint derivation are the shared core's, and the core's
 * own suite is where their behaviour is asserted. What is asserted here is only
 * what Laravel contributes: that `config/ranetrace.php` reaches them, that the
 * Carbon clock does, and that the router answers the one question a
 * framework-agnostic library cannot.
 */
test('scrubbing.extra_keys from config/ranetrace.php reaches the shared scrubber', function (): void {
    config(['ranetrace.scrubbing.extra_keys' => ['x_signature', 'SeedValue']]);

    $result = Core::scrubber()->scrub([
        'x_signature' => 'deadbeef',
        'app_seedvalue' => 'matched case-insensitively',
        'password' => 'a built-in is never dropped',
        'keep' => 'ok',
    ]);

    expect($result['x_signature'])->toBe('[REDACTED]')
        ->and($result['app_seedvalue'])->toBe('[REDACTED]')
        ->and($result['password'])->toBe('[REDACTED]')
        ->and($result['keep'])->toBe('ok');
});

test('a config change between captures is picked up, because the scrubber is built per capture', function (): void {
    config(['ranetrace.scrubbing.extra_keys' => []]);
    expect(Core::scrubber()->scrub(['seed' => 'x'])['seed'])->toBe('x');

    config(['ranetrace.scrubbing.extra_keys' => ['seed']]);
    expect(Core::scrubber()->scrub(['seed' => 'x'])['seed'])->toBe('[REDACTED]');
});

test('the fingerprint salt falls back to the ranetrace api key, not the application key', function (): void {
    // It used to fall back to `app.key`. Both SDKs now salt with the API key,
    // so the same visitor hashes the same way whichever SDK observed them.
    config([
        'ranetrace.fingerprint_salt' => null,
        'ranetrace.key' => 'rt-key-for-salting',
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
    ]);

    expect(Core::fingerprints()->hash('raw-session-id'))
        ->toBe(hash_hmac('sha256', 'raw-session-id', 'rt-key-for-salting'))
        ->and(Core::fingerprints()->hash('raw-session-id'))
        ->not->toBe(hash_hmac('sha256', 'raw-session-id', (string) config('app.key')));
});

test('a configured fingerprint_salt still wins over the api key', function (): void {
    config(['ranetrace.key' => 'rt-key-for-salting', 'ranetrace.fingerprint_salt' => 'pepper']);

    expect(Core::fingerprints()->hash('raw-session-id'))
        ->toBe(hash_hmac('sha256', 'raw-session-id', 'pepper'));
});

test('the session id hash rotates on Carbon time, so a frozen clock freezes it', function (): void {
    $generator = Core::fingerprints();
    $today = $generator->generateSessionIdHash('127.0.0.1', 'Test Browser', now()->format('Y-m-d'));

    $this->travel(1)->days();

    expect($generator->generateSessionIdHash('127.0.0.1', 'Test Browser', now()->format('Y-m-d')))
        ->not->toBe($today);
});

test('the resolver answers per url, so one payload can carry secrets from several routes', function (): void {
    Route::get('/reset-password/{token}', fn (): string => 'reset');
    Route::get('/invitations/{invitation:token}', fn (): string => 'invite');
    Route::get('/articles/{slug}', fn (): string => 'article');

    $resolver = RouteSecretResolver::resolver();

    expect($resolver('http://localhost/reset-password/AAA'))->toBe(['AAA'])
        ->and($resolver('http://localhost/invitations/BBB'))->toBe(['BBB'])
        ->and($resolver('http://localhost/articles/hello'))->toBe([]);
});

test('the resolver unions the values it was told about into every answer', function (): void {
    // The current request may be a POST, which forUrl() cannot match: it only
    // scans GET routes. Its own values therefore travel alongside the per-URL
    // lookup rather than instead of it.
    Route::get('/reset-password/{token}', fn (): string => 'reset');

    $resolver = RouteSecretResolver::resolver(['from-the-current-request']);

    expect($resolver('http://localhost/reset-password/AAA'))
        ->toBe(['from-the-current-request', 'AAA'])
        ->and($resolver('http://localhost/anything-else'))
        ->toBe(['from-the-current-request']);
});

test('the resolver answers empty for an application with no secret-bearing route', function (): void {
    Route::get('/articles/{slug}', fn (): string => 'article');

    expect((RouteSecretResolver::resolver())('http://localhost/articles/hello'))->toBe([]);
});

test('the shared scrubber redacts a path segment the resolver named', function (): void {
    Route::get('/reset-password/{token}', fn (): string => 'reset');

    $result = Core::scrubber()->scrubDeep(
        ['seen' => 'http://localhost/reset-password/live-token-abc?utm_source=email'],
        RouteSecretResolver::resolver(),
    );

    expect($result['seen'])->toBe('http://localhost/reset-password/[REDACTED]?utm_source=email');
});
