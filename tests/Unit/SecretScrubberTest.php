<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Ranetrace\Laravel\Utilities\SecretScrubber;

/**
 * A value engineered to exhaust PCRE's backtrack limit inside
 * {@see SecretScrubber::scrubString()}: the sensitive fragment sits at the very
 * start, so the greedy `[\w.\-]*` prefix has to back off once per character of
 * the long run behind it before the alternation can match.
 */
function backtrackingScrubStringValue(): string
{
    return 'token'.str_repeat('a', 50_000).'=x';
}

/**
 * Run $callback with PCRE's backtrack limit pinned low, restoring it afterwards
 * whatever happens — a leaked limit would make every later test's regex give up.
 */
function withTinyBacktrackLimit(Closure $callback): mixed
{
    $original = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '100');

    try {
        return $callback();
    } finally {
        ini_set('pcre.backtrack_limit', $original === false ? '1000000' : $original);
    }
}

test('it redacts values under sensitive keys', function (): void {
    $result = SecretScrubber::scrub([
        'password' => 'hunter2',
        'api_key' => 'sk_live_123',
        'token' => 'abc',
        'authorization' => 'Bearer xyz',
        'username' => 'alice',
    ]);

    expect($result['password'])->toBe('[REDACTED]')
        ->and($result['api_key'])->toBe('[REDACTED]')
        ->and($result['token'])->toBe('[REDACTED]')
        ->and($result['authorization'])->toBe('[REDACTED]')
        ->and($result['username'])->toBe('alice');
});

test('it matches sensitive keys case-insensitively and as substrings', function (): void {
    $result = SecretScrubber::scrub([
        'API_KEY' => 'x',
        'Stripe_Secret' => 'y',
        'csrf_token' => 'z',
        'safe' => 'keep',
    ]);

    expect($result['API_KEY'])->toBe('[REDACTED]')
        ->and($result['Stripe_Secret'])->toBe('[REDACTED]')
        ->and($result['csrf_token'])->toBe('[REDACTED]')
        ->and($result['safe'])->toBe('keep');
});

test('it redacts nested sensitive keys and the whole sensitive subtree', function (): void {
    $result = SecretScrubber::scrub([
        'user' => [
            'name' => 'bob',
            'credentials' => ['password' => 'p', 'pin' => '1234'],
        ],
        'authorization' => ['scheme' => 'Bearer', 'value' => 'tok'],
    ]);

    expect($result['user']['name'])->toBe('bob')
        ->and($result['user']['credentials'])->toBe('[REDACTED]')
        ->and($result['authorization'])->toBe('[REDACTED]');
});

test('it does not over-match unrelated keys', function (): void {
    $result = SecretScrubber::scrub([
        'author' => 'jane',
        'description' => 'a token of appreciation',
        'count' => 3,
    ]);

    // 'author' must not match 'authorization'; values (not keys) are never inspected.
    expect($result['author'])->toBe('jane')
        ->and($result['description'])->toBe('a token of appreciation')
        ->and($result['count'])->toBe(3);
});

test('it returns non-array input untouched', function (): void {
    expect(SecretScrubber::scrub('plain'))->toBe('plain')
        ->and(SecretScrubber::scrub(123))->toBe(123)
        ->and(SecretScrubber::scrub(null))->toBeNull();
});

test('it honors user-configured extra keys', function (): void {
    config(['ranetrace.scrubbing.extra_keys' => ['x_signature']]);

    $result = SecretScrubber::scrub([
        'x_signature' => 'deadbeef',
        'keep' => 'ok',
    ]);

    expect($result['x_signature'])->toBe('[REDACTED]')
        ->and($result['keep'])->toBe('ok');
});

test('it preserves list/numeric-keyed arrays while scrubbing nested secrets', function (): void {
    $result = SecretScrubber::scrub([
        'headers' => [
            ['name' => 'Accept', 'value' => 'application/json'],
            ['name' => 'X-Api-Key', 'api_key' => 'secret-value'],
        ],
    ]);

    expect($result['headers'][0]['value'])->toBe('application/json')
        ->and($result['headers'][1]['api_key'])->toBe('[REDACTED]')
        ->and($result['headers'][1]['name'])->toBe('X-Api-Key');
});

test('scrubUrl redacts sensitive query params and preserves the rest', function (): void {
    expect(SecretScrubber::scrubUrl('https://example.com/reset?token=abc123&utm_source=google&page=2'))
        ->toBe('https://example.com/reset?token=[REDACTED]&utm_source=google&page=2');
});

