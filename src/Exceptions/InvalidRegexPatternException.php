<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Exceptions;

use Throwable;

/**
 * Exception thrown when a regex pattern is invalid or cannot be compiled.
 *
 * This exception is thrown when:
 * - A regex pattern has invalid syntax
 * - A regex pattern cannot be compiled by PHP's PCRE engine
 * - A regex pattern is detected as potentially vulnerable to ReDoS attacks
 * - A regex pattern compilation results in a PCRE error
 *
 * @api
 */
class InvalidRegexPatternException extends GdprProcessorException
{
    /**
     * Create an exception for an invalid regex pattern.
     *
     * @param string $pattern The invalid regex pattern
     * @param string $reason The reason why the pattern is invalid
     * @param int $pcreError Optional PCRE error code
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function forPattern(
        string $pattern,
        string $reason,
        int $pcreError = 0,
        ?Throwable $previous = null
    ): static {
        $message = sprintf("Invalid regex pattern '%s': %s", $pattern, $reason);

        if ($pcreError !== 0) {
            $pcreErrorMessage = self::getPcreErrorMessage($pcreError);
            $message .= sprintf(' (PCRE Error: %s)', $pcreErrorMessage);
        }

        return self::withContext($message, [
            'pattern' => $pattern,
            'reason' => $reason,
            'pcre_error' => $pcreError,
            'pcre_error_message' => $pcreError !== 0 ? self::getPcreErrorMessage($pcreError) : null,
        ], $pcreError, $previous);
    }

    /**
     * Create an exception for a pattern that failed compilation.
     *
     * @param string $pattern The pattern that failed to compile
     * @param int $pcreError The PCRE error code
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function compilationFailed(
        string $pattern,
        int $pcreError,
        ?Throwable $previous = null
    ): static {
        return self::forPattern($pattern, 'Pattern compilation failed', $pcreError, $previous);
    }

    /**
     * Create an exception for a pattern detected as vulnerable to ReDoS.
     *
     * @param string $pattern The potentially vulnerable pattern
     * @param string $vulnerability Description of the vulnerability
     * @param Throwable|null $previous Previous exception for chaining
     *
     * @return InvalidRegexPatternException&static
     */
    public static function redosVulnerable(
        string $pattern,
        string $vulnerability,
        ?Throwable $previous = null
    ): static {
        return self::forPattern($pattern, 'Potential ReDoS vulnerability: ' . $vulnerability, 0, $previous);
    }

    /**
     * Get a human-readable error message for a PCRE error code.
     *
     * @param int $errorCode The PCRE error code
     *
     * @return string Human-readable error message
     * @psalm-return non-empty-string
     */
    private static function getPcreErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            PREG_NO_ERROR => 'No error',
            PREG_INTERNAL_ERROR => 'Internal PCRE error',
            PREG_BACKTRACK_LIMIT_ERROR => 'Backtrack limit exceeded',
            PREG_RECURSION_LIMIT_ERROR => 'Recursion limit exceeded',
            PREG_BAD_UTF8_ERROR => 'Invalid UTF-8 data',
            PREG_BAD_UTF8_OFFSET_ERROR => 'Invalid UTF-8 offset',
            PREG_JIT_STACKLIMIT_ERROR => 'JIT stack limit exceeded',
            default => sprintf('Unknown PCRE error (code: %s)', $errorCode),
        };
    }
}
