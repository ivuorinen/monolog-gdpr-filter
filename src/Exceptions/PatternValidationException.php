<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Exceptions;

use Throwable;

/**
 * Exception thrown when pattern validation fails.
 *
 * This exception is thrown when:
 * - Regex patterns are invalid or malformed
 * - Pattern security validation fails
 * - Pattern syntax is incorrect
 * - Pattern validation methods encounter errors
 *
 * @api
 */
class PatternValidationException extends GdprProcessorException
{
    /**
     * Create an exception for a failed pattern validation.
     *
     * @param string $pattern The pattern that failed validation
     * @param string $reason The reason why validation failed
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function forPattern(
        string $pattern,
        string $reason,
        ?Throwable $previous = null
    ): static {
        $message = sprintf("Pattern validation failed for '%s': %s", $pattern, $reason);

        return self::withContext($message, [
            'pattern' => $pattern,
            'reason' => $reason,
        ], 0, $previous);
    }

    /**
     * Create an exception for multiple pattern validation failures.
     *
     * @param array<string, string> $failedPatterns Array of pattern => error reason
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function forMultiplePatterns(
        array $failedPatterns,
        ?Throwable $previous = null
    ): static {
        $count = count($failedPatterns);
        $message = sprintf("Pattern validation failed for %d pattern(s)", $count);

        return self::withContext($message, [
            'failed_patterns' => $failedPatterns,
            'failure_count' => $count,
        ], 0, $previous);
    }

    /**
     * Create an exception for pattern security validation failure.
     *
     * @param string $pattern The potentially unsafe pattern
     * @param string $securityReason The security concern
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function securityValidationFailed(
        string $pattern,
        string $securityReason,
        ?Throwable $previous = null
    ): static {
        $message = sprintf("Pattern security validation failed for '%s': %s", $pattern, $securityReason);

        return self::withContext($message, [
            'pattern' => $pattern,
            'security_reason' => $securityReason,
            'category' => 'security',
        ], 0, $previous);
    }

    /**
     * Create an exception for pattern syntax errors.
     *
     * @param string $pattern The pattern with syntax errors
     * @param string $syntaxError The syntax error details
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function syntaxError(
        string $pattern,
        string $syntaxError,
        ?Throwable $previous = null
    ): static {
        $message = sprintf("Pattern syntax error in '%s': %s", $pattern, $syntaxError);

        return self::withContext($message, [
            'pattern' => $pattern,
            'syntax_error' => $syntaxError,
            'category' => 'syntax',
        ], 0, $previous);
    }
}
