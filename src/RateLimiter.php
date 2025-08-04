<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter;

/**
 * Simple rate limiter to prevent audit log flooding.
 *
 * Uses a sliding window approach with memory-based storage.
 * For production use, consider implementing persistent storage.
 *
 * @api
 */
class RateLimiter
{
    /**
     * Storage for request timestamps per key.
     * @var array<string, array<int>>
     */
    private static array $requests = [];

    /**
     * Last time global cleanup was performed.
     */
    private static int $lastCleanup = 0;

    /**
     * How often to perform global cleanup (in seconds).
     */
    private static int $cleanupInterval = 300; // 5 minutes

    /**
     * @param int $maxRequests Maximum number of requests allowed
     * @param int $windowSeconds Time window in seconds
     */
    public function __construct(
        private readonly int $maxRequests,
        private readonly int $windowSeconds
    ) {
    }

    /**
     * Check if a request is allowed for the given key.
     */
    public function isAllowed(string $key): bool
    {
        $now = time();
        $windowStart = $now - $this->windowSeconds;

        // Initialize key if not exists
        if (!isset(self::$requests[$key])) {
            self::$requests[$key] = [];
        }

        // Remove old requests outside the window
        self::$requests[$key] = array_filter(
            self::$requests[$key],
            fn(int $timestamp): bool => $timestamp > $windowStart
        );

        // Perform global cleanup periodically to prevent memory leaks
        $this->performGlobalCleanupIfNeeded($now);

        // Check if we're under the limit
        if (count(self::$requests[$key] ?? []) < $this->maxRequests) {
            // Add current request
            self::$requests[$key][] = $now;
            return true;
        }

        return false;
    }

    /**
     * Get time until next request is allowed (in seconds).
     *
     * @psalm-return int<0, max>
     */
    public function getTimeUntilReset(string $key): int
    {
        if (!isset(self::$requests[$key]) || empty(self::$requests[$key])) {
            return 0;
        }

        $now = time();
        $oldestRequest = min(self::$requests[$key]);
        $resetTime = $oldestRequest + $this->windowSeconds;

        return max(0, $resetTime - $now);
    }

    /**
     * Get statistics for a specific key.
     *
     * @return int[]
     *
     * @psalm-return array{current_requests: int<0, max>, remaining_requests: int<0, max>, time_until_reset: int<0, max>}
     */
    public function getStats(string $key): array
    {
        $now = time();
        $windowStart = $now - $this->windowSeconds;

        $currentRequests = 0;
        if (isset(self::$requests[$key])) {
            $currentRequests = count(array_filter(
                self::$requests[$key],
                fn(int $timestamp): bool => $timestamp > $windowStart
            ));
        }

        return [
            'current_requests' => $currentRequests,
            'remaining_requests' => max(0, $this->maxRequests - $currentRequests),
            'time_until_reset' => $this->getTimeUntilReset($key),
        ];
    }

    /**
     * Get remaining requests for a specific key.
     *
     * @param string $key The rate limiting key
     * @return int The number of remaining requests
     *
     * @psalm-return int<0, max>
     */
    public function getRemainingRequests(string $key): int
    {
        return $this->getStats($key)['remaining_requests'] ?? 0;
    }

    public static function clearAll(): void
    {
        self::$requests = [];
    }

    public static function clearKey(string $key): void
    {
        if (isset(self::$requests[$key])) {
            unset(self::$requests[$key]);
        }
    }

    /**
     * Perform global cleanup if enough time has passed.
     * This prevents memory leaks from accumulating unused keys.
     */
    private function performGlobalCleanupIfNeeded(int $now): void
    {
        if ($now - self::$lastCleanup >= self::$cleanupInterval) {
            $this->performGlobalCleanup($now);
            self::$lastCleanup = $now;
        }
    }

    /**
     * Clean up all expired entries across all keys.
     * This prevents memory leaks from accumulating old unused keys.
     */
    private function performGlobalCleanup(int $now): void
    {
        $windowStart = $now - $this->windowSeconds;

        foreach (self::$requests as $key => $timestamps) {
            // Filter out old timestamps
            $validTimestamps = array_filter(
                $timestamps,
                fn(int $timestamp): bool => $timestamp > $windowStart
            );

            if ($validTimestamps === []) {
                // Remove keys with no valid timestamps
                unset(self::$requests[$key]);
            } else {
                // Update with filtered timestamps
                self::$requests[$key] = array_values($validTimestamps);
            }
        }
    }

    /**
     * Get memory usage statistics for debugging.
     *
     * @return int[]
     *
     * @psalm-return array{total_keys: int<0, max>, total_timestamps: int, estimated_memory_bytes: int<min, max>, last_cleanup: int, cleanup_interval: int}
     */
    public static function getMemoryStats(): array
    {
        $totalKeys = count(self::$requests);
        $totalTimestamps = array_sum(array_map('count', self::$requests));
        $estimatedMemory = $totalKeys * 50 + $totalTimestamps * 8; // Rough estimate

        return [
            'total_keys' => $totalKeys,
            'total_timestamps' => $totalTimestamps,
            'estimated_memory_bytes' => $estimatedMemory,
            'last_cleanup' => self::$lastCleanup,
            'cleanup_interval' => self::$cleanupInterval,
        ];
    }

    /**
     * Configure the global cleanup interval.
     *
     * @param int $seconds Cleanup interval in seconds (minimum 60)
     */
    public static function setCleanupInterval(int $seconds): void
    {
        self::$cleanupInterval = max(60, $seconds); // Minimum 1 minute
    }
}
