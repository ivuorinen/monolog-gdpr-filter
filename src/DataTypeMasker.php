<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter;

use Ivuorinen\MonologGdprFilter\MaskConstants as Mask;

/**
 * Handles data type-based masking of values.
 *
 * This class applies masking based on PHP data types
 * according to configured masking rules.
 */
final class DataTypeMasker
{
    /**
     * @param array<string,string> $dataTypeMasks Type-based masking: type => mask pattern
     * @param callable(string, mixed, mixed):void|null $auditLogger
     */
    public function __construct(
        private readonly array $dataTypeMasks,
        private $auditLogger = null
    ) {}

    /**
     * Get default data type masking configuration.
     *
     * @return string[]
     *
     * @psalm-return array{
     *     integer: '***INT***',
     *     double: '***FLOAT***',
     *     string: '***STRING***',
     *     boolean: '***BOOL***',
     *     NULL: '***NULL***',
     *     array: '***ARRAY***',
     *     object: '***OBJECT***',
     *     resource: '***RESOURCE***'
     * }
     */
    public static function getDefaultMasks(): array
    {
        return [
            'integer' => Mask::MASK_INT,
            'double' => Mask::MASK_FLOAT,
            'string' => Mask::MASK_STRING,
            'boolean' => Mask::MASK_BOOL,
            'NULL' => Mask::MASK_NULL,
            'array' => Mask::MASK_ARRAY,
            'object' => Mask::MASK_OBJECT,
            'resource' => Mask::MASK_RESOURCE,
        ];
    }

    /**
     * Apply data type-based masking to a value.
     *
     * @param mixed $value The value to mask.
     * @param (callable(array<mixed>|string, int=):(array<mixed>|string))|null $recursiveMaskCallback
     * @return mixed The masked value.
     *
     * @psalm-param mixed $value The value to mask.
     */
    public function applyMasking(mixed $value, ?callable $recursiveMaskCallback = null): mixed
    {
        if ($this->dataTypeMasks === []) {
            return $value;
        }

        $type = gettype($value);

        if (!isset($this->dataTypeMasks[$type])) {
            return $value;
        }

        $mask = $this->dataTypeMasks[$type];

        // Special handling for different types
        return match ($type) {
            'integer' => is_numeric($mask) ? (int)$mask : $mask,
            'double' => is_numeric($mask) ? (float)$mask : $mask,
            'boolean' => $this->maskBoolean($mask, $value),
            'NULL' => $mask === 'preserve' ? null : $mask,
            'array' => $this->maskArray($mask, $value, $recursiveMaskCallback),
            'object' => (object) ['masked' => $mask, 'original_class' => $value::class],
            default => $mask,
        };
    }

    /**
     * Mask a boolean value.
     */
    private function maskBoolean(string $mask, bool $value): bool|string
    {
        if ($mask === 'preserve') {
            return $value;
        }

        if ($mask === 'true') {
            return true;
        }

        if ($mask === 'false') {
            return false;
        }

        return $mask;
    }

    /**
     * Mask an array value.
     *
     * @param array<mixed> $value
     * @param (callable(array<mixed>|string, int=):(array<mixed>|string))|null $recursiveMaskCallback
     * @return array<mixed>|string
     */
    private function maskArray(string $mask, array $value, ?callable $recursiveMaskCallback): array|string
    {
        // For arrays, we can return a masked indicator or process recursively
        if ($mask === 'recursive' && $recursiveMaskCallback !== null) {
            return $recursiveMaskCallback($value, 0);
        }

        return [$mask];
    }

    /**
     * Apply data type masking to an entire context structure.
     *
     * @param array<mixed> $context
     * @param array<string> $processedFields Array of field paths already processed
     * @param string $currentPath Current dot-notation path for nested processing
     * @param (callable(array<mixed>|string, int=):(array<mixed>|string))|null $recursiveMaskCallback
     * @return array<mixed>
     */
    public function applyToContext(
        array $context,
        array $processedFields = [],
        string $currentPath = '',
        ?callable $recursiveMaskCallback = null
    ): array {
        $result = [];
        foreach ($context as $key => $value) {
            $fieldPath = $currentPath === '' ? (string)$key : $currentPath . '.' . $key;

            // Skip fields that have already been processed by field paths or custom callbacks
            if (in_array($fieldPath, $processedFields, true)) {
                $result[$key] = $value;
                continue;
            }

            $result[$key] = $this->processFieldValue(
                $value,
                $fieldPath,
                $processedFields,
                $recursiveMaskCallback
            );
        }

        return $result;
    }

    /**
     * Process a single field value, applying masking if applicable.
     *
     * @param mixed $value
     * @param string $fieldPath
     * @param array<string> $processedFields
     * @param (callable(array<mixed>|string, int=):(array<mixed>|string))|null $recursiveMaskCallback
     * @return mixed
     */
    private function processFieldValue(
        mixed $value,
        string $fieldPath,
        array $processedFields,
        ?callable $recursiveMaskCallback
    ): mixed {
        if (is_array($value)) {
            return $this->applyToContext($value, $processedFields, $fieldPath, $recursiveMaskCallback);
        }

        $type = gettype($value);
        if (!isset($this->dataTypeMasks[$type])) {
            return $value;
        }

        $masked = $this->applyMasking($value, $recursiveMaskCallback);
        if ($masked !== $value && $this->auditLogger !== null) {
            ($this->auditLogger)($fieldPath, $value, $masked);
        }

        return $masked;
    }
}
