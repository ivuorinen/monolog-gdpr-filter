<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter;

use Ivuorinen\MonologGdprFilter\Exceptions\InvalidConfigurationException;
use Ivuorinen\MonologGdprFilter\Exceptions\InvalidRegexPatternException;

/**
 * Validates constructor parameters for GdprProcessor.
 *
 * This class is responsible for validating all input parameters
 * to ensure they meet the requirements before processing.
 */
final class InputValidator
{
    /**
     * Validate all constructor parameters for early error detection.
     *
     * @param array<string,string> $patterns
     * @param array<string,FieldMaskConfig|string> $fieldPaths
     * @param array<string,callable(mixed):string> $customCallbacks
     * @param callable(string,mixed,mixed):void|null $auditLogger
     * @param int $maxDepth
     * @param array<string,string> $dataTypeMasks
     * @param array<string,callable> $conditionalRules
     *
     * @throws InvalidConfigurationException When any parameter is invalid
     */
    public static function validateAll(
        array $patterns,
        array $fieldPaths,
        array $customCallbacks,
        mixed $auditLogger,
        int $maxDepth,
        array $dataTypeMasks,
        array $conditionalRules
    ): void {
        self::validatePatterns($patterns);
        self::validateFieldPaths($fieldPaths);
        self::validateCustomCallbacks($customCallbacks);
        self::validateAuditLogger($auditLogger);
        self::validateMaxDepth($maxDepth);
        self::validateDataTypeMasks($dataTypeMasks);
        self::validateConditionalRules($conditionalRules);
    }

    /**
     * Validate patterns array for proper structure and valid regex patterns.
     *
     * @param array<string,string> $patterns
     *
     * @throws InvalidConfigurationException When patterns are invalid
     */
    public static function validatePatterns(array $patterns): void
    {
        foreach ($patterns as $pattern => $replacement) {
            // Validate pattern key
            /** @psalm-suppress DocblockTypeContradiction - Runtime validation for defensive programming */
            if (!is_string($pattern)) {
                throw InvalidConfigurationException::invalidType(
                    'pattern',
                    'string',
                    gettype($pattern)
                );
            }

            if (trim($pattern) === '') {
                throw InvalidConfigurationException::emptyValue('pattern');
            }

            // Validate replacement value
            /** @psalm-suppress DocblockTypeContradiction - Runtime validation for defensive programming */
            if (!is_string($replacement)) {
                throw InvalidConfigurationException::invalidType(
                    'pattern replacement',
                    'string',
                    gettype($replacement)
                );
            }

            // Validate regex pattern syntax
            /** @psalm-suppress DeprecatedMethod - Internal validation use */
            if (!PatternValidator::isValid($pattern)) {
                throw InvalidRegexPatternException::forPattern(
                    $pattern,
                    'Invalid regex pattern syntax'
                );
            }
        }
    }

    /**
     * Validate field paths array for proper structure.
     *
     * @param array<string,FieldMaskConfig|string> $fieldPaths
     *
     * @throws InvalidConfigurationException When field paths are invalid
     */
    public static function validateFieldPaths(array $fieldPaths): void
    {
        foreach ($fieldPaths as $path => $config) {
            // Validate path key
            /** @psalm-suppress DocblockTypeContradiction - Runtime validation for defensive programming */
            if (!is_string($path)) {
                throw InvalidConfigurationException::invalidType(
                    'field path',
                    'string',
                    gettype($path)
                );
            }

            if (trim($path) === '') {
                throw InvalidConfigurationException::emptyValue('field path');
            }

            // Validate config value
            /** @psalm-suppress DocblockTypeContradiction - Runtime validation for defensive programming */
            if (!($config instanceof FieldMaskConfig) && !is_string($config)) {
                throw InvalidConfigurationException::invalidType(
                    'field path value',
                    'FieldMaskConfig or string',
                    gettype($config)
                );
            }

            if (is_string($config) && trim($config) === '') {
                throw InvalidConfigurationException::forFieldPath(
                    $path,
                    'Cannot have empty string value'
                );
            }
        }
    }

