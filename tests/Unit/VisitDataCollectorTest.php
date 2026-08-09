<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Ranetrace\Laravel\Analytics\VisitDataCollector;

/**
 * A request with a resolved route bound to it, mirroring what the collector
 * sees from `web` group middleware (the route is matched before it runs).
 */
function requestWithRoute(string $url, string $uri): Request
{
    $request = Request::create($url, 'GET');
    $request->headers->set('User-Agent', 'Mozilla/5.0');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $route = (new Route(['GET'], $uri, static fn (): string => 'ok'))->bind($request);
    $request->setRouteResolver(static fn (): Route => $route);

    return $request;
}

test('it collects basic visit data', function (): void {
    $request = Request::create('https://example.com/test-page?utm_source=google', 'GET');
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
        $request = Request::create('/', 'GET');
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
        $request = Request::create('/', 'GET');
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
        $request = Request::create('/', 'GET');
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
        $request = Request::create('/', 'GET');
        $request->headers->set('User-Agent', $ua);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        $data = VisitDataCollector::collect($request);

        expect($data['browser_name'])->toBe($expectedBrowser);
    }
});

test('it collects utm parameters', function (): void {
    $request = Request::create('/', 'GET', [
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

test('it reports the path decoded so one page is one entry', function (): void {
    // The router rawurldecodes before matching, so these are all the same page;
    // reporting the raw request line would fragment it in analytics.
    $request = Request::create('https://example.com/%61dmin/us%65rs', 'GET');
    $request->headers->set('User-Agent', 'Mozilla/5.0');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $data = VisitDataCollector::collect($request);

    expect($data['path'])->toBe('/admin/users');
});

test('it still redacts a sensitive segment that itself contains a percent sign', function (): void {
    // Scrubbing runs before decoding for exactly this case: decoding first
    // would turn the segment into `tok%41` and stop it matching the route's
    // parameter value.
    $request = requestWithRoute('https://example.com/invitations/tok%2541', 'invitations/{token}');

    $data = VisitDataCollector::collect($request);

    expect($data['path'])->toBe('/invitations/[REDACTED]');
});

test('it drops a non-string campaign parameter', function (): void {
    // `?utm_source[]=x` yields an array, which violates the API's string|null
    // schema and gets the whole batch rejected.
    $request = Request::create('/', 'GET', ['utm_source' => ['x']]);
    $request->headers->set('User-Agent', 'Test Browser');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $data = VisitDataCollector::collect($request);

    expect($data['utm_source'])->toBeNull();
});

test('it caps an oversized campaign parameter', function (): void {
    $request = Request::create('/', 'GET', ['utm_campaign' => str_repeat('a', 500)]);
    $request->headers->set('User-Agent', 'Test Browser');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $data = VisitDataCollector::collect($request);

    expect($data['utm_campaign'])->toHaveLength(255);
});

test('it includes timestamp in ISO format', function (): void {
    $request = Request::create('/', 'GET');
    $request->headers->set('User-Agent', 'Test Browser');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $data = VisitDataCollector::collect($request);

    expect($data['timestamp'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
});

test('it scrubs sensitive query params from url and referrer', function (): void {
    $request = Request::create('https://example.com/reset?token=abc&utm_source=google', 'GET');
    $request->headers->set('User-Agent', 'Mozilla/5.0');
    $request->headers->set('Referer', 'https://example.com/login?api_key=zzz');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $data = VisitDataCollector::collect($request);

    expect($data['url'])->toContain('token=[REDACTED]')
        ->and($data['url'])->toContain('utm_source=google')
        ->and($data['url'])->not->toContain('token=abc')
        ->and($data['referrer'])->toBe('https://example.com/login?api_key=[REDACTED]')
        ->and($data['utm_source'])->toBe('google');
});

test('it scrubs sensitive route parameter values from the path and url', function (): void {
    $request = requestWithRoute(
        'https://example.com/invitations/secret-token-123?page=2',
        'invitations/{token}'
    );

    $data = VisitDataCollector::collect($request);

    expect($data['path'])->toBe('/invitations/[REDACTED]')
        ->and($data['url'])->toBe('https://example.com/invitations/[REDACTED]?page=2');
});

test('it scrubs a verification hash segment but keeps non-sensitive segments', function (): void {
    $request = requestWithRoute(
        'https://example.com/verify/42/deadbeefhash',
        'verify/{id}/{hash}'
    );

    $data = VisitDataCollector::collect($request);

    expect($data['path'])->toBe('/verify/42/[REDACTED]')
        ->and($data['url'])->toBe('https://example.com/verify/42/[REDACTED]');
});

test('it leaves non-sensitive route parameters untouched', function (): void {
    $request = requestWithRoute('https://example.com/articles/my-post', 'articles/{slug}');

    $data = VisitDataCollector::collect($request);

    expect($data['path'])->toBe('/articles/my-post')
        ->and($data['url'])->toBe('https://example.com/articles/my-post');
});

test('it scrubs both the path and the query when a token appears in each', function (): void {
    $request = requestWithRoute(
        'https://example.com/invitations/tok-abc?token=tok-abc&utm_source=mail',
        'invitations/{token}'
    );

    $data = VisitDataCollector::collect($request);

    expect($data['path'])->toBe('/invitations/[REDACTED]')
        ->and($data['url'])->toBe('https://example.com/invitations/[REDACTED]?token=[REDACTED]&utm_source=mail');
});

test('it behaves exactly as before when no route is resolved', function (): void {
    $request = Request::create('https://example.com/invitations/secret-token-123', 'GET');
    $request->headers->set('User-Agent', 'Mozilla/5.0');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $data = VisitDataCollector::collect($request);

    expect($data['path'])->toBe('/invitations/secret-token-123')
        ->and($data['url'])->toBe('https://example.com/invitations/secret-token-123');
});

test('it handles a parameterless route on the root path', function (): void {
    $request = requestWithRoute('https://example.com/', '/');

    $data = VisitDataCollector::collect($request);

    expect($data['path'])->toBe('/')
        ->and($data['url'])->toBe('https://example.com');
});

test('it hashes user agent', function (): void {
    $request = Request::create('/', 'GET');
    $request->headers->set('User-Agent', 'Test Browser');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $data = VisitDataCollector::collect($request);

    expect($data['user_agent_hash'])->not->toBeNull();
    expect($data['user_agent_hash'])->toHaveLength(64); // SHA256
    expect($data['user_agent_hash'])->not->toBe('Test Browser'); // Should be hashed
});
