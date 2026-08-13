<?php

declare(strict_types=1);

namespace Ranetrace\Laravel;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use Ranetrace\Laravel\Analytics\Middleware\TrackPageVisit;
use Ranetrace\Laravel\Commands\RanetraceAnalyticsTestCommand;
use Ranetrace\Laravel\Commands\RanetraceErrorTestCommand;
use Ranetrace\Laravel\Commands\RanetraceEventTestCommand;
use Ranetrace\Laravel\Commands\RanetraceJavaScriptErrorTestCommand;
use Ranetrace\Laravel\Commands\RanetraceLogTestCommand;
use Ranetrace\Laravel\Commands\RanetracePauseClearCommand;
use Ranetrace\Laravel\Commands\RanetraceStatusCommand;
use Ranetrace\Laravel\Commands\RanetraceTestCommand;
use Ranetrace\Laravel\Commands\RanetraceWorkCommand;
use Ranetrace\Laravel\Events\EventTracker;
use Ranetrace\Laravel\Http\Controllers\AnalyticsBeaconController;
use Ranetrace\Laravel\Http\Controllers\AssetController;
use Ranetrace\Laravel\Http\Controllers\JavaScriptErrorController;
use Ranetrace\Laravel\Http\Middleware\Authorize;
use Ranetrace\Laravel\Logging\RanetraceLogDriver;
use Ranetrace\Laravel\Mcp\RanetraceServer;
use Ranetrace\Laravel\Services\RanetraceApiClient;

class RanetraceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merge package config
        $this->mergeConfigFrom(
            __DIR__.'/../config/ranetrace.php',
            'ranetrace'
        );

        $this->registerLogChannels();

        // Register Ranetrace as singleton
        $this->app->singleton(Ranetrace::class);

        // Register EventTracker as singleton
        $this->app->singleton(EventTracker::class);

        // Register custom log driver
        $this->app['log']->extend('ranetrace', function ($app, $config) {
            return (new RanetraceLogDriver)($config);
        });

        $this->registerMcpApiClient();
    }

    public function boot(): void
    {
        // Publish config
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/ranetrace.php' => config_path('ranetrace.php'),
            ], 'ranetrace-laravel-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/ranetrace'),
            ], 'ranetrace-laravel-views');

            // Register commands
            $this->commands([
                RanetraceTestCommand::class,
                RanetraceAnalyticsTestCommand::class,
                RanetraceErrorTestCommand::class,
                RanetraceEventTestCommand::class,
                RanetraceJavaScriptErrorTestCommand::class,
                RanetraceLogTestCommand::class,
                RanetraceWorkCommand::class,
                RanetraceStatusCommand::class,
                RanetracePauseClearCommand::class,
            ]);
        }

        // Load package views
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'ranetrace');

        // Add middleware to web group
        if (config('ranetrace.enabled', true) && config('ranetrace.website_analytics.enabled')) {
            $this->app['router']->pushMiddlewareToGroup('web', TrackPageVisit::class);
            //            $this->registerAnalyticsBeaconRoute();
        }

        // Register JavaScript error tracking route
        if (config('ranetrace.enabled', true) && config('ranetrace.javascript_errors.enabled')) {
            $this->registerJavaScriptErrorRoute();
        }

        // Register Blade directive for error tracking script
        $this->registerBladeDirectives();

        // Register MCP server
        $this->registerMcpServer();

        // Register the in-app diagnostics dashboard
        $this->registerDashboard();
    }

    /**
     * Give the MCP tools an API client that talks with the MCP token, while
     * everything else — the batch worker above all — keeps the ingest key.
     *
     * The two credentials are not interchangeable: the ingest key writes
     * telemetry in and lives on every production server, the MCP token reads
     * data back out and lives on a developer's machine. {@see RanetraceApiClient}
     * takes its credential through the constructor and has no binding of its
     * own, so one contextual binding per tool class is the whole seam — no tool
     * constructor knows which credential it was handed.
     *
     * The class list is {@see RanetraceServer::TOOLS}, the same list the server
     * registers, so a tool added there is bound here automatically instead of
     * silently resolving the default client and authenticating as the ingest
     * key.
     */
    protected function registerMcpApiClient(): void
    {
        if (! class_exists(Mcp::class)) {
            return;
        }

        foreach (RanetraceServer::TOOLS as $tool) {
            $this->app->when($tool)
                ->needs(RanetraceApiClient::class)
                // Cast rather than pass through: a null token would hit the
                // client's own fallback to `ranetrace.key`, quietly reviving
                // the conflation this binding exists to end.
                ->give(fn (): RanetraceApiClient => new RanetraceApiClient((string) config('ranetrace.mcp.token')));
        }
    }

    /**
     * Register the diagnostics dashboard route group + its authorization gate.
     *
     * Gated on `ranetrace.dashboard.enabled` independently of the master
     * `ranetrace.enabled` capture switch, so an admin can still open the
     * dashboard to see *why* capture is disabled or misconfigured. Disabling it
     * removes the routes entirely (zero attack surface).
     */
    protected function registerDashboard(): void
    {
        if (! config('ranetrace.dashboard.enabled', true)) {
            return;
        }

        $this->registerDashboardGate();

        // Data routes — behind the gate.
        $this->app['router']->group($this->dashboardRouteConfiguration(), function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/dashboard.php');
        });

        // Asset routes — same path/domain but NOT gated (no secrets; keeps the
        // page CSP-clean). Long-cached, content-hash busted by the shell.
        $this->app['router']->group([
            'domain' => config('ranetrace.dashboard.domain'),
            'prefix' => config('ranetrace.dashboard.path', 'ranetrace'),
        ], function ($router): void {
            $router->get('ranetrace.css', [AssetController::class, 'css'])->name('ranetrace.assets.css');
            $router->get('ranetrace.js', [AssetController::class, 'js'])->name('ranetrace.assets.js');
        });
    }

    /**
     * Define the default `viewRanetrace` gate (local-only) unless the host has
     * already defined one.
     *
     * Mirrors Pulse: registered after the Gate resolves so a host override in
     * AppServiceProvider::boot() always wins regardless of provider boot order.
     * The closure takes a nullable user and explicitly denies in every
     * non-local environment — it must never fall through to allow.
     */
    protected function registerDashboardGate(): void
    {
        $this->callAfterResolving(Gate::class, function (Gate $gate): void {
            if (! $gate->has('viewRanetrace')) {
                $gate->define(
                    'viewRanetrace',
                    fn (?Authenticatable $user = null): bool => $this->app->environment('local')
                );
            }
        });
    }

    /**
     * Build the dashboard route group configuration from config, always
     * appending the package's own Authorize middleware (never trusting the
     * host's `web` group to include it).
     *
     * @return array<string, mixed>
     */
    protected function dashboardRouteConfiguration(): array
    {
        return [
            'domain' => config('ranetrace.dashboard.domain'),
            'prefix' => config('ranetrace.dashboard.path', 'ranetrace'),
            'middleware' => array_merge(
                (array) config('ranetrace.dashboard.middleware', ['web']),
                [Authorize::class]
            ),
        ];
    }

    /**
     * Auto-register the package's log channels so the host application gets
     * zero-config diagnostics and centralized logging.
     *
     * The user-facing `ranetrace` channel is never overwritten: if the host
     * defines its own `ranetrace` channel, that definition always wins. The
     * internal `ranetrace_internal` channel is package-owned and is always
     * (re)set from `ranetrace.internal_logging.*`, so it cannot be overridden
     * via `config/logging.php` — tune it through those config keys instead.
     */
    protected function registerLogChannels(): void
    {
        $this->app['config']->set('logging.channels.ranetrace_internal', [
            'driver' => 'daily',
            'path' => storage_path('logs/ranetrace-internal.log'),
            'level' => config('ranetrace.internal_logging.level', 'debug'),
            'days' => config('ranetrace.internal_logging.days', 14),
        ]);

        // Define the user-facing `ranetrace` channel unconditionally. The
        // `ranetrace` log driver is always extended, and the handler
        // short-circuits when logging is disabled, so a defined-but-inactive
        // channel is inert. Registering it regardless of the enabled flag keeps
        // a committed `config/logging.php` stack that references `ranetrace`
        // valid in every environment, including local or dev where logging is
        // turned off, instead of throwing "Log [ranetrace] is not defined".
        if (! $this->app['config']->has('logging.channels.ranetrace')) {
            $this->app['config']->set('logging.channels.ranetrace', [
                'driver' => 'ranetrace',
                'level' => config('ranetrace.logging.level', 'notice'),
            ]);
        }
    }

    protected function registerJavaScriptErrorRoute(): void
    {
        $throttle = config('ranetrace.javascript_errors.throttle', '60,1');

        $this->app['router']
            ->post('ranetrace/javascript-errors/store', [JavaScriptErrorController::class, 'store'])
            ->middleware(['web', "throttle:{$throttle}"])
            ->name('ranetrace.javascript-errors.store');
    }

    /**
     * Register the human-verification beacon route. Mounted whenever website
     * analytics is on (same condition as the capture middleware); the controller
     * itself enforces `website_analytics.beacon.enabled` so a stray call while
     * the beacon is off is a clean 403 rather than a 404.
     */
    protected function registerAnalyticsBeaconRoute(): void
    {
        //        $throttle = config('ranetrace.website_analytics.beacon.throttle', '120,1');
        //
        //        $this->app['router']
        //            ->post('ranetrace/analytics/verify', [AnalyticsBeaconController::class, 'verify'])
        //            ->middleware(['web', "throttle:{$throttle}"])
        //            ->name('ranetrace.analytics.verify');
    }

    protected function registerBladeDirectives(): void
    {
        Blade::directive('ranetraceErrorTracking', function () {
            return "<?php echo view('ranetrace::error-tracker')->render(); ?>";
        });

        //        Blade::directive('ranetraceAnalytics', function () {
        /*            return "<?php echo view('ranetrace::analytics-beacon')->render(); ?>"; */
        //        });
    }

    protected function registerMcpServer(): void
    {
        if (! class_exists(Mcp::class)) {
            return;
        }

        if (! config('ranetrace.mcp.enabled', true)) {
            return;
        }

        // The MCP token, not the ingest key: this server exists to read data
        // back out, and the token is what the API accepts for that. Gating on
        // it also keeps the server off production hosts, which hold an ingest
        // key and no token.
        if (empty(config('ranetrace.mcp.token'))) {
            return;
        }

        Mcp::local('ranetrace', RanetraceServer::class);
    }
}
