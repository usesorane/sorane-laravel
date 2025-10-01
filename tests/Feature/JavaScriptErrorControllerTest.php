<?php

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    Bus::fake();
    $this->withoutMiddleware(VerifyCsrfToken::class);
    config([
        'sorane.javascript_errors.enabled' => true,
        'sorane.javascript_errors.queue' => true,
        'sorane.javascript_errors.sample_rate' => 1.0,
    ]);
});

test('javascript error endpoint is registered', function (): void {
    $response = $this->post(route('sorane.javascript-errors.store'));

    // Should not be 404
    expect($response->status())->not->toBe(404);
});

test('it rejects requests when feature is disabled', function (): void {
    config(['sorane.javascript_errors.enabled' => false]);

    $response = $this->postJson(route('sorane.javascript-errors.store'), [
        'message' => 'Test error',
    ]);

    $response->assertStatus(403);
    $response->assertJson([
        'success' => false,
        'message' => 'JavaScript error tracking is not enabled',
    ]);
});

test('it validates required fields', function (): void {
    $response = $this->postJson(route('sorane.javascript-errors.store'), []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['message']);
});

test('it accepts valid error data', function (): void {
    $response = $this->postJson(route('sorane.javascript-errors.store'), [
        'message' => 'Test error message',
        'stack' => 'Error: Test\n  at test.js:10',
        'type' => 'Error',
        'filename' => 'test.js',
        'line' => 10,
        'column' => 5,
        'url' => 'https://example.com/test',
        'timestamp' => now()->toISOString(),
    ]);

    $response->assertStatus(200);
    $response->assertJson(['success' => true]);
});

test('it ignores errors matching ignored patterns', function (): void {
    config(['sorane.javascript_errors.ignored_errors' => ['ResizeObserver']]);

    $response = $this->postJson(route('sorane.javascript-errors.store'), [
        'message' => 'ResizeObserver loop limit exceeded',
    ]);

    $response->assertStatus(200);
    $response->assertJson([
        'success' => true,
        'message' => 'Error ignored based on pattern',
    ]);
});

test('it sanitizes breadcrumbs', function (): void {
    $response = $this->postJson(route('sorane.javascript-errors.store'), [
        'message' => 'Test error',
        'breadcrumbs' => [
            [
                'timestamp' => now()->toISOString(),
                'category' => 'user',
                'message' => 'Button clicked',
                'data' => ['button_id' => 'test'],
            ],
        ],
    ]);

    $response->assertStatus(200);
});

test('it limits breadcrumb count', function (): void {
    config(['sorane.javascript_errors.max_breadcrumbs' => 5]);

    $breadcrumbs = array_map(
        fn ($i) => [
            'timestamp' => now()->toISOString(),
            'category' => 'test',
            'message' => "Breadcrumb {$i}",
            'data' => [],
        ],
        range(1, 20)
    );

    $response = $this->postJson(route('sorane.javascript-errors.store'), [
        'message' => 'Test error',
        'breadcrumbs' => $breadcrumbs,
    ]);

    $response->assertStatus(200);
});

test('it includes browser info', function (): void {
    $response = $this->postJson(route('sorane.javascript-errors.store'), [
        'message' => 'Test error',
        'browser_info' => [
            'screen_width' => 1920,
            'screen_height' => 1080,
            'viewport_width' => 1200,
            'viewport_height' => 800,
        ],
    ]);

    $response->assertStatus(200);
});

test('it validates breadcrumb structure', function (): void {
    $response = $this->postJson(route('sorane.javascript-errors.store'), [
        'message' => 'Test error',
        'breadcrumbs' => [
            [
                // Missing required fields
                'data' => [],
            ],
        ],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['breadcrumbs.0.timestamp', 'breadcrumbs.0.category', 'breadcrumbs.0.message']);
});

test('it limits message length', function (): void {
    $response = $this->postJson(route('sorane.javascript-errors.store'), [
        'message' => str_repeat('a', 3000), // Exceeds max
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['message']);
});

test('it limits stack trace length', function (): void {
    $response = $this->postJson(route('sorane.javascript-errors.store'), [
        'message' => 'Test',
        'stack' => str_repeat('a', 15000), // Exceeds max
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['stack']);
});
