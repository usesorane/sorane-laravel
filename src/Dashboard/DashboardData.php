<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Dashboard;

use Carbon\Carbon;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Ranetrace\Laravel\Analytics\Middleware\TrackPageVisit;
use Ranetrace\Laravel\Dashboard\Checks\ApiKeyCheck;
use Ranetrace\Laravel\Dashboard\Checks\BufferCapacityCheck;
use Ranetrace\Laravel\Dashboard\Checks\CacheDriverCheck;
use Ranetrace\Laravel\Dashboard\Checks\Check;
use Ranetrace\Laravel\Dashboard\Checks\CheckResult;
use Ranetrace\Laravel\Dashboard\Checks\DrainStalledCheck;
use Ranetrace\Laravel\Dashboard\Checks\InternalLoggingCheck;
use Ranetrace\Laravel\Dashboard\Checks\QueueWorkerCheck;
use Ranetrace\Laravel\Jobs\SendBatchToRanetraceJob;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Services\RanetracePauseManager;
use Throwable;

/**
 * Shared source of truth for Ranetrace installation health and diagnostics.
 *
 * Both the `ranetrace:status` command and the in-app dashboard consume this
 * service, so the CLI and the web page can never disagree. Every cache/database
 * read degrades gracefully — this is a diagnostic surface and must never throw,
 * even when the cache store or database is unavailable.
 */
class DashboardData
{
    /**
     * A buffer is considered "drain-stalled" when it holds items but has not
     * had a successful batch send within this many seconds (and is not paused).
     */
    public const int DRAIN_STALE_SECONDS = 600;

    /**
     * Fraction of a buffer's max size at which it is considered "near capacity"
     * — drives both the overall health flag and the recommendations output.
     */
    public const float NEAR_CAPACITY_RATIO = 0.8;

    /**
     * The seed misconfiguration checks. Pluggable: a host can override the list
     * via `config('ranetrace.dashboard.checks')` to add or replace checks
     * without touching the panel. Each entry must implement the Check contract.
     *
     * @var array<int, class-string<Check>>
     */
    public const array DEFAULT_CHECKS = [
        ApiKeyCheck::class,
        CacheDriverCheck::class,
        DrainStalledCheck::class,
        BufferCapacityCheck::class,
        QueueWorkerCheck::class,
        InternalLoggingCheck::class,
    ];

    /**
     * Number of recent internal-log entries (warning+) surfaced in the feed.
     */
    protected const int LOG_TAIL_LIMIT = 15;

    public function __construct(
        protected RanetraceBatchBuffer $buffer,
        protected RanetracePauseManager $pauseManager,
    ) {}

