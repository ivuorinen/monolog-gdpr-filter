<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Strategies;

use Throwable;
use Monolog\LogRecord;
use Ivuorinen\MonologGdprFilter\Exceptions\MaskingOperationFailedException;
use Ivuorinen\MonologGdprFilter\MaskConstants as Mask;

/**
 * Data type-based masking strategy.
 *
 * Applies different masking based on the PHP data type of values.
 * Useful for applying consistent masking patterns across all values
 * of specific types (e.g., all integers, all strings).
 *
 * @api
 */
class DataTypeMaskingStrategy extends AbstractMaskingStrategy
{
    /**
     * @param array<string, string> $typeMasks Map of PHP type names to their mask values
     * @param array<string> $includePaths Optional field paths to include (empty = all paths)
     * @param array<string> $excludePaths Optional field paths to exclude
     * @param int $priority Strategy priority (default: 40)
     */
    public function __construct(
        private readonly array $typeMasks,
        private readonly array $includePaths = [],
        private readonly array $excludePaths = [],
        int $priority = 40
    ) {
        parent::__construct($priority, [
            'type_masks' => $typeMasks,
            'include_paths' => $includePaths,
            'exclude_paths' => $excludePaths,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function mask(mixed $value, string $path, LogRecord $logRecord): mixed
    {
        $type = $this->getValueType($value);
        $mask = $this->typeMasks[$type] ?? null;

        if ($mask === null) {
            return $value; // Should not happen if shouldApply was called first
        }

        try {
            return $this->applyTypeMask($value, $mask, $type);
        } catch (Throwable $throwable) {
            throw MaskingOperationFailedException::dataTypeMaskingFailed(
                $type,
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
        // Check exclude paths first
        foreach ($this->excludePaths as $excludePath) {
            if ($this->pathMatches($path, $excludePath)) {
                return false;
            }
        }

        // If include paths are specified, check them
        if ($this->includePaths !== []) {
            $included = false;
            foreach ($this->includePaths as $includePath) {
                if ($this->pathMatches($path, $includePath)) {
                    $included = true;
                    break;
                }
            }

            if (!$included) {
                return false;
            }
        }

        // Check if we have a mask for this value's type
        $type = $this->getValueType($value);
        return isset($this->typeMasks[$type]);
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function getName(): string
    {
        $typeCount = count($this->typeMasks);
        $types = implode(', ', array_keys($this->typeMasks));
        return sprintf('Data Type Masking (%d types: %s)', $typeCount, $types);
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function validate(): bool
    {
        if ($this->typeMasks === []) {
            return false;
        }

        $validTypes = ['string', 'integer', 'double', 'boolean', 'array', 'object', 'NULL', 'resource'];

        foreach ($this->typeMasks as $type => $mask) {
            if (!in_array($type, $validTypes, true)) {
                return false;
            }

            /** @psalm-suppress DocblockTypeContradiction - Runtime validation for defensive programming */
            if (!is_string($mask)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Create a strategy for common data types.
     *
     * @param array<string, string> $customMasks Additional or override masks
     * @param int $priority Strategy priority
     */
    public static function createDefault(array $customMasks = [], int $priority = 40): self
    {
        $defaultMasks = [
            'string' => Mask::MASK_STRING,
            'integer' => '999',
            'double' => '99.99',
            'boolean' => 'false',
            'array' => '[]',
            'object' => '{}',
            'NULL' => '',
        ];

        $masks = array_merge($defaultMasks, $customMasks);

        return new self($masks, [], [], $priority);
    }

    /**
     * Create a strategy that only masks sensitive data types.
     *
     * @param array<string, string> $customMasks Additional or override masks
     * @param int $priority Strategy priority
     */
    public static function createSensitiveOnly(array $customMasks = [], int $priority = 40): self
    {
        $sensitiveMasks = [
            'string' => Mask::MASK_MASKED,  // Strings often contain sensitive data
            'array' => '[]',                // Arrays might contain sensitive structured data
            'object' => '{}',               // Objects might contain sensitive data
        ];

        $masks = array_merge($sensitiveMasks, $customMasks);

        return new self($masks, [], [], $priority);
    }

    /**
     * Get a normalized type name for a value.
     *
     * @param mixed $value The value to get the type for
     * @return string The normalized type name
     */
    private function getValueType(mixed $value): string
    {
        $type = gettype($value);

        // Normalize some type names to match common usage
        return match ($type) {
            'double' => 'double', // Keep as 'double' for consistency with gettype()
            'boolean' => 'boolean',
            'integer' => 'integer',
            default => $type,
        };
    }

    /**
     * Apply type-specific masking to a value.
     *
     * @param mixed $value The original value
     * @param string $mask The mask to apply
     * @param string $type The value type for error context
     * @return mixed The masked value
     *
     * @throws MaskingOperationFailedException
     */
    private function applyTypeMask(mixed $value, string $mask, string $type): mixed
    {
        // For null values, mask should also be null or empty
        if ($value === null) {
            return $mask === '' ? null : $mask;
        }

        // Try to convert mask to appropriate type
        try {
            return match ($type) {
                'integer' => is_numeric($mask) ? (int) $mask : $mask,
                'double' => is_numeric($mask) ? (float) $mask : $mask,
                'boolean' => filter_var($mask, FILTER_VALIDATE_BOOLEAN),
                'array' => $this->parseArrayMask($mask),
                'object' => $this->parseObjectMask($mask),
                'string' => $mask,
                default => $mask,
            };
        } catch (Throwable $throwable) {
            throw MaskingOperationFailedException::dataTypeMaskingFailed(
                $type,
                $value,
                'Failed to apply type mask: ' . $throwable->getMessage(),
                $throwable
            );
        }
    }

    /**
     * Parse an array mask from string representation.
     *
     * @param string $mask The mask string
     * @return array<mixed> The parsed array
     *
     * @throws MaskingOperationFailedException
     */
    private function parseArrayMask(string $mask): array
    {
        // Handle JSON array representation
        if (str_starts_with($mask, '[') && str_ends_with($mask, ']')) {
            $decoded = json_decode($mask, true);
            if ($decoded !== null && is_array($decoded)) {
                return $decoded;
            }
        }

        // Handle simple cases
        if ($mask === '[]' || $mask === '') {
            return [];
        }

        // Try to split on commas for simple arrays
        return explode(',', trim($mask, '[]'));
    }

    /**
     * Parse an object mask from string representation.
     *
     * @param string $mask The mask string
     * @return object The parsed object
     *
     * @throws MaskingOperationFailedException
     */
    private function parseObjectMask(string $mask): object
    {
        // Handle JSON object representation
        if (str_starts_with($mask, '{') && str_ends_with($mask, '}')) {
            $decoded = json_decode($mask, false);
            if ($decoded !== null && is_object($decoded)) {
                return $decoded;
            }
        }

        // Handle simple cases
        if ($mask === '{}' || $mask === '') {
            return (object) [];
        }

        // Create a simple object with the mask as a property
        return (object) ['masked' => $mask];
    }
}
