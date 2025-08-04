<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Strategies;

use Ivuorinen\MonologGdprFilter\Exceptions\MaskingOperationFailedException;
use Ivuorinen\MonologGdprFilter\Exceptions\GdprProcessorException;
use Monolog\LogRecord;

/**
 * Interface for implementing custom masking strategies.
 *
 * This interface allows for pluggable masking approaches, enabling users to
 * create custom masking logic while maintaining consistency with the library's
 * architecture.
 *
 * @api
 */
interface MaskingStrategyInterface
{
    /**
     * Apply masking to a given value.
     *
     * @param mixed $value The value to be masked
     * @param string $path The field path (dot notation) where the value was found
     * @param LogRecord $logRecord The complete log record for context
     * @return mixed The masked value
     *
     * @throws MaskingOperationFailedException
     */
    public function mask(mixed $value, string $path, LogRecord $logRecord): mixed;

    /**
     * Determine if this strategy should be applied to the given value/context.
     *
     * @param mixed $value The value to potentially mask
     * @param string $path The field path (dot notation) where the value was found
     * @param LogRecord $logRecord The complete log record for context
     * @return bool True if this strategy should be applied, false otherwise
     */
    public function shouldApply(mixed $value, string $path, LogRecord $logRecord): bool;

    /**
     * Get a human-readable name for this masking strategy.
     *
     * @return string The strategy name (e.g., "Regex Pattern", "Credit Card", "Email")
     */
    public function getName(): string;

    /**
     * Get the priority of this strategy (higher number = higher priority).
     *
     * When multiple strategies match, the one with highest priority is used.
     * Built-in strategies use priorities in the range 0-100.
     *
     * @return int The priority level (0-1000, where 1000 is highest priority)
     */
    public function getPriority(): int;

    /**
     * Get configuration options for this strategy.
     *
     * This can be used by management interfaces to display strategy settings
     * or for serialization/deserialization of strategy configurations.
     *
     * @return array<string, mixed> Configuration options as key-value pairs
     */
    public function getConfiguration(): array;

    /**
     * Validate the strategy configuration and dependencies.
     *
     * This method should check that the strategy is properly configured
     * and can function correctly with the current environment.
     *
     * @return bool True if the strategy is valid and ready to use
     *
     * @throws GdprProcessorException
     */
    public function validate(): bool;
}