    /**
     * Collect all status information.
     *
     * All cache/database reads degrade gracefully — the status surface is a
     * diagnostic tool and must never throw, even when subsystems are down.
     *
     * @return array<string, mixed>
     */
    public function collectStatus(): array
    {
        $features = RanetraceBatchBuffer::TYPES;

        // Check global pause
        try {
            $globalPause = $this->pauseManager->getGlobalPause();
            $isGloballyPaused = $this->pauseManager->isGloballyPaused();
        } catch (Throwable) {
            $globalPause = null;
            $isGloballyPaused = false;
        }

        // Check feature pauses
        $featurePauses = [];
        foreach ($features as $feature) {
            try {
                $pauseData = $this->pauseManager->getFeaturePause($feature);
                $featurePauses[$feature] = $pauseData ? [
                    'paused' => $this->pauseManager->isFeaturePaused($feature),
                    'paused_until' => $pauseData['paused_until'],
                    'reason' => $pauseData['reason'],
                    'time_remaining_seconds' => $this->remainingSecondsUntil($pauseData['paused_until']),
                ] : null;
            } catch (Throwable) {
                $featurePauses[$feature] = null;
            }
        }

        // Get buffer sizes, last-drain timestamps, and oldest buffered-item ages
        $buffers = [];
        $lastBatch = [];
        $oldestItem = [];
        $totalBuffered = 0;
        foreach ($features as $feature) {
            try {
                $count = $this->buffer->count($feature);
            } catch (Throwable) {
                $count = 0;
            }
            $buffers[$feature] = $count;
            $totalBuffered += $count;
            $lastBatch[$feature] = $this->getLastBatchTimestamp($feature);
            $oldestItem[$feature] = $this->getOldestBufferedTimestamp($feature);
        }

        $maxPerFeature = config('ranetrace.batch.max_buffer_size', 5000);

        // Detect drain-stalled buffers. A buffer is stalled only when it holds an
        // item that has genuinely waited too long: the oldest item is older than
        // DRAIN_STALE_SECONDS AND no successful drain has happened within that
        // window. Items simply waiting for the next scheduled ranetrace:work run
        // — and brand-new buffers that have never drained yet — are NOT stalled.
        // (Treating an absent drain history alone as failure produced false
        // "drain stalled" alarms for items that were draining perfectly well.)
        $now = Carbon::now()->timestamp;
        $stalledFeatures = [];
        foreach ($features as $feature) {
            if ($buffers[$feature] <= 0) {
                continue;
            }

            if ($isGloballyPaused || ($featurePauses[$feature]['paused'] ?? false)) {
                continue;
            }

            $oldest = $oldestItem[$feature];
            if ($oldest === null || ($now - $oldest) <= self::DRAIN_STALE_SECONDS) {
                // No readable items, or the oldest item is still within the
                // normal drain window — waiting for its turn, not stalled.
                continue;
            }

            $last = $lastBatch[$feature];
            $drainedRecently = $last !== null && ($now - $last) <= self::DRAIN_STALE_SECONDS;
            if (! $drainedRecently) {
                $stalledFeatures[] = $feature;
            }
        }

        // Get failed jobs count (last 24 hours)
        $failedJobsCount = $this->getFailedJobsCount();

        // Overall health: no single buffer near its own capacity, not globally
        // paused, and few failed jobs.
        $anyBufferNearCapacity = array_any(
            $buffers,
            fn (int $count): bool => $count >= $maxPerFeature * self::NEAR_CAPACITY_RATIO
        );

        $healthy = ! $isGloballyPaused
            && ! $anyBufferNearCapacity
            && $failedJobsCount < 10;

        return [
            'healthy' => $healthy,
            'timestamp' => Carbon::now()->toIso8601String(),
            'pauses' => [
                'global' => $globalPause ? [
                    'paused' => $isGloballyPaused,
                    'paused_until' => $globalPause['paused_until'],
                    'reason' => $globalPause['reason'],
                    'time_remaining_seconds' => $this->remainingSecondsUntil($globalPause['paused_until']),
                ] : null,
                'features' => $featurePauses,
            ],
            'buffers' => [
                'total' => $totalBuffered,
                'max_per_feature' => $maxPerFeature,
                'features' => $buffers,
            ],
            'drain' => [
                'last_batch' => $lastBatch,
                'stalled' => $stalledFeatures,
            ],
            'failed_jobs_last_24h' => $failedJobsCount,
            'config' => [
                'enabled' => config('ranetrace.enabled', true),
                // Two credentials, reported separately: the ingest key sends
                // captured data in, the MCP token reads it back out. Only
                // whether each is set is reported, never the value.
                'api_key_configured' => ! empty(config('ranetrace.key')),
                'mcp_token_configured' => ! empty(config('ranetrace.mcp.token')),
                'cache_driver' => config('ranetrace.batch.cache_driver', 'file'),
                'queue_name' => config('ranetrace.batch.queue_name', 'default'),
            ],
        ];
    }

