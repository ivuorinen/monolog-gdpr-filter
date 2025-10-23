<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter;

/**
 * Handles recursive processing operations for GDPR masking.
 *
 * This class extracts recursive data processing logic from GdprProcessor
 * to reduce the main class's method count and improve separation of concerns.
 *
 * @internal This class is for internal use within the GDPR processor
 */
class RecursiveProcessor
{
    /**
     * @param \Closure(string):string $regexProcessor Function to process strings with regex
     * @param DataTypeMasker $dataTypeMasker Data type masker instance
     * @param callable(string,mixed,mixed):void|null $auditLogger Optional audit logger callback
     * @param int $maxDepth Maximum recursion depth for nested structures
     */
    public function __construct(
        private readonly \Closure $regexProcessor,
        private readonly DataTypeMasker $dataTypeMasker,
        private $auditLogger,
        private readonly int $maxDepth
    ) {
    }

    /**
     * Recursively mask all string values in an array using regex patterns with depth limiting
     * and memory-efficient processing for large nested structures.
     *
     * @param array<mixed>|string $data
     * @param int $currentDepth Current recursion depth
     * @return array<mixed>|string
     */
    public function recursiveMask(array|string $data, int $currentDepth = 0): array|string
    {
        if (is_string($data)) {
            return ($this->regexProcessor)($data);
        }

        // At this point, we know it's an array due to the string check above
        return $this->processArrayData($data, $currentDepth);
    }

    /**
     * Process array data with depth and size checks.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public function processArrayData(array $data, int $currentDepth): array
    {
        // Prevent excessive recursion depth
        if ($currentDepth >= $this->maxDepth) {
            if ($this->auditLogger !== null) {
                ($this->auditLogger)(
                    'max_depth_reached',
                    $currentDepth,
                    sprintf('Recursion depth limit (%d) reached', $this->maxDepth)
                );
            }

            return $data; // Return unmodified data when depth limit is reached
        }

        // Early return for empty arrays to save processing
        if ($data === []) {
            return $data;
        }

        // Memory-efficient processing: process in chunks for very large arrays
        $arraySize = count($data);
        $chunkSize = 1000; // Process in chunks of 1000 items

        return $arraySize > $chunkSize
            ? $this->processLargeArray($data, $currentDepth, $chunkSize)
            : $this->processStandardArray($data, $currentDepth);
    }

    /**
     * Process a large array in chunks to reduce memory pressure.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public function processLargeArray(array $data, int $currentDepth, int $chunkSize): array
    {
        $result = [];
        $chunks = array_chunk($data, $chunkSize, true);
        $arraySize = count($data);

        foreach ($chunks as $chunk) {
            foreach ($chunk as $key => $value) {
                $result[$key] = $this->processValue($value, $currentDepth);
            }

            // Optional: Force garbage collection after each chunk for memory management
            if ($arraySize > 10000) {
                gc_collect_cycles();
            }
        }

        return $result;
    }

    /**
     * Process a standard-sized array.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public function processStandardArray(array $data, int $currentDepth): array
    {
        foreach ($data as $key => $value) {
            $data[$key] = $this->processValue($value, $currentDepth);
        }

        return $data;
    }

    /**
     * Process a single value (string, array, or other type).
     */
    public function processValue(mixed $value, int $currentDepth): mixed
    {
        if (is_string($value)) {
            return $this->processStringValue($value);
        }

        if (is_array($value)) {
            return $this->processArrayValue($value, $currentDepth);
        }

        // For other non-strings: apply data type masking if configured
        return $this->dataTypeMasker->applyMasking($value, $this->recursiveMask(...));
    }

    /**
     * Process a string value with regex and data type masking.
     */
    public function processStringValue(string $value): string
    {
        // For strings: apply regex patterns first, then data type masking if unchanged
        $regexResult = ($this->regexProcessor)($value);

        return $regexResult !== $value
            ? $regexResult  // Regex patterns matched and changed the value
            : $this->dataTypeMasker->applyMasking($value, $this->recursiveMask(...)); // Apply data type masking
    }

    /**
     * Process an array value with masking and recursion.
     *
     * @param array<mixed> $value
     * @return array<mixed>
     */
    public function processArrayValue(array $value, int $currentDepth): array
    {
        // For arrays: apply data type masking if configured, otherwise recurse
        $masked = $this->dataTypeMasker->applyMasking($value, $this->recursiveMask(...));

        return $masked !== $value
            ? $masked  // Data type masking was applied
            : $this->recursiveMask($value, $currentDepth + 1); // Continue recursion
    }

    /**
     * Set the audit logger callable.
     *
     * @param callable(string,mixed,mixed):void|null $auditLogger
     */
    public function setAuditLogger(?callable $auditLogger): void
    {
        $this->auditLogger = $auditLogger;
    }
}