    /**
     * Validate custom callbacks array for proper structure.
     *
     * @param array<string,callable(mixed):string> $customCallbacks
     *
     * @throws InvalidConfigurationException When custom callbacks are invalid
     */
    public static function validateCustomCallbacks(array $customCallbacks): void
    {
        foreach ($customCallbacks as $path => $callback) {
            // Validate path key
            /** @psalm-suppress DocblockTypeContradiction - Runtime validation for defensive programming */
            if (!is_string($path)) {
                throw InvalidConfigurationException::invalidType(
                    'custom callback path',
                    'string',
                    gettype($path)
                );
            }

            if (trim($path) === '') {
                throw InvalidConfigurationException::emptyValue('custom callback path');
            }

            // Validate callback value
            if (!is_callable($callback)) {
                throw InvalidConfigurationException::forParameter(
                    'custom callback for ' . $path,
                    $callback,
                    'Must be callable'
                );
            }
        }
    }

    /**
     * Validate audit logger parameter.
     *
     * @param callable(string,mixed,mixed):void|null $auditLogger
     *
     * @throws InvalidConfigurationException When audit logger is invalid
     */
    public static function validateAuditLogger(mixed $auditLogger): void
    {
        if ($auditLogger !== null && !is_callable($auditLogger)) {
            $type = gettype($auditLogger);
            throw InvalidConfigurationException::invalidType(
                'audit logger',
                'callable or null',
                $type
            );
        }
    }

    /**
     * Validate max depth parameter for reasonable bounds.
     *
     * @throws InvalidConfigurationException When max depth is invalid
     */
    public static function validateMaxDepth(int $maxDepth): void
    {
        if ($maxDepth <= 0) {
            throw InvalidConfigurationException::forParameter(
                'max_depth',
                $maxDepth,
                'Must be a positive integer'
            );
        }

        if ($maxDepth > 1000) {
            throw InvalidConfigurationException::forParameter(
                'max_depth',
                $maxDepth,
                'Cannot exceed 1,000 for stack safety'
            );
        }
    }

    /**
     * Validate data type masks array for proper structure.
     *
     * @param array<string,string> $dataTypeMasks
     *
     * @throws InvalidConfigurationException When data type masks are invalid
     */
    public static function validateDataTypeMasks(array $dataTypeMasks): void
    {
        $validTypes = ['integer', 'double', 'string', 'boolean', 'NULL', 'array', 'object', 'resource'];

        foreach ($dataTypeMasks as $type => $mask) {
            // Validate type key
            /** @psalm-suppress DocblockTypeContradiction - Runtime validation for defensive programming */
            if (!is_string($type)) {
                $typeGot = gettype($type);
                throw InvalidConfigurationException::invalidType(
                    'data type mask key',
                    'string',
                    $typeGot
                );
            }

            if (!in_array($type, $validTypes, true)) {
                $validList = implode(', ', $validTypes);
                throw InvalidConfigurationException::forDataTypeMask(
                    $type,
                    null,
                    "Must be one of: $validList"
                );
            }

            // Validate mask value
            /** @psalm-suppress DocblockTypeContradiction - Runtime validation for defensive programming */
            if (!is_string($mask)) {
                throw InvalidConfigurationException::invalidType(
                    'data type mask value',
                    'string',
                    gettype($mask)
                );
            }

            if (trim($mask) === '') {
                throw InvalidConfigurationException::forDataTypeMask(
                    $type,
                    '',
                    'Cannot be empty'
                );
            }
        }
    }

    /**
     * Validate conditional rules array for proper structure.
     *
     * @param array<string,callable> $conditionalRules
     *
     * @throws InvalidConfigurationException When conditional rules are invalid
     */
    public static function validateConditionalRules(array $conditionalRules): void
    {
        foreach ($conditionalRules as $ruleName => $callback) {
            // Validate rule name key
            /** @psalm-suppress DocblockTypeContradiction - Runtime validation for defensive programming */
            if (!is_string($ruleName)) {
                throw InvalidConfigurationException::invalidType(
                    'conditional rule name',
                    'string',
                    gettype($ruleName)
                );
            }

            if (trim($ruleName) === '') {
                throw InvalidConfigurationException::emptyValue('conditional rule name');
            }

            // Validate callback value
            if (!is_callable($callback)) {
                throw InvalidConfigurationException::forConditionalRule(
                    $ruleName,
                    'Must have a callable callback'
                );
            }
        }
    }
}
