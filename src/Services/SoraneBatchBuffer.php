<?php

declare(strict_types=1);

namespace Sorane\Laravel\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SoraneBatchBuffer
{
    protected const BUFFER_PREFIX = 'sorane:buffer:';

    protected string $cacheDriver;

    protected int $ttl;

    public function __construct()
    {
        $this->cacheDriver = config('sorane.batch.cache_driver', 'redis');
        $this->ttl = config('sorane.batch.buffer_ttl', 3600);
    }

    /**
     * Add an item to the buffer for a specific type.
     */
    public function addItem(string $type, array $data): void
    {
        $cacheKey = $this->getCacheKey($type);

        $item = [
            'id' => (string) Str::uuid(),
            'data' => $data,
            'timestamp' => now()->timestamp,
        ];

        // Use cache lock to ensure thread-safety
        Cache::store($this->cacheDriver)->lock($cacheKey.':lock', 10)->get(function () use ($cacheKey, $item) {
            $buffer = Cache::store($this->cacheDriver)->get($cacheKey, []);
            $buffer[] = $item;

            // Check max buffer size
            $maxSize = $this->getMaxBufferSize();
            if (count($buffer) > $maxSize) {
                // Keep only the most recent items
                $buffer = array_slice($buffer, -$maxSize);
            }

            Cache::store($this->cacheDriver)->put($cacheKey, $buffer, $this->ttl);
        });
    }

    /**
     * Get items from the buffer for processing.
     *
     * @return array<int, array{id: string, data: array, timestamp: int}>
     */
    public function getItems(string $type, int $limit): array
    {
        $cacheKey = $this->getCacheKey($type);

        return Cache::store($this->cacheDriver)->lock($cacheKey.':lock', 10)->get(function () use ($cacheKey, $limit) {
            $buffer = Cache::store($this->cacheDriver)->get($cacheKey, []);

            return array_slice($buffer, 0, $limit);
        });
    }

    /**
     * Clear specific items from the buffer by their IDs.
     *
     * @param  array<int, string>  $ids
     */
    public function clearItems(string $type, array $ids): void
    {
        $cacheKey = $this->getCacheKey($type);

        Cache::store($this->cacheDriver)->lock($cacheKey.':lock', 10)->get(function () use ($cacheKey, $ids) {
            $buffer = Cache::store($this->cacheDriver)->get($cacheKey, []);

            // Filter out items with matching IDs
            $buffer = array_values(array_filter($buffer, function ($item) use ($ids) {
                return ! in_array($item['id'], $ids, true);
            }));

            if (empty($buffer)) {
                Cache::store($this->cacheDriver)->forget($cacheKey);
            } else {
                Cache::store($this->cacheDriver)->put($cacheKey, $buffer, $this->ttl);
            }
        });
    }

    /**
     * Get the count of items in the buffer for a specific type.
     */
    public function count(string $type): int
    {
        $cacheKey = $this->getCacheKey($type);
        $buffer = Cache::store($this->cacheDriver)->get($cacheKey, []);

        return count($buffer);
    }

    /**
     * Clear all items from the buffer for a specific type.
     */
    public function clear(string $type): void
    {
        $cacheKey = $this->getCacheKey($type);
        Cache::store($this->cacheDriver)->forget($cacheKey);
    }

    /**
     * Get all available buffer types that have items.
     *
     * @return array<int, string>
     */
    public function getAvailableTypes(): array
    {
        return array_filter([
            'events',
            'logs',
            'page_visits',
            'javascript_errors',
        ], fn ($type) => $this->count($type) > 0);
    }

    /**
     * Get the cache key for a specific type.
     */
    protected function getCacheKey(string $type): string
    {
        return self::BUFFER_PREFIX.$type;
    }

    /**
     * Get the maximum buffer size to prevent memory issues.
     */
    protected function getMaxBufferSize(): int
    {
        return config('sorane.batch.max_buffer_size', 1000);
    }
}
