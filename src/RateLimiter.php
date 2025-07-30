<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter;

/**
 * Simple rate limiter to prevent audit log flooding.
 *
 * Uses a sliding window approach with memory-based storage.
 * For production use, consider implementing persistent storage.
 */
class RateLimiter
{
    /**
     * Storage for request timestamps per key.
     * @var array<string, array<int>>
     */
    private static array $requests = [];

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

        // Check if we're under the limit
        if (count(self::$requests[$key]) < $this->maxRequests) {
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
}
