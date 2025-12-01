<?php

namespace App\Traits;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

trait HandlesDeadlocks
{
    /**
     * Maximum number of retry attempts for deadlock recovery.
     */
    private const MAX_RETRIES = 3;

    /**
     * Base delay in milliseconds for exponential backoff.
     */
    private const BASE_DELAY_MS = 10;

    /**
     * Get metrics service instance.
     * Uses lazy loading to avoid circular dependencies.
     *
     * @return \App\Services\MetricsService
     */
    private function getMetricsService(): \App\Services\MetricsService
    {
        return app(\App\Services\MetricsService::class);
    }


    /**
     * Execute a transaction with deadlock retry logic.
     *
     * @param callable $callback The transaction callback
     * @param string $operationName Name of the operation for logging
     * @return mixed
     * @throws \Exception
     */
    protected function executeWithDeadlockRetry(callable $callback, string $operationName): mixed
    {
        $attempt = 0;

        while ($attempt < self::MAX_RETRIES) {
            try {
                $result = $callback();

                // Log success if this was a retry
                if ($attempt > 0) {
                    Log::info("Deadlock retry succeeded for {$operationName}", [
                        'operation' => $operationName,
                        'final_attempt' => $attempt + 1,
                        'result' => 'success',
                        'timestamp' => now()->toIso8601String(),
                    ]);
                }

                return $result;
            } catch (QueryException $e) {
                if ($this->isDeadlockException($e)) {
                    $attempt++;

                    // Track deadlock retry metric
                    $this->getMetricsService()->incrementDeadlockRetries();

                    Log::warning("Deadlock detected in {$operationName}", [
                        'operation' => $operationName,
                        'attempt' => $attempt,
                        'retry_count' => $attempt,
                        'max_retries' => self::MAX_RETRIES,
                        'error_code' => $e->getCode(),
                        'error_message' => $e->getMessage(),
                        'timestamp' => now()->toIso8601String(),
                    ]);

                    if ($attempt < self::MAX_RETRIES) {
                        // Exponential backoff: 10ms, 20ms, 40ms
                        $delayMs = self::BASE_DELAY_MS * (2 ** ($attempt - 1));
                        usleep($delayMs * 1000); // Convert ms to microseconds

                        Log::info("Retrying {$operationName} after deadlock", [
                            'operation' => $operationName,
                            'attempt' => $attempt,
                            'retry_count' => $attempt,
                            'next_attempt' => $attempt + 1,
                            'delay_ms' => $delayMs,
                            'timestamp' => now()->toIso8601String(),
                        ]);

                        continue;
                    }
                }

                // Not a deadlock or max retries reached, rethrow
                throw $e;
            }
        }

        // Max retries reached
        Log::error("Deadlock retry limit exceeded for {$operationName}", [
            'operation' => $operationName,
            'max_retries' => self::MAX_RETRIES,
            'final_attempt' => $attempt,
            'retry_count' => $attempt,
            'result' => 'failure',
            'timestamp' => now()->toIso8601String(),
        ]);

        abort(500, "Service temporarily unavailable due to database contention. Please try again.");
    }

    /**
     * Check if the exception is a deadlock error.
     *
     * @param QueryException $e
     * @return bool
     */
    private function isDeadlockException(QueryException $e): bool
    {
        $errorCode = (string) $e->getCode();
        $errorMessage = strtolower($e->getMessage());

        // Check for MySQL deadlock error code (1213) or error message
        return $errorCode === '40001'
            || $errorCode === '1213'
            || str_contains($errorMessage, 'deadlock')
            || str_contains($errorMessage, 'try restarting transaction');
    }
}
