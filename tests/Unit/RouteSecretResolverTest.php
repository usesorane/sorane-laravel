<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Ranetrace\Laravel\Utilities\RouteSecretResolver;

beforeEach(function (): void {
    Route::get('/reset-password/{token}', fn (): string => 'reset');
    Route::get('/verify/{id}/{hash}', fn (): string => 'verified');
    Route::get('/invitations/{invitation:token}', fn (): string => 'invite');
    Route::get('/articles/{slug}', fn (): string => 'article');
});

test('forUrl finds the sensitive segment of a url the current request did not resolve', function (): void {
    expect(RouteSecretResolver::forUrl('http://localhost/reset-password/live-token-abc'))
        ->toBe(['live-token-abc'])
        ->and(RouteSecretResolver::forUrl('http://localhost/verify/42/deadbeefhash'))
        ->toBe(['deadbeefhash']);
});

test('forUrl honours a custom-key binding, where only the binding field names the secret', function (): void {
    expect(RouteSecretResolver::forUrl('http://localhost/invitations/live-invite-abc'))
        ->toBe(['live-invite-abc']);
});

test('forUrl returns nothing for routes without a sensitive parameter', function (): void {
    expect(RouteSecretResolver::forUrl('http://localhost/articles/my-post'))->toBe([]);
});

test('forUrl ignores urls that are not this application', function (): void {
    // A third-party referrer's path is not described by our routes, so guessing
    // at it would be meaningless — even when it happens to look like one. A
    // host-less URL carrying a scheme is not one of our pages either.
    expect(RouteSecretResolver::forUrl('https://example.com/reset-password/not-ours'))->toBe([])
        ->and(RouteSecretResolver::forUrl('mailto:someone@example.com'))->toBe([])
        ->and(RouteSecretResolver::forUrl(null))->toBe([])
        ->and(RouteSecretResolver::forUrl(''))->toBe([]);
});

test('forUrl resolves a relative reference as this application', function (): void {
    // A relative URL was resolved by the browser against the page it was on,
    // which is one of ours, so it describes our routes by definition. The
    // fetch/XHR breadcrumb hooks record whatever argument the app passed, which
    // is frequently relative.
    expect(RouteSecretResolver::forUrl('/reset-password/live-token-abc'))
        ->toBe(['live-token-abc'])
        ->and(RouteSecretResolver::forUrl('reset-password/live-token-abc'))
        ->toBe(['live-token-abc'])
        ->and(RouteSecretResolver::forUrl('/articles/my-post'))
        ->toBe([]);
});

test('forUrl accepts pre-resolved candidate routes', function (): void {
    $candidates = RouteSecretResolver::sensitiveParameterRoutes();

    expect($candidates)->not->toBeEmpty()
        ->and(RouteSecretResolver::forUrl('/reset-password/live-token-abc', $candidates))
        ->toBe(['live-token-abc']);
});

test('forUrl never throws on a malformed url', function (): void {
    expect(RouteSecretResolver::forUrl('http://localhost/reset-password/a b c'))->toBeArray()
        ->and(RouteSecretResolver::forUrl('not a url at all'))->toBe([])
        ->and(RouteSecretResolver::forUrl('http://'))->toBe([]);
});

test('resolving a foreign url leaves the current request route binding intact', function (): void {
    // Regression guard for the reason forUrl() binds a CLONE: routes are shared
    // singletons, so binding the route object the current request is using would
    // hand the host application the foreign URL's parameters instead of its own.
    Route::get('/password-reset/{token}', function (string $token): string {
        RouteSecretResolver::forUrl('http://localhost/password-reset/someone-elses-token');

        return $token.'|'.request()->route()->parameter('token');
    })->middleware('web');

    $this->get('/password-reset/my-own-token')
        ->assertOk()
        ->assertSee('my-own-token|my-own-token');
});

test('forRequest reads the route already bound to the request', function (): void {
    Route::get('/session/{token}', function (string $token): string {
        return implode(',', RouteSecretResolver::forRequest(request()));
    })->middleware('web');

    $this->get('/session/bound-token-123')->assertOk()->assertSee('bound-token-123');
});

test('forRequest returns nothing when no route is resolved', function (): void {
    expect(RouteSecretResolver::forRequest(Illuminate\Http\Request::create('/anything', 'GET')))
        ->toBe([]);
});
