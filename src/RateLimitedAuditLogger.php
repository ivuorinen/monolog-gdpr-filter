<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter;

/**
 * Rate-limited wrapper for audit logging to prevent log flooding.
 *
 * This class wraps any audit logger callable and applies rate limiting
 * to prevent overwhelming the audit system with too many log entries.
 *
 * @api
 */
class RateLimitedAuditLogger
{
    private readonly RateLimiter $rateLimiter;

    /**
     * @param callable(string,mixed,mixed):void $auditLogger The underlying audit logger
     * @param int $maxRequestsPerMinute Maximum audit log entries per minute (default: 100)
     * @param int $windowSeconds Time window for rate limiting in seconds (default: 60)
     */
    public function __construct(
        private readonly mixed $auditLogger,
        int $maxRequestsPerMinute = 100,
        int $windowSeconds = 60
    ) {
        $this->rateLimiter = new RateLimiter($maxRequestsPerMinute, $windowSeconds);
    }

    /**
     * Log an audit entry if rate limiting allows it.
     *
     * @param string $path The path or operation being audited
     * @param mixed $original The original value
     * @param mixed $masked The masked value
     */
    public function __invoke(string $path, mixed $original, mixed $masked): void
    {
        // Use a combination of path and operation type as the rate limiting key
        $key = $this->generateRateLimitKey($path);

        if ($this->rateLimiter->isAllowed($key)) {
            // Rate limit allows this log entry
            if (is_callable($this->auditLogger)) {
                ($this->auditLogger)($path, $original, $masked);
            }
        } else {
            // Rate limit exceeded - optionally log a rate limit warning
            $this->logRateLimitExceeded($path, $key);
        }
    }

    public function isOperationAllowed(string $path): bool
    {
        // Use a combination of path and operation type as the rate limiting key
        $key = $this->generateRateLimitKey($path);

        return $this->rateLimiter->isAllowed($key);
    }

    /**
     * Get rate limiting statistics for all active operation types.
     *
     * @return int[][]
     *
     * @psalm-return array{'audit:general_operations'?: array{current_requests: int<1, max>, remaining_requests: int<0, max>, time_until_reset: int<0, max>}, 'audit:error_operations'?: array{current_requests: int<1, max>, remaining_requests: int<0, max>, time_until_reset: int<0, max>}, 'audit:regex_operations'?: array{current_requests: int<1, max>, remaining_requests: int<0, max>, time_until_reset: int<0, max>}, 'audit:conditional_operations'?: array{current_requests: int<1, max>, remaining_requests: int<0, max>, time_until_reset: int<0, max>}, 'audit:json_operations'?: array{current_requests: int<1, max>, remaining_requests: int<0, max>, time_until_reset: int<0, max>}}
     */
    public function getRateLimitStats(): array
    {
        // Get all possible operation types based on the classification logic
        $operationTypes = [
            'audit:json_operations',
            'audit:conditional_operations',
            'audit:regex_operations',
            'audit:error_operations',
            'audit:general_operations'
        ];

        $stats = [];
        foreach ($operationTypes as $type) {
            $typeStats = $this->rateLimiter->getStats($type);
            // Only include operation types that have been used
            if ($typeStats['current_requests'] > 0) {
                $stats[$type] = $typeStats;
            }
        }

        return $stats;
    }

    /**
     * Clear all rate limiting data.
     */
    public function clearRateLimitData(): void
    {
        RateLimiter::clearAll();
    }

    /**
     * Generate a rate limiting key based on the audit operation.
     *
     * This allows different types of operations to have separate rate limits.
     */
    private function generateRateLimitKey(string $path): string
    {
        // Group similar operations together to prevent flooding of specific operation types
        $operationType = $this->getOperationType($path);

        // Use operation type as the primary key for rate limiting
        return 'audit:' . $operationType;
    }

    /**
     * Determine the operation type from the path.
     */
    private function getOperationType(string $path): string
    {
        // Group different operations into categories for rate limiting
        return match (true) {
            str_contains($path, 'json_') => 'json_operations',
            str_contains($path, 'conditional_') => 'conditional_operations',
            str_contains($path, 'regex_') => 'regex_operations',
            str_contains($path, 'preg_replace_') => 'regex_operations',
            str_contains($path, 'error') => 'error_operations',
            default => 'general_operations'
        };
    }

    /**
     * Log when rate limiting is exceeded (with its own rate limiting to prevent spam).
     */
    private function logRateLimitExceeded(string $path, string $key): void
    {
        // Create a separate rate limiter for warnings to avoid interfering with main rate limiting
        static $warningRateLimiter = null;
        if ($warningRateLimiter === null) {
            $warningRateLimiter = new RateLimiter(1, 60); // 1 warning per minute per operation type
        }

        $warningKey = 'warning:' . $key;

        // Only log rate limit warnings once per minute per operation type to prevent warning spam
        if ($warningRateLimiter->isAllowed($warningKey) && is_callable($this->auditLogger)) {
            ($this->auditLogger)(
                'rate_limit_exceeded',
                $path,
                sprintf(
                    'Audit logging rate limit exceeded for operation type: %s. Stats: %s',
                    $key,
                    json_encode($this->rateLimiter->getStats($key)) ?: 'N/A'
                )
            );
        }
    }

    /**
     * Create a factory method for common configurations.
     *
     * @psalm-param callable(string, mixed, mixed):void $auditLogger
     */
    public static function create(
        callable $auditLogger,
        string $profile = 'default'
    ): self {
        return match ($profile) {
            'strict' => new self($auditLogger, 50, 60),    // 50 per minute
            'relaxed' => new self($auditLogger, 200, 60),  // 200 per minute
            'testing' => new self($auditLogger, 1000, 60), // 1000 per minute for testing
            default => new self($auditLogger, 100, 60),    // 100 per minute (default)
        };
    }
}
