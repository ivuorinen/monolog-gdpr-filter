<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Exceptions;

use Throwable;

/**
 * Exception thrown when rule execution fails.
 *
 * This exception is thrown when:
 * - Conditional rules fail during execution
 * - Rule callbacks throw errors
 * - Rule evaluation encounters runtime errors
 * - Custom masking logic fails
 * - Rule processing exceeds limits
 *
 * @api
 */
class RuleExecutionException extends GdprProcessorException
{
    /**
     * Create an exception for conditional rule execution failure.
     *
     * @param string $ruleName The rule that failed
     * @param string $reason The reason for failure
     * @param mixed $context Additional context about the failure
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function forConditionalRule(
        string $ruleName,
        string $reason,
        mixed $context = null,
        ?Throwable $previous = null
    ): static {
        $message = sprintf("Conditional rule '%s' execution failed: %s", $ruleName, $reason);

        $contextData = [
            'rule_name' => $ruleName,
            'reason' => $reason,
            'category' => 'conditional_rule',
        ];

        if ($context !== null) {
            $contextData['context'] = $context;
        }

        return self::withContext($message, $contextData, 0, $previous);
    }

    /**
     * Create an exception for callback execution failure.
     *
     * @param string $callbackName The callback that failed
     * @param string $fieldPath The field path being processed
     * @param string $reason The reason for failure
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function forCallback(
        string $callbackName,
        string $fieldPath,
        string $reason,
        ?Throwable $previous = null
    ): static {
        $message = sprintf(
            "Callback '%s' failed for field path '%s': %s",
            $callbackName,
            $fieldPath,
            $reason
        );

        return self::withContext($message, [
            'callback_name' => $callbackName,
            'field_path' => $fieldPath,
            'reason' => $reason,
            'category' => 'callback_execution',
        ], 0, $previous);
    }

    /**
     * Create an exception for rule timeout.
     *
     * @param string $ruleName The rule that timed out
     * @param float $timeoutSeconds The timeout threshold in seconds
     * @param float $actualTime The actual execution time
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function forTimeout(
        string $ruleName,
        float $timeoutSeconds,
        float $actualTime,
        ?Throwable $previous = null
    ): static {
        $message = sprintf(
            "Rule '%s' execution timed out after %.3f seconds (limit: %.3f seconds)",
            $ruleName,
            $actualTime,
            $timeoutSeconds
        );

        return self::withContext($message, [
            'rule_name' => $ruleName,
            'timeout_seconds' => $timeoutSeconds,
            'actual_time' => $actualTime,
            'category' => 'timeout',
        ], 0, $previous);
    }

    /**
     * Create an exception for rule evaluation error.
     *
     * @param string $ruleName The rule that failed evaluation
     * @param mixed $inputData The input data being evaluated
     * @param string $reason The reason for evaluation failure
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function forEvaluation(
        string $ruleName,
        mixed $inputData,
        string $reason,
        ?Throwable $previous = null
    ): static {
        $message = sprintf("Rule '%s' evaluation failed: %s", $ruleName, $reason);

        return self::withContext($message, [
            'rule_name' => $ruleName,
            'input_data' => $inputData,
            'reason' => $reason,
            'category' => 'evaluation',
        ], 0, $previous);
    }
}
