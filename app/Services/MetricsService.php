<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class MetricsService
{
    private const METRICS_TTL = 86400; // 24 hours

    /**
     * Increment webhook duplicates counter.
     *
     * @return void
     */
    public function incrementWebhookDuplicates(): void
    {
        $this->incrementCounter('webhook_duplicates');
    }

    /**
     * Increment deadlock retries counter.
     *
     * @return void
     */
    public function incrementDeadlockRetries(): void
    {
        $this->incrementCounter('deadlock_retries');
    }

    /**
     * Record hold creation time.
     *
     * @param float $timeMs Time in milliseconds
     * @return void
     */
    public function recordHoldCreationTime(float $timeMs): void
    {
        $this->recordTiming('hold_creation_times', $timeMs);
    }

    /**
     * Record webhook processing time.
     *
     * @param float $timeMs Time in milliseconds
     * @return void
     */
    public function recordWebhookProcessingTime(float $timeMs): void
    {
        $this->recordTiming('webhook_processing_times', $timeMs);
    }

    /**
     * Record cache hit for product available stock.
     *
     * @return void
     */
    public function recordCacheHit(): void
    {
        $this->incrementCounter('cache_hits');
    }

    /**
     * Record cache miss for product available stock.
     *
     * @return void
     */
    public function recordCacheMiss(): void
    {
        $this->incrementCounter('cache_misses');
    }

    /**
     * Get webhook duplicates count.
     *
     * @return int
     */
    public function getWebhookDuplicatesCount(): int
    {
        return $this->getCounter('webhook_duplicates');
    }

    /**
     * Get deadlock retries count.
     *
     * @return int
     */
    public function getDeadlockRetriesCount(): int
    {
        return $this->getCounter('deadlock_retries');
    }

    /**
     * Get average hold creation time.
     *
     * @return float|null Returns null if no data available
     */
    public function getAverageHoldCreationTime(): ?float
    {
        return $this->getAverageTiming('hold_creation_times');
    }

    /**
     * Get average webhook processing time.
     *
     * @return float|null Returns null if no data available
     */
    public function getAverageWebhookProcessingTime(): ?float
    {
        return $this->getAverageTiming('webhook_processing_times');
    }

    /**
     * Get cache hit rate for product available stock.
     *
     * @return float|null Returns null if no data available (0-1 scale, where 1 = 100%)
     */
    public function getCacheHitRate(): ?float
    {
        $hits = $this->getCounter('cache_hits');
        $misses = $this->getCounter('cache_misses');
        $total = $hits + $misses;

        if ($total === 0) {
            return null;
        }

        return $hits / $total;
    }

    /**
     * Get all metrics.
     *
     * @return array
     */
    public function getAllMetrics(): array
    {
        return [
            'webhook_duplicates' => $this->getWebhookDuplicatesCount(),
            'deadlock_retries' => $this->getDeadlockRetriesCount(),
            'average_hold_creation_time_ms' => $this->getAverageHoldCreationTime(),
            'average_webhook_processing_time_ms' => $this->getAverageWebhookProcessingTime(),
            'cache_hit_rate' => $this->getCacheHitRate(),
            'cache_hits' => $this->getCounter('cache_hits'),
            'cache_misses' => $this->getCounter('cache_misses'),
        ];
    }

    /**
     * Reset all metrics.
     *
     * @return void
     */
    public function resetMetrics(): void
    {
        Cache::forget('metrics_webhook_duplicates');
        Cache::forget('metrics_deadlock_retries');
        Cache::forget('metrics_hold_creation_times');
        Cache::forget('metrics_webhook_processing_times');
        Cache::forget('metrics_cache_hits');
        Cache::forget('metrics_cache_misses');
    }

    /**
     * Increment a counter metric.
     *
     * @param string $key
     * @return void
     */
    private function incrementCounter(string $key): void
    {
        $cacheKey = "metrics_{$key}";
        $current = Cache::get($cacheKey, 0);
        Cache::put($cacheKey, $current + 1, self::METRICS_TTL);
    }

    /**
     * Get counter value.
     *
     * @param string $key
     * @return int
     */
    private function getCounter(string $key): int
    {
        return Cache::get("metrics_{$key}", 0);
    }

    /**
     * Record a timing value.
     *
     * @param string $key
     * @param float $timeMs
     * @return void
     */
    private function recordTiming(string $key, float $timeMs): void
    {
        $cacheKey = "metrics_{$key}";
        $timings = Cache::get($cacheKey, []);
        $timings[] = $timeMs;

        // Keep only last 1000 timings to prevent memory issues
        if (count($timings) > 1000) {
            $timings = array_slice($timings, -1000);
        }

        Cache::put($cacheKey, $timings, self::METRICS_TTL);
    }

    /**
     * Get average timing.
     *
     * @param string $key
     * @return float|null
     */
    private function getAverageTiming(string $key): ?float
    {
        $timings = Cache::get("metrics_{$key}", []);

        if (empty($timings)) {
            return null;
        }

        return array_sum($timings) / count($timings);
    }
}
