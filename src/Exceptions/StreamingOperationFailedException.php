<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Exceptions;

use Throwable;

/**
 * Exception thrown when streaming operations fail.
 *
 * This exception is thrown when file operations related to streaming
 * log processing fail, such as inability to open input or output files.
 *
 * @api
 */
class StreamingOperationFailedException extends GdprProcessorException
{
    /**
     * Create an exception for when an input file cannot be opened.
     *
     * @param string $filePath Path to the file that could not be opened
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function cannotOpenInputFile(string $filePath, ?Throwable $previous = null): static
    {
        return self::withContext(
            "Cannot open input file for streaming: {$filePath}",
            ['operation' => 'open_input_file', 'file' => $filePath],
            0,
            $previous
        );
    }

    /**
     * Create an exception for when an output file cannot be opened.
     *
     * @param string $filePath Path to the file that could not be opened
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function cannotOpenOutputFile(string $filePath, ?Throwable $previous = null): static
    {
        return self::withContext(
            "Cannot open output file for streaming: {$filePath}",
            ['operation' => 'open_output_file', 'file' => $filePath],
            0,
            $previous
        );
    }
}
