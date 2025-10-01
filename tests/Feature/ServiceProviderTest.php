<?php

declare(strict_types=1);

use Sorane\Laravel\SoraneServiceProvider;

test('service provider is registered', function (): void {
    $providers = $this->app->getLoadedProviders();

    expect($providers)->toHaveKey(SoraneServiceProvider::class);
});

test('config is merged', function (): void {
    expect(config('sorane'))->toBeArray();
    expect(config('sorane.key'))->not->toBeNull();
});

test('event tracker is registered as singleton', function (): void {
    $instance1 = app(Sorane\Laravel\Events\EventTracker::class);
    $instance2 = app(Sorane\Laravel\Events\EventTracker::class);

    expect($instance1)->toBe($instance2);
});

test('sorane log driver is registered', function (): void {
    $channel = Log::channel('sorane');

    expect($channel)->toBeInstanceOf(Psr\Log\LoggerInterface::class);
});

test('blade directive is registered', function (): void {
    $directives = Illuminate\Support\Facades\Blade::getCustomDirectives();

    expect($directives)->toHaveKey('soraneErrorTracking');
});

test('javascript error route is registered when enabled', function (): void {
    $routes = collect(app('router')->getRoutes())->filter(function ($route): bool {
        return $route->getName() === 'sorane.javascript-errors.store';
    });

    expect($routes)->not->toBeEmpty();
});

test('middleware is registered when analytics enabled', function (): void {
    $middleware = app('router')->getMiddlewareGroups()['web'] ?? [];

    expect($middleware)->toContain(Sorane\Laravel\Analytics\Middleware\TrackPageVisit::class);
});

test('commands are registered', function (): void {
    $commands = Illuminate\Support\Facades\Artisan::all();

    expect($commands)->toHaveKeys([
        'sorane:test',
        'sorane:test-events',
        'sorane:test-logging',
        'sorane:test-javascript-errors',
    ]);
});

test('facades are accessible', function (): void {
    expect(class_exists(Sorane\Laravel\Facades\Sorane::class))->toBeTrue();
    expect(class_exists(Sorane\Laravel\Facades\SoraneEvents::class))->toBeTrue();
});

test('package views are loadable', function (): void {
    $view = view('sorane::error-tracker');

    expect($view)->not->toBeNull();
});
