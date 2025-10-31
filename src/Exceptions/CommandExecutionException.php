<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Exceptions;

use Throwable;

/**
 * Exception thrown when command execution fails.
 *
 * This exception is thrown when:
 * - Artisan commands encounter runtime errors
 * - Command input validation fails
 * - Command operations fail during execution
 * - Command result processing fails
 * - File operations within commands fail
 *
 * @api
 */
class CommandExecutionException extends GdprProcessorException
{
    /**
     * Create an exception for command input validation failure.
     *
     * @param string $commandName The command that failed
     * @param string $inputName The input parameter that failed validation
     * @param mixed $inputValue The invalid input value
     * @param string $reason The reason for validation failure
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function forInvalidInput(
        string $commandName,
        string $inputName,
        mixed $inputValue,
        string $reason,
        ?Throwable $previous = null
    ): static {
        $message = sprintf(
            "Command '%s' failed: invalid input '%s' - %s",
            $commandName,
            $inputName,
            $reason
        );

        return self::withContext($message, [
            'command_name' => $commandName,
            'input_name' => $inputName,
            'input_value' => $inputValue,
            'reason' => $reason,
            'category' => 'input_validation',
        ], 0, $previous);
    }

    /**
     * Create an exception for command operation failure.
     *
     * @param string $commandName The command that failed
     * @param string $operation The operation that failed
     * @param string $reason The reason for failure
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function forOperation(
        string $commandName,
        string $operation,
        string $reason,
        ?Throwable $previous = null
    ): static {
        $message = sprintf(
            "Command '%s' failed during operation '%s': %s",
            $commandName,
            $operation,
            $reason
        );

        return self::withContext($message, [
            'command_name' => $commandName,
            'operation' => $operation,
            'reason' => $reason,
            'category' => 'operation_failure',
        ], 0, $previous);
    }

    /**
     * Create an exception for pattern testing failure.
     *
     * @param string $pattern The pattern that failed testing
     * @param string $testString The test string used
     * @param string $reason The reason for test failure
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function forPatternTest(
        string $pattern,
        string $testString,
        string $reason,
        ?Throwable $previous = null
    ): static {
        $message = sprintf("Pattern test failed for '%s': %s", $pattern, $reason);

        return self::withContext($message, [
            'pattern' => $pattern,
            'test_string' => $testString,
            'reason' => $reason,
            'category' => 'pattern_test',
        ], 0, $previous);
    }

    /**
     * Create an exception for JSON processing failure in commands.
     *
     * @param string $commandName The command that failed
     * @param string $jsonData The JSON data being processed
     * @param string $reason The reason for JSON processing failure
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function forJsonProcessing(
        string $commandName,
        string $jsonData,
        string $reason,
        ?Throwable $previous = null
    ): static {
        $message = sprintf(
            "Command '%s' failed to process JSON data: %s",
            $commandName,
            $reason
        );

        return self::withContext($message, [
            'command_name' => $commandName,
            'json_data' => $jsonData,
            'reason' => $reason,
            'category' => 'json_processing',
        ], 0, $previous);
    }
}
