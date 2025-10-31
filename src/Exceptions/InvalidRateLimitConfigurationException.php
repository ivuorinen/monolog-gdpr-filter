<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Exceptions;

use Throwable;

/**
 * Exception thrown when rate limiter configuration is invalid.
 *
 * This exception is thrown when:
 * - Maximum requests value is invalid
 * - Time window value is invalid
 * - Cleanup interval value is invalid
 * - Rate limiting key is invalid or contains forbidden characters
 *
 * @api
 */
class InvalidRateLimitConfigurationException extends GdprProcessorException
{
    /**
     * Create an exception for an invalid maximum requests value.
     *
     * @param int|float|string $value The invalid value
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function invalidMaxRequests(
        int|float|string $value,
        ?Throwable $previous = null
    ): static {
        $message = sprintf('Maximum requests must be a positive integer, got: %s', $value);

        return self::withContext($message, [
            'parameter' => 'max_requests',
            'value' => $value,
        ], 0, $previous);
    }

    /**
     * Create an exception for an invalid time window value.
     *
     * @param int|float|string $value The invalid value
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function invalidTimeWindow(
        int|float|string $value,
        ?Throwable $previous = null
    ): static {
        $message = sprintf(
            'Time window must be a positive integer representing seconds, got: %s',
            $value
        );

        return self::withContext($message, [
            'parameter' => 'time_window',
            'value' => $value,
        ], 0, $previous);
    }

    /**
     * Create an exception for an invalid cleanup interval.
     *
     * @param int|float|string $value The invalid value
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function invalidCleanupInterval(
        int|float|string $value,
        ?Throwable $previous = null
    ): static {
        $message = sprintf('Cleanup interval must be a positive integer, got: %s', $value);

        return self::withContext($message, [
            'parameter' => 'cleanup_interval',
            'value' => $value,
        ], 0, $previous);
    }

    /**
     * Create an exception for a time window that is too short.
     *
     * @param int $value The time window value
     * @param int $minimum The minimum allowed value
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function timeWindowTooShort(
        int $value,
        int $minimum,
        ?Throwable $previous = null
    ): static {
        $message = sprintf(
            'Time window (%d seconds) is too short, minimum is %d seconds',
            $value,
            $minimum
        );

        return self::withContext($message, [
            'parameter' => 'time_window',
            'value' => $value,
            'minimum' => $minimum,
        ], 0, $previous);
    }

    /**
     * Create an exception for a cleanup interval that is too short.
     *
     * @param int $value The cleanup interval value
     * @param int $minimum The minimum allowed value
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function cleanupIntervalTooShort(
        int $value,
        int $minimum,
        ?Throwable $previous = null
    ): static {
        $message = sprintf(
            'Cleanup interval (%d seconds) is too short, minimum is %d seconds',
            $value,
            $minimum
        );

        return self::withContext($message, [
            'parameter' => 'cleanup_interval',
            'value' => $value,
            'minimum' => $minimum,
        ], 0, $previous);
    }

    /**
     * Create an exception for an empty rate limiting key.
     *
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function emptyKey(?Throwable $previous = null): static
    {
        return self::withContext('Rate limiting key cannot be empty', [
            'parameter' => 'key',
        ], 0, $previous);
    }

    /**
     * Create an exception for a rate limiting key that is too long.
     *
     * @param string $key The key that is too long
     * @param int $maxLength The maximum allowed length
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function keyTooLong(
        string $key,
        int $maxLength,
        ?Throwable $previous = null
    ): static {
        $message = sprintf(
            'Rate limiting key length (%d) exceeds maximum (%d characters)',
            strlen($key),
            $maxLength
        );

        return self::withContext($message, [
            'parameter' => 'key',
            'key_length' => strlen($key),
            'max_length' => $maxLength,
        ], 0, $previous);
    }

    /**
     * Create an exception for a rate limiting key containing invalid characters.
     *
     * @param string $reason The reason why the key is invalid
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function invalidKeyFormat(
        string $reason,
        ?Throwable $previous = null
    ): static {
        return self::withContext($reason, [
            'parameter' => 'key',
        ], 0, $previous);
    }

    /**
     * Create an exception for a generic parameter validation failure.
     *
     * @param string $parameter The parameter name
     * @param mixed $value The invalid value
     * @param string $reason The reason why the value is invalid
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function forParameter(
        string $parameter,
        mixed $value,
        string $reason,
        ?Throwable $previous = null
    ): static {
        $message = sprintf("Invalid rate limit parameter '%s': %s", $parameter, $reason);

        return self::withContext($message, [
            'parameter' => $parameter,
            'value' => $value,
            'reason' => $reason,
        ], 0, $previous);
    }
}
