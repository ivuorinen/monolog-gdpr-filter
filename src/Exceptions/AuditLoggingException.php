<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Exceptions;

use Throwable;

/**
 * Exception thrown when audit logging operations fail.
 *
 * This exception is thrown when:
 * - An audit logger callback throws an exception
 * - Audit log data cannot be serialized
 * - Rate-limited audit logging encounters errors
 * - Audit logger configuration is invalid
 *
 * @api
 */
class AuditLoggingException extends GdprProcessorException
{
    /**
     * Create an exception for a failed audit logging callback.
     *
     * @param string $path The field path being audited
     * @param mixed $original The original value
     * @param mixed $masked The masked value
     * @param string $reason The reason for the failure
     * @param Throwable|null $previous Previous exception for chaining
     * @psalm-suppress MoreSpecificReturnType
     */
    public static function callbackFailed(
        string $path,
        mixed $original,
        mixed $masked,
        string $reason,
        ?Throwable $previous = null
    ): static {
        $message = sprintf("Audit logging callback failed for path '%s': %s", $path, $reason);

        /** @psalm-suppress LessSpecificReturnStatement */
        return self::withContext($message, [
            'audit_type' => 'callback_failure',
            'path' => $path,
            'original_type' => gettype($original),
            'masked_type' => gettype($masked),
            'original_preview' => self::getValuePreview($original),
            'masked_preview' => self::getValuePreview($masked),
            'reason' => $reason,
        ], 0, $previous);
    }

    /**
     * Create an exception for audit data serialization failure.
     *
     * @param string $path The field path being audited
     * @param mixed $value The value that failed to serialize
     * @param string $reason The reason for the serialization failure
     * @param Throwable|null $previous Previous exception for chaining
     * @psalm-suppress MoreSpecificReturnType
     */
    public static function serializationFailed(
        string $path,
        mixed $value,
        string $reason,
        ?Throwable $previous = null
    ): static {
        $message = sprintf("Audit data serialization failed for path '%s': %s", $path, $reason);

        /** @psalm-suppress LessSpecificReturnStatement */
        return self::withContext($message, [
            'audit_type' => 'serialization_failure',
            'path' => $path,
            'value_type' => gettype($value),
            'reason' => $reason,
        ], 0, $previous);
    }

    /**
     * Create an exception for rate-limited audit logging failures.
     *
     * @param string $operationType The operation type being rate limited
     * @param int $currentRequests Current number of requests
     * @param int $maxRequests Maximum allowed requests
     * @param string $reason The reason for the failure
     * @param Throwable|null $previous Previous exception for chaining
     * @psalm-suppress MoreSpecificReturnType
     */
    public static function rateLimitingFailed(
        string $operationType,
        int $currentRequests,
        int $maxRequests,
        string $reason,
        ?Throwable $previous = null
    ): static {
        $message = sprintf("Rate-limited audit logging failed for operation '%s': %s", $operationType, $reason);

        /** @psalm-suppress LessSpecificReturnStatement */
        return self::withContext($message, [
            'audit_type' => 'rate_limiting_failure',
            'operation_type' => $operationType,
            'current_requests' => $currentRequests,
            'max_requests' => $maxRequests,
            'reason' => $reason,
        ], 0, $previous);
    }

    /**
     * Create an exception for invalid audit logger configuration.
     *
     * @param string $configurationIssue Description of the configuration issue
     * @param array<string, mixed> $config The invalid configuration
     * @param Throwable|null $previous Previous exception for chaining
     * @psalm-suppress MoreSpecificReturnType
     */
    public static function invalidConfiguration(
        string $configurationIssue,
        array $config,
        ?Throwable $previous = null
    ): static {
        $message = 'Invalid audit logger configuration: ' . $configurationIssue;

        /** @psalm-suppress LessSpecificReturnStatement */
        return self::withContext($message, [
            'audit_type' => 'configuration_error',
            'configuration_issue' => $configurationIssue,
            'config' => $config,
        ], 0, $previous);
    }

    /**
     * Create an exception for audit logger creation failure.
     *
     * @param string $loggerType The type of logger being created
     * @param string $reason The reason for the creation failure
     * @param Throwable|null $previous Previous exception for chaining
     * @psalm-suppress MoreSpecificReturnType
     */
    public static function loggerCreationFailed(
        string $loggerType,
        string $reason,
        ?Throwable $previous = null
    ): static {
        $message = sprintf("Audit logger creation failed for type '%s': %s", $loggerType, $reason);

        /** @psalm-suppress LessSpecificReturnStatement */
        return self::withContext($message, [
            'audit_type' => 'logger_creation_failure',
            'logger_type' => $loggerType,
            'reason' => $reason,
        ], 0, $previous);
    }

    /**
     * Get a safe preview of a value for logging.
     *
     * @param mixed $value The value to preview
     * @return string Safe preview string
     */
    private static function getValuePreview(mixed $value): string
    {
        if (is_string($value)) {
            return substr($value, 0, 100) . (strlen($value) > 100 ? '...' : '');
        }

        if (is_array($value) || is_object($value)) {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($json === false) {
                return '[Unable to serialize]';
            }

            return substr($json, 0, 100) . (strlen($json) > 100 ? '...' : '');
        }

        return (string) $value;
    }
}
