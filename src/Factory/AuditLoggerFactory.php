<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Factory;

use Closure;
use Ivuorinen\MonologGdprFilter\RateLimitedAuditLogger;

/**
 * Factory for creating audit logger instances.
 *
 * This class provides factory methods for creating various types of
 * audit loggers, including rate-limited and array-based loggers.
 *
 * @api
 */
final class AuditLoggerFactory
{
    /**
     * Create a rate-limited audit logger wrapper.
     *
     * @param callable(string,mixed,mixed):void $auditLogger The underlying audit logger
     * @param string $profile Rate limiting profile: 'strict', 'default', 'relaxed', or 'testing'
     */
    public function createRateLimited(
        callable $auditLogger,
        string $profile = 'default'
    ): RateLimitedAuditLogger {
        return RateLimitedAuditLogger::create($auditLogger, $profile);
    }

    /**
     * Create a simple audit logger that logs to an array (useful for testing).
     *
     * @param array<array-key, mixed> $logStorage Reference to array for storing logs
     * @psalm-param array<array{path: string, original: mixed, masked: mixed}> $logStorage
     * @psalm-param-out array<array{path: string, original: mixed, masked: mixed, timestamp: int<1, max>}> $logStorage
     * @phpstan-param-out array<array-key, mixed> $logStorage
     * @param bool $rateLimited Whether to apply rate limiting (default: false for testing)
     *
     * @psalm-return RateLimitedAuditLogger|Closure(string, mixed, mixed):void
     * @psalm-suppress ReferenceConstraintViolation
     */
    public function createArrayLogger(
        array &$logStorage,
        bool $rateLimited = false
    ): Closure|RateLimitedAuditLogger {
        $baseLogger = function (string $path, mixed $original, mixed $masked) use (&$logStorage): void {
            $logStorage[] = [
                'path' => $path,
                'original' => $original,
                'masked' => $masked,
                'timestamp' => time()
            ];
        };

        return $rateLimited
            ? $this->createRateLimited($baseLogger, 'testing')
            : $baseLogger;
    }

    /**
     * Create a null audit logger that does nothing.
     *
     * @return Closure(string, mixed, mixed):void
     */
    public function createNullLogger(): Closure
    {
        return function (string $path, mixed $original, mixed $masked): void {
            // Intentionally do nothing - null object pattern
            unset($path, $original, $masked);
        };
    }

    /**
     * Create a callback-based logger.
     *
     * @param callable(string, mixed, mixed):void $callback The callback to invoke
     * @return Closure(string, mixed, mixed):void
     */
    public function createCallbackLogger(callable $callback): Closure
    {
        return function (string $path, mixed $original, mixed $masked) use ($callback): void {
            $callback($path, $original, $masked);
        };
    }

    /**
     * Static factory method for convenience.
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Static method: Create a rate-limited audit logger wrapper.
     *
     * @param callable(string,mixed,mixed):void $auditLogger The underlying audit logger
     * @param string $profile Rate limiting profile
     * @deprecated Use instance method createRateLimited() instead
     */
    public static function rateLimited(
        callable $auditLogger,
        string $profile = 'default'
    ): RateLimitedAuditLogger {
        return (new self())->createRateLimited($auditLogger, $profile);
    }

    /**
     * Static method: Create a simple audit logger that logs to an array.
     *
     * @param array<array-key, mixed> $logStorage Reference to array for storing logs
     * @param bool $rateLimited Whether to apply rate limiting
     * @deprecated Use instance method createArrayLogger() instead
     *
     * @psalm-return RateLimitedAuditLogger|Closure(string, mixed, mixed):void
     */
    public static function arrayLogger(
        array &$logStorage,
        bool $rateLimited = false
    ): Closure|RateLimitedAuditLogger {
        return (new self())->createArrayLogger($logStorage, $rateLimited);
    }
}
