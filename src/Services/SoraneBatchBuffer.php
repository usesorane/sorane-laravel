<?php

declare(strict_types=1);

namespace Sorane\Laravel\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Psr\SimpleCache\InvalidArgumentException;
use Sorane\Laravel\Support\InternalLogger;

class SoraneBatchBuffer
{
    protected const string BUFFER_PREFIX = 'sorane:buffer:';

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
        $addItemResult = Cache::store($this->cacheDriver)->lock($cacheKey.':lock', 10)->get(function () use ($cacheKey, $item) {
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

        if ($addItemResult === false) {
            // This means the lock could not be acquired; log a warning
            InternalLogger::warning('Could not acquire cache lock to add item to buffer', ['type' => $type]);
        }
    }

    /**
     * Get items from the buffer for processing.
     * Items are atomically removed from the buffer to prevent duplicate processing.
     *
     * @return array<int, array{id: string, data: array, timestamp: int}>
     *
     * @throws InvalidArgumentException
     */
    public function getItems(string $type, int $limit): array
    {
        $cacheKey = $this->getCacheKey($type);

        $itemsToProcess = Cache::store($this->cacheDriver)->lock($cacheKey.':lock', 10)->get(function () use ($cacheKey, $limit) {
            $buffer = Cache::store($this->cacheDriver)->get($cacheKey, []);

            // Get items to process
            $itemsToProcess = array_slice($buffer, 0, $limit);

            // Remove these items from the buffer atomically
            $buffer = array_slice($buffer, $limit);

            // Update cache
            if (empty($buffer)) {
                Cache::store($this->cacheDriver)->forget($cacheKey);
            } else {
                Cache::store($this->cacheDriver)->put($cacheKey, $buffer, $this->ttl);
            }

            return $itemsToProcess;
        });

        if ($itemsToProcess === false) {
            // This means the lock could not be acquired; return empty array
            // Can happen sometimes in high concurrency scenarios, but should not happen often

            // Log to internal logger for monitoring
            InternalLogger::warning('Could not acquire cache lock to get items from buffer', ['type' => $type]);

            $itemsToProcess = [];
        }

        return $itemsToProcess;
    }

    /**
     * Clear specific items from the buffer by their IDs.
     *
     * @param  array<int, string>  $ids
     *
     * @throws InvalidArgumentException
     */
    public function clearItems(string $type, array $ids): void
    {
        $cacheKey = $this->getCacheKey($type);

        $clearItemsResult = Cache::store($this->cacheDriver)->lock($cacheKey.':lock', 10)->get(function () use ($cacheKey, $ids) {
            $buffer = Cache::store($this->cacheDriver)->get($cacheKey, []);

            // Filter out items with matching IDs
            $idsFlipped = array_flip($ids);
            $buffer = array_values(array_filter($buffer, function ($item) use ($idsFlipped) {
                return ! isset($idsFlipped[$item['id']]);
            }));

            if (empty($buffer)) {
                Cache::store($this->cacheDriver)->forget($cacheKey);
            } else {
                Cache::store($this->cacheDriver)->put($cacheKey, $buffer, $this->ttl);
            }
        });

        if ($clearItemsResult === false) {
            // This means the lock could not be acquired; log a warning
            InternalLogger::warning('Could not acquire cache lock to clear items from buffer', ['type' => $type, 'ids' => $ids]);
        }
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
