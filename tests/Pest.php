<?php

use Sorane\Laravel\SoraneServiceProvider;
use Sorane\Laravel\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Test Case Setup
|--------------------------------------------------------------------------
|
| Configure the test case to properly load the Sorane service provider
| and set up the testing environment.
|
*/

// Define the getPackageProviders method for all tests
uses()->beforeEach(function (): void {
    // No additional setup needed for now
})->in('Feature', 'Unit');

// Helper to get package providers
function getPackageProviders($app): array
{
    return [
        SoraneServiceProvider::class,
    ];
}

// Helper to define environment setup
function getEnvironmentSetUp($app): void
{
    // Configure cache to use array driver for testing
    $app['config']->set('cache.default', 'array');
    $app['config']->set('queue.default', 'sync');

    // Set test API key
    $app['config']->set('sorane.key', 'test-api-key-12345');

    // Enable features for testing
    $app['config']->set('sorane.events.enabled', true);
    $app['config']->set('sorane.events.queue', true);
    $app['config']->set('sorane.events.queue_name', 'default');

    $app['config']->set('sorane.logging.enabled', true);
    $app['config']->set('sorane.logging.queue', true);
    $app['config']->set('sorane.logging.queue_name', 'default');

    $app['config']->set('sorane.javascript_errors.enabled', true);
    $app['config']->set('sorane.javascript_errors.queue', true);
    $app['config']->set('sorane.javascript_errors.sample_rate', 1.0);
    $app['config']->set('sorane.javascript_errors.queue_name', 'default');

    $app['config']->set('sorane.website_analytics.enabled', true);
    $app['config']->set('sorane.website_analytics.queue', 'default');
}
