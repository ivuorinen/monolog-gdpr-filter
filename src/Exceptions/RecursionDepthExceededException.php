<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Exceptions;

use Throwable;

/**
 * Exception thrown when the maximum recursion depth is exceeded during processing.
 *
 * This exception is thrown when:
 * - The recursion depth limit is exceeded while processing nested structures
 * - Circular references are detected in data structures
 * - Extremely deep nesting threatens stack overflow
 * - The configured maxDepth parameter is reached
 *
 * @psalm-api
 */
class RecursionDepthExceededException extends GdprProcessorException
{
    /**
     * Create an exception for exceeded recursion depth.
     *
     * @param int $currentDepth The current recursion depth when the exception occurred
     * @param int $maxDepth The maximum allowed recursion depth
     * @param string $path The field path where the depth was exceeded
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function depthExceeded(int $currentDepth, int $maxDepth, string $path, ?Throwable $previous = null): static
    {
        $message = sprintf("Maximum recursion depth of %d exceeded (current: %d) at path '%s'", $maxDepth, $currentDepth, $path);

        return self::withContext($message, [
            'error_type' => 'depth_exceeded',
            'current_depth' => $currentDepth,
            'max_depth' => $maxDepth,
            'field_path' => $path,
            'safety_measure' => 'Processing stopped to prevent stack overflow',
        ], 0, $previous);
    }

    /**
     * Create an exception for potential circular reference detection.
     *
     * @param string $path The field path where circular reference was detected
     * @param int $currentDepth The current recursion depth
     * @param int $maxDepth The maximum allowed recursion depth
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function circularReferenceDetected(string $path, int $currentDepth, int $maxDepth, ?Throwable $previous = null): static
    {
        $message = sprintf("Potential circular reference detected at path '%s' (depth: %d/%d)", $path, $currentDepth, $maxDepth);

        return self::withContext($message, [
            'error_type' => 'circular_reference',
            'field_path' => $path,
            'current_depth' => $currentDepth,
            'max_depth' => $maxDepth,
            'safety_measure' => 'Processing stopped to prevent infinite recursion',
        ], 0, $previous);
    }

    /**
     * Create an exception for extremely deep nesting scenarios.
     *
     * @param string $dataType The type of data structure causing deep nesting
     * @param int $currentDepth The current recursion depth
     * @param int $maxDepth The maximum allowed recursion depth
     * @param string $path The field path with deep nesting
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function extremeNesting(string $dataType, int $currentDepth, int $maxDepth, string $path, ?Throwable $previous = null): static
    {
        $message = sprintf("Extremely deep nesting detected in %s at path '%s' (depth: %d/%d)", $dataType, $path, $currentDepth, $maxDepth);

        return self::withContext($message, [
            'error_type' => 'extreme_nesting',
            'data_type' => $dataType,
            'field_path' => $path,
            'current_depth' => $currentDepth,
            'max_depth' => $maxDepth,
            'suggestion' => 'Consider flattening the data structure or increasing maxDepth parameter',
        ], 0, $previous);
    }

    /**
     * Create an exception for invalid depth configuration.
     *
     * @param int $invalidDepth The invalid depth value provided
     * @param string $reason The reason why the depth is invalid
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function invalidDepthConfiguration(int $invalidDepth, string $reason, ?Throwable $previous = null): static
    {
        $message = sprintf('Invalid recursion depth configuration: %d (%s)', $invalidDepth, $reason);

        return self::withContext($message, [
            'error_type' => 'invalid_configuration',
            'invalid_depth' => $invalidDepth,
            'reason' => $reason,
            'valid_range' => 'Depth must be a positive integer between 1 and 1000',
        ], 0, $previous);
    }

    /**
     * Create an exception with recommendations for handling deep structures.
     *
     * @param int $currentDepth The current recursion depth
     * @param int $maxDepth The maximum allowed recursion depth
     * @param string $path The field path where the issue occurred
     * @param array<string> $recommendations List of recommendations
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function withRecommendations(int $currentDepth, int $maxDepth, string $path, array $recommendations, ?Throwable $previous = null): static
    {
        $message = sprintf("Recursion depth limit reached at path '%s' (depth: %d/%d)", $path, $currentDepth, $maxDepth);

        return self::withContext($message, [
            'error_type' => 'depth_with_recommendations',
            'current_depth' => $currentDepth,
            'max_depth' => $maxDepth,
            'field_path' => $path,
            'recommendations' => $recommendations,
        ], 0, $previous);
    }
}
