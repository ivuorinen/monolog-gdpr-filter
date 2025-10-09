<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Strategies;

use Throwable;
use Monolog\LogRecord;
use Ivuorinen\MonologGdprFilter\FieldMaskConfig;
use Ivuorinen\MonologGdprFilter\Exceptions\MaskingOperationFailedException;

/**
 * Field path-based masking strategy.
 *
 * Applies masking based on specific field paths using dot notation.
 * Supports static replacements, regex patterns, and removal of fields.
 *
 * @api
 */
class FieldPathMaskingStrategy extends AbstractMaskingStrategy
{
    /**
     * @param array<string, FieldMaskConfig|string> $fieldConfigs Field path => config mappings
     * @param int $priority Strategy priority (default: 80)
     */
    public function __construct(
        private readonly array $fieldConfigs,
        int $priority = 80
    ) {
        parent::__construct($priority, [
            'field_configs' => array_map(
                /**
                 * @return (null|string)[]|string
                 *
                 * @psalm-return array{type: string, replacement: null|string}|string
                 */
                fn(FieldMaskConfig|string $config): array|string => $config instanceof FieldMaskConfig
                    ? $config->toArray() : $config,
                $fieldConfigs
            ),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function mask(mixed $value, string $path, LogRecord $logRecord): mixed
    {
        $config = $this->getConfigForPath($path);

        if ($config === null) {
            return $value; // Should not happen if shouldApply was called first
        }

        try {
            return $this->applyFieldConfig($value, $config, $path);
        } catch (Throwable $throwable) {
            throw MaskingOperationFailedException::fieldPathMaskingFailed(
                $path,
                $value,
                $throwable->getMessage(),
                $throwable
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function shouldApply(mixed $value, string $path, LogRecord $logRecord): bool
    {
        return $this->getConfigForPath($path) !== null;
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function getName(): string
    {
        $configCount = count($this->fieldConfigs);
        return sprintf('Field Path Masking (%d fields)', $configCount);
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function validate(): bool
    {
        if ($this->fieldConfigs === []) {
            return false;
        }

        // Validate each configuration
        foreach ($this->fieldConfigs as $path => $config) {
            /** @psalm-suppress DocblockTypeContradiction - Runtime validation for defensive programming */
            if (!is_string($path) || ($path === '' || $path === '0')) {
                return false;
            }

            /** @psalm-suppress DocblockTypeContradiction - Runtime validation for defensive programming */
            if (!($config instanceof FieldMaskConfig) && !is_string($config)) {
                return false;
            }

            // Validate regex patterns in FieldMaskConfig
            if ($config instanceof FieldMaskConfig && $config->hasRegexPattern()) {
                try {
                    $pattern = $config->getRegexPattern();
                    if ($pattern === null) {
                        return false;
                    }

                    /** @psalm-suppress ArgumentTypeCoercion - Pattern checked for null above */
                    $testResult = @preg_match($pattern, '');
                    if ($testResult === false) {
                        return false;
                    }
                } catch (Throwable) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Get the configuration that applies to a given field path.
     *
     * @param string $path The field path to check
     * @return FieldMaskConfig|string|null The configuration or null if no match
     */
    private function getConfigForPath(string $path): FieldMaskConfig|string|null
    {
        // First try exact matches
        if (isset($this->fieldConfigs[$path])) {
            return $this->fieldConfigs[$path];
        }

        // Then try pattern matches
        foreach ($this->fieldConfigs as $configPath => $config) {
            if ($this->pathMatches($path, $configPath)) {
                return $config;
            }
        }

        return null;
    }

    /**
     * Apply field configuration to a value.
     *
     * @param mixed $value The value to mask
     * @param FieldMaskConfig|string $config The masking configuration
     * @param string $path The field path for error context
     * @return mixed The masked value
     *
     * @throws MaskingOperationFailedException
     */
    private function applyFieldConfig(mixed $value, FieldMaskConfig|string $config, string $path): mixed
    {
        // Simple string replacement
        if (is_string($config)) {
            return $config;
        }

        // FieldMaskConfig handling
        return $this->applyFieldMaskConfig($value, $config, $path);
    }

    /**
     * Apply a FieldMaskConfig to a value.
     *
     * @param mixed $value The value to mask
     * @param FieldMaskConfig $config The mask configuration
     * @param string $path The field path for error context
     * @return mixed The masked value
     *
     * @throws MaskingOperationFailedException
     */
    private function applyFieldMaskConfig(mixed $value, FieldMaskConfig $config, string $path): mixed
    {
        // Handle removal
        if ($config->shouldRemove()) {
            return null; // This will be handled by the processor to remove the field
        }

        // Handle regex masking
        if ($config->hasRegexPattern()) {
            try {
                $stringValue = $this->valueToString($value);
                $pattern = $config->getRegexPattern();

                if ($pattern === null) {
                    throw MaskingOperationFailedException::fieldPathMaskingFailed(
                        $path,
                        $value,
                        'Regex pattern is null'
                    );
                }

                $replacement = $config->getReplacement() ?? '***MASKED***';

                /** @psalm-suppress ArgumentTypeCoercion - Pattern validated during construction */
                $result = preg_replace($pattern, $replacement, $stringValue);
                if ($result === null) {
                    throw MaskingOperationFailedException::fieldPathMaskingFailed(
                        $path,
                        $value,
                        'Regex replacement failed'
                    );
                }

                return $this->preserveValueType($value, $result);
            } catch (MaskingOperationFailedException $e) {
                throw $e;
            } catch (Throwable $e) {
                throw MaskingOperationFailedException::fieldPathMaskingFailed(
                    $path,
                    $value,
                    'Regex processing failed: ' . $e->getMessage(),
                    $e
                );
            }
        }

        // Handle static replacement
        $replacement = $config->getReplacement();
        if ($replacement !== null) {
            // Try to preserve type if the replacement can be converted
            if (is_int($value) && is_numeric($replacement)) {
                return (int) $replacement;
            }

            if (is_float($value) && is_numeric($replacement)) {
                return (float) $replacement;
            }

            if (is_bool($value)) {
                return filter_var($replacement, FILTER_VALIDATE_BOOLEAN);
            }

            return $replacement;
        }

        // If no specific action, return original value
        return $value;
    }
}
