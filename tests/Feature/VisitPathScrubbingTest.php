<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Ranetrace\Laravel\Analytics\Middleware\TrackPageVisit;
use Ranetrace\Laravel\Jobs\HandlePageVisitJob;

/**
 * Headers that make a request look like a real browser, so the middleware's
 * bot filtering lets the visit through to HandlePageVisitJob.
 *
 * @var array<string, string>
 */
$browserHeaders = [
    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
    'Accept-Language' => 'en-US,en;q=0.9',
];

/**
 * The visit data of the single job dispatched by the request under test.
 *
 * @var Closure(): array<string, mixed>
 */
$visitData = function (): array {
    $job = Bus::dispatched(HandlePageVisitJob::class)->first();

    expect($job)->not->toBeNull();

    return $job->getVisitData();
};

beforeEach(function (): void {
    Bus::fake();
    Cache::flush();

    Route::middleware(['web', TrackPageVisit::class])->group(function (): void {
        Route::get('/invitations/{token}', fn () => response('Invitation'));
        Route::get('/verify/{id}/{hash}', fn () => response('Verified'));
        Route::get('/articles/{slug}', fn () => response('Article'));
        Route::get('/reset-password/{token}', fn () => response('Reset'));
        Route::get('/teams/{invitation:token}', fn () => response('Team invite'));
    });
});

test('a token in the path never reaches the dispatched visit job', function () use ($browserHeaders, $visitData): void {
    $this->withHeaders($browserHeaders)
        ->get('/invitations/live-invite-token-abc123')
        ->assertSuccessful();

    $data = $visitData();

    expect($data['path'])->toBe('/invitations/[REDACTED]')
        ->and($data['url'])->toBe('http://localhost/invitations/[REDACTED]')
        ->and($data['url'])->not->toContain('live-invite-token-abc123');
});

test('a verification hash in the path is redacted while other segments survive', function () use ($browserHeaders, $visitData): void {
    $this->withHeaders($browserHeaders)
        ->get('/verify/42/8f14e45fceea167a5a36dedd4bea2543')
        ->assertSuccessful();

    $data = $visitData();

    expect($data['path'])->toBe('/verify/42/[REDACTED]')
        ->and($data['url'])->toBe('http://localhost/verify/42/[REDACTED]');
});

test('the stock password-reset page is tracked with its token redacted', function () use ($browserHeaders, $visitData): void {
    // `reset-password` is deliberately NOT in the default excluded_paths: the
    // page-view signal is kept, and the route-parameter scrub keeps the live
    // reset token out of the visit data.
    $this->withHeaders($browserHeaders)
        ->get('/reset-password/live-reset-token-xyz789')
        ->assertSuccessful();

    $data = $visitData();

    expect($data['path'])->toBe('/reset-password/[REDACTED]')
        ->and($data['url'])->toBe('http://localhost/reset-password/[REDACTED]')
        ->and($data['url'])->not->toContain('live-reset-token-xyz789');
});

test('non-sensitive route parameters are left untouched', function () use ($browserHeaders, $visitData): void {
    $this->withHeaders($browserHeaders)
        ->get('/articles/my-first-post?utm_source=newsletter')
        ->assertSuccessful();

    $data = $visitData();

    expect($data['path'])->toBe('/articles/my-first-post')
        ->and($data['url'])->toBe('http://localhost/articles/my-first-post?utm_source=newsletter')
        ->and($data['utm_source'])->toBe('newsletter');
});

test('a custom-key binding is redacted via its binding field', function () use ($browserHeaders, $visitData): void {
    // `{invitation:token}` names the parameter `invitation`; only the binding
    // field says the segment is a token.
    $this->withHeaders($browserHeaders)
        ->get('/teams/live-team-invite-abc')
        ->assertSuccessful();

    $data = $visitData();

    expect($data['path'])->toBe('/teams/[REDACTED]')
        ->and($data['url'])->not->toContain('live-team-invite-abc');
});

test('a token in the REFERRER path never reaches the dispatched visit job', function () use ($browserHeaders, $visitData): void {
    // The scenario the current-request scrub cannot see: the visitor was on the
    // reset page (redacted there) and clicked a link. Same-origin navigations
    // send the full previous URL by default, so the live token arrives on the
    // NEXT page view instead.
    $this->withHeaders($browserHeaders + [
        'Referer' => 'http://localhost/reset-password/live-reset-token-xyz789',
    ])->get('/articles/my-first-post')->assertSuccessful();

    $data = $visitData();

    expect($data['referrer'])->toBe('http://localhost/reset-password/[REDACTED]')
        ->and($data['referrer'])->not->toContain('live-reset-token-xyz789')
        ->and($data['path'])->toBe('/articles/my-first-post');
});

test('a non-sensitive referrer path is preserved in full', function () use ($browserHeaders, $visitData): void {
    $this->withHeaders($browserHeaders + [
        'Referer' => 'http://localhost/articles/previous-post?utm_source=mail',
    ])->get('/articles/my-first-post')->assertSuccessful();

    expect($visitData()['referrer'])->toBe('http://localhost/articles/previous-post?utm_source=mail');
});

test('an external referrer keeps its path and still has its query scrubbed', function () use ($browserHeaders, $visitData): void {
    // A third-party path is not described by our routes, so it is left alone —
    // but the query-string scrub still applies, as it always did.
    $this->withHeaders($browserHeaders + [
        'Referer' => 'https://search.example.com/results/laravel?token=theirs&q=ranetrace',
    ])->get('/articles/my-first-post')->assertSuccessful();

    expect($visitData()['referrer'])
        ->toBe('https://search.example.com/results/laravel?token=[REDACTED]&q=ranetrace');
});

test('path and query secrets are both redacted in the same url', function () use ($browserHeaders, $visitData): void {
    $this->withHeaders($browserHeaders)
        ->get('/invitations/dual-secret?token=dual-secret&page=2')
        ->assertSuccessful();

    $data = $visitData();

    expect($data['path'])->toBe('/invitations/[REDACTED]')
        // The test client normalises the query string's parameter order.
        ->and($data['url'])->toBe('http://localhost/invitations/[REDACTED]?page=2&token=[REDACTED]')
        ->and($data['url'])->not->toContain('dual-secret');
});
