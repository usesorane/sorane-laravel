<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Pest\Browser\Execution;
use Ranetrace\Laravel\Jobs\HandleJavaScriptErrorJob;

/**
 * Registers a throwaway page that renders the shipped error-tracker snippet
 * and exposes a button that throws a ReferenceError once clicked.
 */
function probePage(): void
{
    Route::get('/js-error-probe', fn (): string => Blade::render(<<<'BLADE'
        <!DOCTYPE html>
        <html>
        <head><title>Probe</title>@ranetraceErrorTracking</head>
        <body>
            <h1>Probe page</h1>
            <button onclick="undefinedFunctionCall()">Boom</button>
        </body>
        </html>
    BLADE))->middleware('web');
}

it('renders the error tracker snippet in a real browser', function (): void {
    probePage();

    visit('/js-error-probe')
        ->assertSee('Probe page');
});

it('captures a real javascript error and dispatches the capture job', function (): void {
    Queue::fake();
    probePage();

    visit('/js-error-probe')->click('Boom');

    // The amphp HTTP server runs in-process, so the event loop must be ticked
    // for the snippet's fetch() to be handled. waitForExpectation() does that;
    // a plain sleep() would deadlock.
    Execution::instance()->waitForExpectation(function (): void {
        Queue::assertPushed(HandleJavaScriptErrorJob::class);
    });
});