test('scrubUrl redacts laravel signed-url signatures', function (): void {
    expect(SecretScrubber::scrubUrl('https://example.com/invite?expires=1700000000&signature=deadbeef'))
        ->toBe('https://example.com/invite?expires=1700000000&signature=[REDACTED]');
});

test('scrubUrl preserves the fragment', function (): void {
    expect(SecretScrubber::scrubUrl('https://example.com/p?api_key=secret#section'))
        ->toBe('https://example.com/p?api_key=[REDACTED]#section');
});

test('scrubUrl redacts a query-shaped fragment', function (): void {
    // The OAuth implicit flow puts the whole grant in the fragment, so a URL
    // with no query at all can still carry the token.
    expect(SecretScrubber::scrubUrl('https://app.test/callback#access_token=abc&expires_in=3600'))
        ->toBe('https://app.test/callback#access_token=[REDACTED]&expires_in=3600');
});

test('scrubUrl redacts a query-shaped fragment behind a real query', function (): void {
    expect(SecretScrubber::scrubUrl('https://app.test/callback?code=1&api_key=k#access_token=abc'))
        ->toBe('https://app.test/callback?code=1&api_key=[REDACTED]#access_token=[REDACTED]');
});

test('scrubUrl returns a fragment that is not query-shaped byte-for-byte', function (string $url): void {
    expect(SecretScrubber::scrubUrl($url))->toBe($url);
})->with([
    'anchor behind a query' => ['https://app.test/docs?page=2#section-2'],
    'anchor on a relative path' => ['/path#anchor'],
    'spa hash route' => ['https://app.test/app#/reset/abc123'],
    'empty fragment' => ['https://app.test/docs#'],
]);

test('scrubUrl leaves urls without sensitive params untouched', function (): void {
    expect(SecretScrubber::scrubUrl('https://example.com/list?page=2&sort=name'))
        ->toBe('https://example.com/list?page=2&sort=name')
        ->and(SecretScrubber::scrubUrl('https://example.com/plain'))
        ->toBe('https://example.com/plain')
        ->and(SecretScrubber::scrubUrl(null))->toBeNull();
});

test('sensitiveRouteParameterValues returns the values of sensitively-named parameters', function (): void {
    expect(SecretScrubber::sensitiveRouteParameterValues([
        'token' => 'abc123',
        'reset_token' => 'def456',
        'hash' => 'deadbeef',
        'id' => '42',
        'slug' => 'my-post',
    ]))->toBe(['abc123', 'def456', 'deadbeef']);
});

test('sensitiveRouteParameterValues skips empty, non-scalar and duplicate values', function (): void {
    expect(SecretScrubber::sensitiveRouteParameterValues([
        'token' => '',
        'api_key' => null,
        'secret' => new stdClass,
        'password' => 'same',
        'password_confirmation_token' => 'same',
    ]))->toBe(['same'])
        ->and(SecretScrubber::sensitiveRouteParameterValues([]))->toBe([]);
});

test('sensitiveRouteParameterValues honours the binding field of a custom-key binding', function (): void {
    // `Route::get('/invitations/{invitation:token}')` names the parameter
    // `invitation` and records `token` as its binding field — the field is the
    // only place that says the segment holds a secret.
    expect(SecretScrubber::sensitiveRouteParameterValues(
        ['invitation' => 'live-invite-abc', 'post' => 'my-post'],
        ['invitation' => 'token', 'post' => 'slug']
    ))->toBe(['live-invite-abc']);
});

test('isSensitiveRouteParameter matches on the name, the binding field, or neither', function (): void {
    expect(SecretScrubber::isSensitiveRouteParameter('token'))->toBeTrue()
        ->and(SecretScrubber::isSensitiveRouteParameter('hash'))->toBeTrue()
        ->and(SecretScrubber::isSensitiveRouteParameter('invitation', 'token'))->toBeTrue()
        ->and(SecretScrubber::isSensitiveRouteParameter('invitation'))->toBeFalse()
        ->and(SecretScrubber::isSensitiveRouteParameter('post', 'slug'))->toBeFalse()
        ->and(SecretScrubber::isSensitiveRouteParameter('post', ''))->toBeFalse();
});

test('the hash fragment applies to route parameters only, never to array keys', function (): void {
    $result = SecretScrubber::scrub([
        'user_agent_hash' => 'aaa',
        'session_id_hash' => 'bbb',
    ]);

    expect($result['user_agent_hash'])->toBe('aaa')
        ->and($result['session_id_hash'])->toBe('bbb');
});