    /**
     * The full dashboard payload: the canonical status plus dashboard-only
     * extras (checks, registered surfaces, internal-log tail, environment).
     *
     * `collectStatus()` is computed once and shared with the checks so the cache
     * isn't read twice. No outbound API calls — everything is local.
     *
     * @return array{
     *     status: array<string, mixed>,
     *     checks: array<int, CheckResult>,
     *     surfaces: array<int, array{label: string, ok: bool, note: ?string}>,
     *     logs: array<int, array{time: string, level: string, message: string}>,
     *     environment: array<string, ?string>,
     * }
     */
    public function collect(): array
    {
        $status = $this->collectStatus();

        return [
            'status' => $status,
            'checks' => $this->runChecks($status),
            'surfaces' => $this->registeredSurfaces(),
            'logs' => $this->internalLogTail(),
            'environment' => $this->environment(),
        ];
    }

    /**
     * Run every configured misconfiguration check against the status array.
     * Each check is isolated: one that throws is skipped, never breaking the page.
     *
     * @param  array<string, mixed>  $status
     * @return array<int, CheckResult>
     */
    public function runChecks(array $status): array
    {
        $checks = config('ranetrace.dashboard.checks', self::DEFAULT_CHECKS);
        $results = [];

        foreach ($checks as $checkClass) {
            try {
                $check = app($checkClass);
                if ($check instanceof Check) {
                    $results[] = $check->run($status);
                }
            } catch (Throwable) {
                // A broken check must never break the dashboard — skip it.
            }
        }

        return $results;
    }

