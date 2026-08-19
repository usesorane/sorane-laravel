<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Vite;

beforeEach(function (): void {
    config([
        'ranetrace.javascript_errors.enabled' => true,
    ]);
});

/**
 * The runtime config object the rendered snippet carries.
 *
 * @return array<string, mixed>
 */
function renderedTrackerConfig(string $html): array
{
    preg_match('/const config = (\{.*\});/', $html, $matches);

    return json_decode($matches[1] ?? '', true, 512, JSON_THROW_ON_ERROR);
}

test('error tracker view renders to valid output', function (): void {
    // Regression guard: `view(...)` alone does not compile the Blade template;
    // only render() does. A directive/PHP syntax error in the view (e.g. the
    // un-compiled `<script@if` nonce conditional) surfaces here as a
    // ViewException and would 500 every host page using the snippet.
    $html = view('ranetrace::error-tracker')->render();

    expect($html)
        ->toContain('Ranetrace JavaScript Error Tracking')
        ->toContain("window.addEventListener('error'")
        ->toContain("window.addEventListener('unhandledrejection'")
        ->not->toContain('@if')
        ->not->toContain('@endif');
});

/**
 * The script itself lives once, in `ranetrace/ranetrace-php`. This view is a
 * wrapper: if its logic ever comes back inline, the two copies drift again and
 * the relays start validating different payloads. The source file, not the
 * rendered output, is what this asserts on.
 */
test('the view carries no copy of the capture script', function (string $marker): void {
    $source = (string) file_get_contents(__DIR__.'/../../resources/views/error-tracker.blade.php');

    expect($source)->not->toContain($marker);
})->with([
    'addEventListener(',
    'KEEPALIVE_SIZE_LIMIT',
    'function sendError(',
    'window.Ranetrace',
]);

test('the view pulls the script from the shared php sdk', function (): void {
    $source = (string) file_get_contents(__DIR__.'/../../resources/views/error-tracker.blade.php');

    expect($source)->toContain('\Ranetrace\Php\JavaScript\CaptureScript::withConfig(');
});

test('the config block carries the laravel route and configured values', function (): void {
    config([
        'ranetrace.javascript_errors.sample_rate' => 0.25,
        'ranetrace.javascript_errors.capture_console_errors' => true,
        'ranetrace.javascript_errors.max_breadcrumbs' => 5,
        'ranetrace.javascript_errors.ignored_errors' => ['Only this one'],
    ]);

    expect(renderedTrackerConfig(view('ranetrace::error-tracker')->render()))
        ->toMatchArray([
            'endpoint' => route('ranetrace.javascript-errors.store'),
            'enabled' => true,
            'sampleRate' => 0.25,
            'captureConsoleErrors' => true,
            'maxBreadcrumbs' => 5,
            'ignoredErrors' => ['Only this one'],
        ]);
});

test('the config block defaults to the controller ignore list, so script and relay filter alike', function (): void {
    $config = renderedTrackerConfig(view('ranetrace::error-tracker')->render());

    expect($config['ignoredErrors'])
        ->toBe(Ranetrace\Laravel\Http\Controllers\JavaScriptErrorController::DEFAULT_IGNORED_ERRORS)
        ->and($config['sampleRate'])->toBe(1.0)
        ->and($config['captureConsoleErrors'])->toBeFalse()
        ->and($config['maxBreadcrumbs'])->toBe(20);
});

/**
 * This relay sits behind the `web` group's CSRF middleware, so the snippet has to
 * hand the script a token: without it every captured error is rejected with a 419.
 */
test('the snippet carries the csrf token and the script sends it as a header', function (): void {
    session()->put('_token', 'test-csrf-token');

    $html = view('ranetrace::error-tracker')->render();

    expect(renderedTrackerConfig($html)['csrfToken'])->toBe('test-csrf-token')
        ->and($html)->toContain("requestHeaders['X-CSRF-TOKEN'] = config.csrfToken;");
});

test('ranetraceErrorTracking directive renders the snippet into a host page', function (): void {
    $html = Blade::render('<html><head>@ranetraceErrorTracking</head><body></body></html>');

    expect($html)
        ->toContain('Ranetrace JavaScript Error Tracking')
        ->toContain('<script');
});

test('error tracker view renders nothing when feature is disabled', function (): void {
    config(['ranetrace.javascript_errors.enabled' => false]);

    expect(mb_trim(view('ranetrace::error-tracker')->render()))->toBe('');
});

test('error tracker script tag includes nonce when a CSP nonce is set', function (): void {
    Vite::useCspNonce('test-nonce-value');

    $html = view('ranetrace::error-tracker')->render();

    expect($html)->toContain('nonce="test-nonce-value"');
});