test('scrubPathSegments redacts every segment equal to a sensitive value', function (): void {
    expect(SecretScrubber::scrubPathSegments('/reset/abc123/confirm/abc123', ['abc123']))
        ->toBe('/reset/[REDACTED]/confirm/[REDACTED]');
});

test('scrubPathSegments matches segments on their decoded form', function (): void {
    expect(SecretScrubber::scrubPathSegments('/invite/a%20b', ['a b']))
        ->toBe('/invite/[REDACTED]');
});

test('scrubPathSegments requires a whole-segment match', function (): void {
    expect(SecretScrubber::scrubPathSegments('/reset/abc123-suffix', ['abc123']))
        ->toBe('/reset/abc123-suffix');
});

test('scrubPathSegments leaves the path untouched without sensitive values', function (): void {
    expect(SecretScrubber::scrubPathSegments('/reset/abc123', []))->toBe('/reset/abc123')
        ->and(SecretScrubber::scrubPathSegments('/', ['abc123']))->toBe('/')
        ->and(SecretScrubber::scrubPathSegments('', ['abc123']))->toBe('');
});

test('scrubUrlPath redacts the path while preserving scheme, host, port, query and fragment', function (): void {
    expect(SecretScrubber::scrubUrlPath('https://example.com:8080/reset/abc123?page=2#top', ['abc123']))
        ->toBe('https://example.com:8080/reset/[REDACTED]?page=2#top');
});

test('scrubUrlPath composes with scrubUrl without re-encoding the query', function (): void {
    $url = 'https://example.com/reset/abc123?token=abc123&next=%2Fdashboard%3Fa%3D1';

    expect(SecretScrubber::scrubUrlPath(SecretScrubber::scrubUrl($url), ['abc123']))
        ->toBe('https://example.com/reset/[REDACTED]?token=[REDACTED]&next=%2Fdashboard%3Fa%3D1');
});

test('scrubUrlPath handles relative urls and urls without a path', function (): void {
    expect(SecretScrubber::scrubUrlPath('/reset/abc123?page=2', ['abc123']))
        ->toBe('/reset/[REDACTED]?page=2')
        ->and(SecretScrubber::scrubUrlPath('https://example.com', ['abc123']))
        ->toBe('https://example.com');
});

test('scrubUrlPath redacts a sensitive value inside an SPA hash route', function (): void {
    // A client-side router keeps the whole route in the fragment, so the reset
    // token lives there rather than in the path the server saw.
    expect(SecretScrubber::scrubUrlPath('/app#/reset/abc123', ['abc123']))
        ->toBe('/app#/reset/[REDACTED]')
        ->and(SecretScrubber::scrubUrlPath('https://app.test/app?page=2#/reset/abc123', ['abc123']))
        ->toBe('https://app.test/app?page=2#/reset/[REDACTED]');
});

test('scrubUrlPath leaves a fragment without a sensitive segment byte-for-byte', function (): void {
    expect(SecretScrubber::scrubUrlPath('/docs#section-2', ['abc123']))->toBe('/docs#section-2');
});

test('scrubUrlPath finds the path when a :// appears in the query or fragment', function (string $url, string $expected): void {
    // pathOffset() used to look for `://` anywhere in the string, so a relative
    // URL carrying an unencoded absolute URL in its query landed the "path"
    // inside the query and shipped the live token untouched.
    expect(SecretScrubber::scrubUrlPath($url, ['TOKEN']))->toBe($expected);
})->with([
    'relative, no query' => ['/reset-password/TOKEN', '/reset-password/[REDACTED]'],
    'relative url in the query' => ['/reset-password/TOKEN?next=/account', '/reset-password/[REDACTED]?next=/account'],
    'absolute url in the query' => ['/reset-password/TOKEN?next=https://app.test/dashboard', '/reset-password/[REDACTED]?next=https://app.test/dashboard'],
    'absolute url in the fragment' => ['/reset-password/TOKEN#ret=https://app.test/x', '/reset-password/[REDACTED]#ret=https://app.test/x'],
    'absolute url' => ['https://app.test/reset-password/TOKEN?next=https://other/x', 'https://app.test/reset-password/[REDACTED]?next=https://other/x'],
    'protocol-relative url' => ['//app.test/reset-password/TOKEN', '//app.test/reset-password/[REDACTED]'],
]);