    /**
     * What the service provider actually wired up given the current config —
     * the "installation truth" view (B2). Read-only introspection; degrades to
     * an empty list rather than throwing.
     *
     * @return array<int, array{label: string, ok: bool, note: ?string}>
     */
    public function registeredSurfaces(): array
    {
        try {
            $webGroup = app('router')->getMiddlewareGroups()['web'] ?? [];
            $pageVisitActive = in_array(TrackPageVisit::class, $webGroup, true);

            // The MCP server is gated on the MCP token, not the ingest key —
            // the two are different credentials and an ingest key alone gets a
            // 403 from every MCP endpoint.
            $mcpActive = class_exists(\Laravel\Mcp\Facades\Mcp::class)
                && config('ranetrace.mcp.enabled', true)
                && ! empty(config('ranetrace.mcp.token'));

            $ranetraceChannel = config()->has('logging.channels.ranetrace');

            return [
                $this->surface('JavaScript-error ingest route', Route::has('ranetrace.javascript-errors.store'), 'Active only when javascript_errors.enabled'),
                $this->surface('Dashboard route', Route::has('ranetrace.dashboard')),
                $this->surface('Page-visit middleware (web group)', $pageVisitActive, 'Active only when website_analytics.enabled'),
                $this->surface('@ranetraceErrorTracking Blade directive', isset(Blade::getCustomDirectives()['ranetraceErrorTracking'])),
                $this->surface('MCP server', $mcpActive, 'Needs laravel/mcp + mcp.enabled + RANETRACE_MCP_TOKEN'),
                $this->surface('Internal log channel', config()->has('logging.channels.ranetrace_internal')),
                $this->surface('Ranetrace log channel', $ranetraceChannel),
            ];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Most-recent internal-log entries at warning level or above. Reads the
     * latest daily `ranetrace-internal-*.log` file; an absent/unreadable file
     * yields an empty feed rather than an error.
     *
     * @return array<int, array{time: string, level: string, message: string}>
     */
    public function internalLogTail(int $limit = self::LOG_TAIL_LIMIT): array
    {
        try {
            $files = glob(storage_path('logs/ranetrace-internal-*.log')) ?: [];

            if ($files === []) {
                $single = storage_path('logs/ranetrace-internal.log');
                $files = is_file($single) ? [$single] : [];
            }

            if ($files === []) {
                return [];
            }

            sort($files); // daily filenames sort chronologically
            $contents = @file_get_contents((string) end($files));

            return $contents === false ? [] : $this->parseLogTail($contents, $limit);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Environment and version facts (H).
     *
     * @return array<string, ?string>
     */
    public function environment(): array
    {
        return [
            'package' => $this->packageVersion(),
            'laravel' => app()->version(),
            'php' => PHP_VERSION,
            'env' => app()->environment(),
            'queue' => (string) config('queue.default', 'sync'),
            'cache' => (string) config('cache.default', 'file'),
        ];
    }

    /**
     * @return array{label: string, ok: bool, note: ?string}
     */
    protected function surface(string $label, bool $ok, ?string $note = null): array
    {
        return ['label' => $label, 'ok' => $ok, 'note' => $ok ? null : $note];
    }

    /**
     * Extract warning+ entries from raw log contents, keeping the most recent
     * $limit in chronological order. Continuation lines (stack traces) are
     * ignored — only lines that start a log entry are parsed.
     *
     * @return array<int, array{time: string, level: string, message: string}>
     */
    protected function parseLogTail(string $contents, int $limit): array
    {
        $surfaced = ['WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];
        $entries = [];

        foreach (explode("\n", $contents) as $line) {
            if (! preg_match('/^\[(?<time>[^\]]+)\]\s+\S+\.(?<level>[A-Z]+):\s*(?<msg>.*)$/', $line, $m)) {
                continue;
            }

            if (! in_array($m['level'], $surfaced, true)) {
                continue;
            }

            $entries[] = [
                'time' => $m['time'],
                'level' => $m['level'],
                'message' => mb_substr(mb_trim($m['msg']), 0, 200),
            ];
        }

        return array_slice($entries, -$limit);
    }

    /**
     * Installed package version, or null if it cannot be resolved (e.g. running
     * from a path repository without Composer metadata).
     */
    protected function packageVersion(): ?string
    {
        try {
            return InstalledVersions::getPrettyVersion('ranetrace/ranetrace-laravel');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whole seconds remaining until a pause's ISO-8601 expiry timestamp, floored
     * at 0. Carbon 3's diffInSeconds() returns a float, so the result is cast to
     * int — both the CLI's formatDuration() and the JSON `time_remaining_seconds`
     * field require an integer (a float would TypeError under strict_types).
     */
    protected function remainingSecondsUntil(string $until): int
    {
        return (int) max(0, round(Carbon::now()->diffInSeconds(Carbon::parse($until), false)));
    }

    /**
     * Get the timestamp of the last successful batch send for a feature.
     *
     * Numeric strings are accepted as well as ints: the Redis and Memcached
     * cache stores keep bare numbers un-serialized (to support atomic
     * increments) and hand them back as numeric strings. An `is_int()` check
     * would discard a perfectly valid timestamp from those stores and report
     * the buffer as never drained — the root cause of false "drain stalled"
     * alarms on a Redis-backed cache.
     */
    protected function getLastBatchTimestamp(string $feature): ?int
    {
        try {
            $cacheDriver = config('ranetrace.batch.cache_driver', 'file');
            $value = Cache::store($cacheDriver)->get(SendBatchToRanetraceJob::LAST_BATCH_PREFIX.$feature);

            return is_numeric($value) ? (int) $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Get the timestamp of the oldest item currently buffered for a feature,
     * or null when the buffer is empty or unreadable. Drives stalled detection:
     * a buffer is only stalled once its oldest item has waited past the drain
     * window, never the instant an item lands.
     */
    protected function getOldestBufferedTimestamp(string $feature): ?int
    {
        try {
            return $this->buffer->oldestTimestamp($feature);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Get count of failed Ranetrace jobs in the last 24 hours.
     */
    protected function getFailedJobsCount(): int
    {
        try {
            return DB::table('failed_jobs')
                ->where('payload', 'like', '%Ranetrace%')
                ->where('failed_at', '>=', Carbon::now()->subDay())
                ->count();
        } catch (Throwable) {
            // Table might not exist or database not available
            return 0;
        }
    }
}
