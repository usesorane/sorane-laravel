<?php

declare(strict_types=1);

namespace Sorane\Laravel\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class SoranePauseManager
{
    protected const string FEATURE_PAUSE_PREFIX = 'sorane.feature.';

    protected const string GLOBAL_PAUSE_KEY = 'sorane.global.pause';

    protected string $cacheDriver;

    public function __construct()
    {
        $this->cacheDriver = config('sorane.batch.cache_driver', 'redis');
    }

    /**
     * Set a global pause (applies to all features).
     */
    public function setGlobalPause(int $seconds, string $reason): void
    {
        $pausedUntil = Carbon::now()->addSeconds($seconds);

        Cache::store($this->cacheDriver)->put(
            self::GLOBAL_PAUSE_KEY,
            [
                'paused_until' => $pausedUntil->toIso8601String(),
                'reason' => $reason,
            ],
            $pausedUntil
        );
    }

    /**
     * Set a pause for a specific feature.
     */
    public function setFeaturePause(string $feature, int $seconds, string $reason): void
    {
        $pausedUntil = Carbon::now()->addSeconds($seconds);

        Cache::store($this->cacheDriver)->put(
            $this->getFeaturePauseKey($feature),
            [
                'paused_until' => $pausedUntil->toIso8601String(),
                'reason' => $reason,
            ],
            $pausedUntil
        );
    }

    /**
     * Check if globally paused.
     */
    public function isGloballyPaused(): bool
    {
        $pauseData = Cache::store($this->cacheDriver)->get(self::GLOBAL_PAUSE_KEY);

        if (! $pauseData) {
            return false;
        }

        $pausedUntil = Carbon::parse($pauseData['paused_until']);

        return Carbon::now()->lessThan($pausedUntil);
    }

    /**
     * Check if a specific feature is paused.
     */
    public function isFeaturePaused(string $feature): bool
    {
        $pauseData = Cache::store($this->cacheDriver)->get($this->getFeaturePauseKey($feature));

        if (! $pauseData) {
            return false;
        }

        $pausedUntil = Carbon::parse($pauseData['paused_until']);

        return Carbon::now()->lessThan($pausedUntil);
    }

    /**
     * Get global pause data.
     *
     * @return array{paused_until: string, reason: string}|null
     */
    public function getGlobalPause(): ?array
    {
        return Cache::store($this->cacheDriver)->get(self::GLOBAL_PAUSE_KEY);
    }

    /**
     * Get feature pause data.
     *
     * @return array{paused_until: string, reason: string}|null
     */
    public function getFeaturePause(string $feature): ?array
    {
        return Cache::store($this->cacheDriver)->get($this->getFeaturePauseKey($feature));
    }

    /**
     * Clear global pause.
     */
    public function clearGlobalPause(): void
    {
        Cache::store($this->cacheDriver)->forget(self::GLOBAL_PAUSE_KEY);
    }

    /**
     * Clear feature pause.
     */
    public function clearFeaturePause(string $feature): void
    {
        Cache::store($this->cacheDriver)->forget($this->getFeaturePauseKey($feature));
    }

    /**
     * Get the cache key for a feature pause.
     */
    protected function getFeaturePauseKey(string $feature): string
    {
        return self::FEATURE_PAUSE_PREFIX.$feature.'.pause';
    }
}