test('scrubUrlPath leaves the url untouched without sensitive values', function (): void {
    expect(SecretScrubber::scrubUrlPath('https://example.com/reset/abc123', []))
        ->toBe('https://example.com/reset/abc123')
        ->and(SecretScrubber::scrubUrlPath(null, ['abc123']))->toBeNull()
        ->and(SecretScrubber::scrubUrlPath('', ['abc123']))->toBe('');
});

test('scrubString redacts key=value secrets in free-form strings', function (): void {
    expect(SecretScrubber::scrubString('error with password=hunter2 in config'))
        ->toBe('error with password=[REDACTED] in config');
});

test('scrubString redacts json-style and arrow-style secrets', function (): void {
    expect(SecretScrubber::scrubString('"api_key":"sk_live_abc"'))->toBe('"api_key":"[REDACTED]"')
        ->and(SecretScrubber::scrubString("token => 'abc123'"))->toBe("token => '[REDACTED]'");
});

test('scrubString redacts query-string secrets while keeping the rest', function (): void {
    $scrubbed = SecretScrubber::scrubString('GET https://api.test/v1?api_key=secret&page=2');

    expect($scrubbed)->toContain('api_key=[REDACTED]')->and($scrubbed)->toContain('page=2');
});

test('scrubString fails closed when the regex engine gives up', function (): void {
    // preg_replace_callback() returns null when PCRE gives up, and returning the
    // input on that path hands an UNSCRUBBED string to a caller that believes it
    // was scrubbed. Losing the value beats leaking it.
    $value = backtrackingScrubStringValue();

    $scrubbed = withTinyBacktrackLimit(static fn (): string => SecretScrubber::scrubString($value));

    expect($scrubbed)->toBe('[REDACTED]')
        ->and($scrubbed)->not->toBe($value);
});

test('scrubString reports the regex give-up on the internal channel', function (): void {
    $logger = Mockery::spy(LoggerInterface::class);
    Log::shouldReceive('channel')->with('ranetrace_internal')->andReturn($logger);

    withTinyBacktrackLimit(static fn (): string => SecretScrubber::scrubString(backtrackingScrubStringValue()));

    $logger->shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => str_contains($context['error'] ?? '', 'Backtrack limit')
    );
});

test('scrubString leaves strings without sensitive keys untouched', function (): void {
    expect(SecretScrubber::scrubString('just a normal message, id=42'))->toBe('just a normal message, id=42')
        ->and(SecretScrubber::scrubString(''))->toBe('');
});

test('scrubDeep redacts a sensitive query param in a relative URL value', function (): void {
    // A signed download link recorded by the fetch/XHR breadcrumb hooks is
    // usually relative; only the absolute form used to be redacted.
    expect(SecretScrubber::scrubDeep(['url' => '/exports/42/download?expires=1735689600&signature=a1b2c3']))
        ->toBe(['url' => '/exports/42/download?expires=1735689600&signature=[REDACTED]']);
});

test('scrubDeep redacts relative URL shapes that carry no leading slash', function (string $value, string $expected): void {
    expect(SecretScrubber::scrubDeep(['u' => $value]))->toBe(['u' => $expected]);
})->with([
    'bare path' => ['api/user?token=SECRET', 'api/user?token=[REDACTED]'],
    'current directory' => ['./api/user?token=SECRET', './api/user?token=[REDACTED]'],
    'parent directory' => ['../api/user?token=SECRET', '../api/user?token=[REDACTED]'],
    'query only' => ['?token=SECRET', '?token=[REDACTED]'],
    'sub-delimiter in a sibling param' => ['/download?ids=1,2&signature=SECRET', '/download?ids=1,2&signature=[REDACTED]'],
    'unencoded @ in the path' => ['/users/@rutger/files?token=SECRET', '/users/@rutger/files?token=[REDACTED]'],
]);

test('scrubDeep leaves free-form values that merely contain a question mark untouched', function (string $value): void {
    // scrubUrl rewrites everything from the first `?` to the end, so admitting
    // a non-URL here would silently truncate it.
    expect(SecretScrubber::scrubDeep(['v' => $value]))->toBe(['v' => $value]);
})->with([
    'json payload' => ['{"callback":"/webhooks/return?token=abc","order_id":991,"amount":42.5}'],
    'ternary' => ['isset($token)?$token:null'],
    'regex' => ['/^(a|b)?token$/'],
    'prose' => ['Did the token=abc request fail?'],
    'markdown link' => ['[reset](/reset?token=abc)'],
    'windows path' => ['C:\Users\token'],
    'bare word' => ['token'],
]);
