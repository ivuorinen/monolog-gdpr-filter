<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Exceptions;

use Throwable;

/**
 * Exception thrown when GDPR processor configuration is invalid.
 *
 * This exception is thrown when:
 * - Invalid field paths are provided
 * - Invalid data type masks are specified
 * - Invalid conditional rules are configured
 * - Configuration values are out of acceptable ranges
 * - Configuration structure is malformed
 *
 * @api
 */
class InvalidConfigurationException extends GdprProcessorException
{
    /**
     * Create an exception for an invalid field path.
     *
     * @param string $fieldPath The invalid field path
     * @param string $reason The reason why the field path is invalid
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function forFieldPath(
        string $fieldPath,
        string $reason,
        ?Throwable $previous = null
    ): static {
        $message = sprintf("Invalid field path '%s': %s", $fieldPath, $reason);

        return self::withContext($message, [
            'field_path' => $fieldPath,
            'reason' => $reason,
        ], 0, $previous);
    }

    /**
     * Create an exception for an invalid data type mask.
     *
     * @param string $dataType The invalid data type
     * @param mixed $mask The invalid mask value
     * @param string $reason The reason why the mask is invalid
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function forDataTypeMask(
        string $dataType,
        mixed $mask,
        string $reason,
        ?Throwable $previous = null
    ): static {
        $message = sprintf("Invalid data type mask for '%s': %s", $dataType, $reason);

        return self::withContext($message, [
            'data_type' => $dataType,
            'mask' => $mask,
            'reason' => $reason,
        ], 0, $previous);
    }

    /**
     * Create an exception for an invalid conditional rule.
     *
     * @param string $ruleName The invalid rule name
     * @param string $reason The reason why the rule is invalid
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function forConditionalRule(
        string $ruleName,
        string $reason,
        ?Throwable $previous = null
    ): static {
        $message = sprintf("Invalid conditional rule '%s': %s", $ruleName, $reason);

        return self::withContext($message, [
            'rule_name' => $ruleName,
            'reason' => $reason,
        ], 0, $previous);
    }

    /**
     * Create an exception for an invalid configuration value.
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
        $message = sprintf("Invalid configuration parameter '%s': %s", $parameter, $reason);

        return self::withContext($message, [
            'parameter' => $parameter,
            'value' => $value,
            'reason' => $reason,
        ], 0, $previous);
    }

    /**
     * Create an exception for an empty or null required value.
     *
     * @param string $parameter The parameter name that cannot be empty
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function emptyValue(
        string $parameter,
        ?Throwable $previous = null
    ): static {
        $message = sprintf("%s cannot be empty", ucfirst($parameter));

        return self::withContext($message, [
            'parameter' => $parameter,
        ], 0, $previous);
    }

    /**
     * Create an exception for a value that exceeds maximum allowed length.
     *
     * @param string $parameter The parameter name
     * @param int $actualLength The actual length
     * @param int $maxLength The maximum allowed length
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function exceedsMaxLength(
        string $parameter,
        int $actualLength,
        int $maxLength,
        ?Throwable $previous = null
    ): static {
        $message = sprintf(
            "%s length (%d) exceeds maximum allowed length (%d)",
            ucfirst($parameter),
            $actualLength,
            $maxLength
        );

        return self::withContext($message, [
            'parameter' => $parameter,
            'actual_length' => $actualLength,
            'max_length' => $maxLength,
        ], 0, $previous);
    }

    /**
     * Create an exception for an invalid type.
     *
     * @param string $parameter The parameter name
     * @param string $expectedType The expected type
     * @param string $actualType The actual type
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function invalidType(
        string $parameter,
        string $expectedType,
        string $actualType,
        ?Throwable $previous = null
    ): static {
        $message = sprintf(
            "%s must be of type %s, got %s",
            ucfirst($parameter),
            $expectedType,
            $actualType
        );

        return self::withContext($message, [
            'parameter' => $parameter,
            'expected_type' => $expectedType,
            'actual_type' => $actualType,
        ], 0, $previous);
    }
}
