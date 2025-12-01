<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Recovery;

use Ivuorinen\MonologGdprFilter\MaskConstants;

/**
 * Provides fallback mask values for different data types and scenarios.
 *
 * Used by recovery strategies to determine appropriate masked values
 * when masking operations fail.
 *
 * @api
 */
final class FallbackMaskStrategy
{
    /**
     * @param array<string, string> $customFallbacks Custom fallback values by type
     * @param string $defaultFallback Default fallback for unknown types
     * @param bool $preserveType Whether to try preserving the original type
     */
    public function __construct(
        private readonly array $customFallbacks = [],
        private readonly string $defaultFallback = MaskConstants::MASK_MASKED,
        private readonly bool $preserveType = true,
    ) {
    }

    /**
     * Create a strategy with default fallback values.
     */
    public static function default(): self
    {
        return new self();
    }

    /**
     * Create a strict strategy that always uses the same mask.
     */
    public static function strict(string $mask = MaskConstants::MASK_REDACTED): self
    {
        return new self(
            defaultFallback: $mask,
            preserveType: false
        );
    }

    /**
     * Create a strategy with custom type mappings.
     *
     * @param array<string, string> $typeMappings Type name => fallback value
     */
    public static function withMappings(array $typeMappings): self
    {
        return new self(customFallbacks: $typeMappings);
    }

    /**
     * Get the appropriate fallback value for a given original value.
     *
     * @param mixed $originalValue The original value that couldn't be masked
     * @param FailureMode $mode The failure mode to apply
     */
    public function getFallback(
        mixed $originalValue,
        FailureMode $mode = FailureMode::FAIL_SAFE
    ): mixed {
        return match ($mode) {
            FailureMode::FAIL_OPEN => $originalValue,
            FailureMode::FAIL_CLOSED => $this->getClosedFallback(),
            FailureMode::FAIL_SAFE => $this->getSafeFallback($originalValue),
        };
    }

    /**
     * Get fallback for FAIL_CLOSED mode.
     */
    private function getClosedFallback(): string
    {
        return $this->customFallbacks['closed'] ?? MaskConstants::MASK_REDACTED;
    }

    /**
     * Get fallback for FAIL_SAFE mode (type-aware).
     */
    private function getSafeFallback(mixed $originalValue): mixed
    {
        $type = gettype($originalValue);

        // Check for custom fallback first
        if (isset($this->customFallbacks[$type])) {
            return $this->customFallbacks[$type];
        }

        // If not preserving type, return default
        if (!$this->preserveType) {
            return $this->defaultFallback;
        }

        // Return type-appropriate fallback
        return match ($type) {
            'string' => $this->getStringFallback($originalValue),
            'integer' => MaskConstants::MASK_INT,
            'double' => MaskConstants::MASK_FLOAT,
            'boolean' => MaskConstants::MASK_BOOL,
            'array' => $this->getArrayFallback($originalValue),
            'object' => $this->getObjectFallback($originalValue),
            'NULL' => MaskConstants::MASK_NULL,
            'resource', 'resource (closed)' => MaskConstants::MASK_RESOURCE,
            default => $this->defaultFallback,
        };
    }

    /**
     * Get fallback for string values.
     *
     * @param string $originalValue
     */
    private function getStringFallback(string $originalValue): string
    {
        // Try to preserve length indication
        $length = strlen($originalValue);

        if ($length <= 10) {
            return MaskConstants::MASK_STRING;
        }

        return sprintf('%s (%d chars)', MaskConstants::MASK_STRING, $length);
    }

    /**
     * Get fallback for array values.
     *
     * @param array<mixed> $originalValue
     */
    private function getArrayFallback(array $originalValue): string
    {
        $count = count($originalValue);

        if ($count === 0) {
            return MaskConstants::MASK_ARRAY;
        }

        return sprintf('%s (%d items)', MaskConstants::MASK_ARRAY, $count);
    }

    /**
     * Get fallback for object values.
     */
    private function getObjectFallback(object $originalValue): string
    {
        $class = $originalValue::class;

        // Extract just the class name without namespace
        $lastBackslash = strrpos($class, '\\');
        $shortClass = $lastBackslash !== false
            ? substr($class, $lastBackslash + 1)
            : $class;

        return sprintf('%s (%s)', MaskConstants::MASK_OBJECT, $shortClass);
    }

    /**
     * Get a description of this strategy's configuration.
     *
     * @return array<string, mixed>
     */
    public function getConfiguration(): array
    {
        return [
            'custom_fallbacks' => $this->customFallbacks,
            'default_fallback' => $this->defaultFallback,
            'preserve_type' => $this->preserveType,
        ];
    }
}
