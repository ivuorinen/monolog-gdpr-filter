<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Exceptions;

use Throwable;

/**
 * Exception thrown when a masking operation fails unexpectedly.
 *
 * This exception is thrown when:
 * - A regex replacement operation fails
 * - A field path masking operation encounters an error
 * - A custom callback masking function throws an exception
 * - Data type masking fails due to type conversion issues
 * - JSON masking fails due to malformed JSON structures
 *
 * @psalm-api
 */
class MaskingOperationFailedException extends GdprProcessorException
{
    /**
     * Create an exception for a failed regex masking operation.
     *
     * @param string $pattern The regex pattern that failed
     * @param string $input The input string being processed
     * @param string $reason The reason for the failure
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function regexMaskingFailed(string $pattern, string $input, string $reason, ?Throwable $previous = null): static
    {
        $message = sprintf("Regex masking failed for pattern '%s': %s", $pattern, $reason);

        return self::withContext($message, [
            'operation_type' => 'regex_masking',
            'pattern' => $pattern,
            'input_length' => strlen($input),
            'input_preview' => substr($input, 0, 100) . (strlen($input) > 100 ? '...' : ''),
            'reason' => $reason,
        ], 0, $previous);
    }

    /**
     * Create an exception for a failed field path masking operation.
     *
     * @param string $fieldPath The field path that failed
     * @param mixed $value The value being masked
     * @param string $reason The reason for the failure
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function fieldPathMaskingFailed(string $fieldPath, mixed $value, string $reason, ?Throwable $previous = null): static
    {
        $message = sprintf("Field path masking failed for path '%s': %s", $fieldPath, $reason);

        return self::withContext($message, [
            'operation_type' => 'field_path_masking',
            'field_path' => $fieldPath,
            'value_type' => gettype($value),
            'value_preview' => self::getValuePreview($value),
            'reason' => $reason,
        ], 0, $previous);
    }

    /**
     * Create an exception for a failed custom callback masking operation.
     *
     * @param string $fieldPath The field path with the custom callback
     * @param mixed $value The value being processed
     * @param string $reason The reason for the failure
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function customCallbackFailed(string $fieldPath, mixed $value, string $reason, ?Throwable $previous = null): static
    {
        $message = sprintf("Custom callback masking failed for path '%s': %s", $fieldPath, $reason);

        return self::withContext($message, [
            'operation_type' => 'custom_callback',
            'field_path' => $fieldPath,
            'value_type' => gettype($value),
            'value_preview' => self::getValuePreview($value),
            'reason' => $reason,
        ], 0, $previous);
    }

    /**
     * Create an exception for a failed data type masking operation.
     *
     * @param string $dataType The data type being masked
     * @param mixed $value The value being masked
     * @param string $reason The reason for the failure
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function dataTypeMaskingFailed(string $dataType, mixed $value, string $reason, ?Throwable $previous = null): static
    {
        $message = sprintf("Data type masking failed for type '%s': %s", $dataType, $reason);

        return self::withContext($message, [
            'operation_type' => 'data_type_masking',
            'expected_type' => $dataType,
            'actual_type' => gettype($value),
            'value_preview' => self::getValuePreview($value),
            'reason' => $reason,
        ], 0, $previous);
    }

    /**
     * Create an exception for a failed JSON masking operation.
     *
     * @param string $jsonString The JSON string that failed to be processed
     * @param string $reason The reason for the failure
     * @param int $jsonError Optional JSON error code
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function jsonMaskingFailed(string $jsonString, string $reason, int $jsonError = 0, ?Throwable $previous = null): static
    {
        $message = 'JSON masking failed: ' . $reason;

        if ($jsonError !== 0) {
            $jsonErrorMessage = json_last_error_msg();
            $message .= sprintf(' (JSON Error: %s)', $jsonErrorMessage);
        }

        return self::withContext($message, [
            'operation_type' => 'json_masking',
            'json_preview' => substr($jsonString, 0, 200) . (strlen($jsonString) > 200 ? '...' : ''),
            'json_length' => strlen($jsonString),
            'reason' => $reason,
            'json_error' => $jsonError,
            'json_error_message' => $jsonError !== 0 ? json_last_error_msg() : null,
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
