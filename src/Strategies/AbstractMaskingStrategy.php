<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Strategies;

use Monolog\LogRecord;
use Ivuorinen\MonologGdprFilter\Exceptions\MaskingOperationFailedException;

/**
 * Abstract base class for masking strategies.
 *
 * Provides common functionality and utilities that most masking strategies
 * will need, reducing code duplication and ensuring consistent behavior.
 *
 * @api
 */
abstract class AbstractMaskingStrategy implements MaskingStrategyInterface
{
    /**
     * Constructor.
     *
     * @param int $priority The priority of the strategy
     * @param array $configuration The configuration for the strategy
     */
    public function __construct(
        protected readonly int $priority = 50,
        protected readonly array $configuration = []
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfiguration(): array
    {
        return $this->configuration;
    }

    /**
     * {@inheritdoc}
     */
    public function validate(): bool
    {
        // Base validation - can be overridden by concrete implementations
        return true;
    }

    /**
     * Safely convert a value to string for processing.
     *
     * @param mixed $value The value to convert
     * @return string The string representation
     *
     * @throws MaskingOperationFailedException If value cannot be converted to string
     */
    protected function valueToString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_numeric($value) || is_bool($value)) {
            return (string) $value;
        }

        if (is_array($value) || is_object($value)) {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw MaskingOperationFailedException::dataTypeMaskingFailed(
                    gettype($value),
                    $value,
                    'Cannot convert value to string for masking'
                );
            }

            return $json;
        }

        if ($value === null) {
            return '';
        }

        throw MaskingOperationFailedException::dataTypeMaskingFailed(
            gettype($value),
            $value,
            'Unsupported value type for string conversion'
        );
    }

    /**
     * Check if a field path matches a given pattern.
     *
     * Supports simple wildcard matching with * and exact matches.
     *
     * @param string $path The field path to check
     * @param string $pattern The pattern to match against (supports * wildcards)
     * @return bool True if the path matches the pattern
     */
    protected function pathMatches(string $path, string $pattern): bool
    {
        // Exact match
        if ($path === $pattern) {
            return true;
        }

        // Wildcard match
        if (str_contains($pattern, '*')) {
            // Escape dots and replace * with .*
            $regexPattern = '/^' . str_replace(['\\', '.', '*'], ['\\\\', '\\.', '.*'], $pattern) . '$/';
            return preg_match($regexPattern, $path) === 1;
        }

        return false;
    }

    /**
     * Check if the log record matches specific conditions.
     *
     * @param LogRecord $logRecord The log record to check
     * @param array<string, mixed> $conditions Conditions to check (level, channel, etc.)
     * @return bool True if all conditions are met
     */
    protected function recordMatches(LogRecord $logRecord, array $conditions): bool
    {
        foreach ($conditions as $field => $expectedValue) {
            $actualValue = match ($field) {
                'level' => $logRecord->level->name,
                'channel' => $logRecord->channel,
                'message' => $logRecord->message,
                default => $logRecord->context[$field] ?? null,
            };

            if ($actualValue !== $expectedValue) {
                return false;
            }
        }

        return true;
    }

    /**
     * Generate a preview of a value for error messages.
     *
     * @param mixed $value The value to preview
     * @param int $maxLength Maximum length of the preview
     * @return string Safe preview string
     */
    protected function generateValuePreview(mixed $value, int $maxLength = 100): string
    {
        try {
            $stringValue = $this->valueToString($value);
            return strlen($stringValue) > $maxLength
                ? substr($stringValue, 0, $maxLength) . '...'
                : $stringValue;
        } catch (MaskingOperationFailedException) {
            return '[' . gettype($value) . ']';
        }
    }

    /**
     * Create a masked value while preserving the original type when possible.
     *
     * @param mixed $originalValue The original value
     * @param string $maskedString The masked string representation
     * @return mixed The masked value with appropriate type
     */
    protected function preserveValueType(mixed $originalValue, string $maskedString): mixed
    {
        // If original was a string, return string
        if (is_string($originalValue)) {
            return $maskedString;
        }

        // For arrays and objects, try to decode back if it was JSON
        if (is_array($originalValue) || is_object($originalValue)) {
            $decoded = json_decode($maskedString, true);
            if ($decoded !== null && json_last_error() === JSON_ERROR_NONE) {
                return is_object($originalValue) ? (object) $decoded : $decoded;
            }
        }

        // For primitives, try to convert back
        if (is_int($originalValue) && is_numeric($maskedString)) {
            return (int) $maskedString;
        }

        if (is_float($originalValue) && is_numeric($maskedString)) {
            return (float) $maskedString;
        }

        if (is_bool($originalValue)) {
            return filter_var($maskedString, FILTER_VALIDATE_BOOLEAN);
        }

        // Default to returning the masked string
        return $maskedString;
    }
}
