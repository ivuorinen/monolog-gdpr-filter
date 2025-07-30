<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Strategies;

use Throwable;
use Monolog\LogRecord;
use Ivuorinen\MonologGdprFilter\Exceptions\MaskingOperationFailedException;

/**
 * Conditional masking strategy.
 *
 * Applies masking only when specific conditions are met, such as log level,
 * channel, or custom context-based rules. This allows for fine-grained
 * control over when masking should occur.
 *
 * @psalm-api
 */
class ConditionalMaskingStrategy extends AbstractMaskingStrategy
{
    /**
     * @param MaskingStrategyInterface $wrappedStrategy The strategy to apply when conditions are met
     * @param array<string, callable(LogRecord): bool> $conditions Named conditions that must be satisfied
     * @param bool $requireAllConditions Whether all conditions must be true (AND) or just one (OR)
     * @param int $priority Strategy priority (default: 70)
     */
    public function __construct(
        private readonly MaskingStrategyInterface $wrappedStrategy,
        private readonly array $conditions,
        private readonly bool $requireAllConditions = true,
        int $priority = 70
    ) {
        parent::__construct($priority, [
            'wrapped_strategy' => $wrappedStrategy->getName(),
            'conditions' => array_keys($conditions),
            'require_all_conditions' => $requireAllConditions,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function mask(mixed $value, string $path, LogRecord $logRecord): mixed
    {
        // This should only be called if shouldApply returned true
        try {
            return $this->wrappedStrategy->mask($value, $path, $logRecord);
        } catch (Throwable $throwable) {
            throw MaskingOperationFailedException::customCallbackFailed(
                $path,
                $value,
                'Conditional masking failed: ' . $throwable->getMessage(),
                $throwable
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function shouldApply(mixed $value, string $path, LogRecord $logRecord): bool
    {
        // First check if conditions are met
        if (!$this->conditionsAreMet($logRecord)) {
            return false;
        }

        // Then check if the wrapped strategy should apply
        return $this->wrappedStrategy->shouldApply($value, $path, $logRecord);
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        $conditionCount = count($this->conditions);
        $logic = $this->requireAllConditions ? 'AND' : 'OR';
        return sprintf('Conditional Masking (%d conditions, %s logic) -> %s', $conditionCount, $logic, $this->wrappedStrategy->getName());
    }

    /**
     * {@inheritdoc}
     */
    public function validate(): bool
    {
        if ($this->conditions === []) {
            return false;
        }

        // Validate that all conditions are callable
        foreach ($this->conditions as $condition) {
            if (!is_callable($condition)) {
                return false;
            }
        }

        // Validate the wrapped strategy
        return $this->wrappedStrategy->validate();
    }

    /**
     * Get the wrapped strategy.
     *
     * @return MaskingStrategyInterface The wrapped strategy
     */
    public function getWrappedStrategy(): MaskingStrategyInterface
    {
        return $this->wrappedStrategy;
    }

    /**
     * Get the condition names.
     *
     * @return string[] The condition names
     *
     * @psalm-return list<string>
     */
    public function getConditionNames(): array
    {
        return array_keys($this->conditions);
    }

    /**
     * Check if all conditions are met for the given log record.
     *
     * @param LogRecord $logRecord The log record to evaluate
     * @return bool True if conditions are satisfied
     */
    private function conditionsAreMet(LogRecord $logRecord): bool
    {
        if ($this->conditions === []) {
            return true;
        }

        $satisfiedConditions = 0;

        foreach ($this->conditions as $condition) {
            try {
                $result = $condition($logRecord);
                if ($result === true) {
                    $satisfiedConditions++;

                    // For OR logic, one satisfied condition is enough
                    if (!$this->requireAllConditions) {
                        return true;
                    }
                }
            } catch (Throwable) {
                // If condition evaluation fails, treat as not satisfied
                if ($this->requireAllConditions) {
                    return false; // For AND logic, any failure means failure
                }

                // For OR logic, continue checking other conditions
            }
        }

        // For AND logic, all conditions must be satisfied
        if ($this->requireAllConditions) {
            return $satisfiedConditions === count($this->conditions);
        }

        // For OR logic, at least one condition must be satisfied
        return $satisfiedConditions > 0;
    }

    /**
     * Create a level-based conditional strategy.
     *
     * @param MaskingStrategyInterface $strategy The strategy to wrap
     * @param array<string> $levels The log levels that should trigger masking
     * @param int $priority Strategy priority
     */
    public static function forLevels(
        MaskingStrategyInterface $strategy,
        array $levels,
        int $priority = 70
    ): self {
        $condition = (static fn(LogRecord $logRecord): bool => in_array($logRecord->level->name, $levels, true));

        return new self($strategy, ['level' => $condition], true, $priority);
    }

    /**
     * Create a channel-based conditional strategy.
     *
     * @param MaskingStrategyInterface $strategy The strategy to wrap
     * @param array<string> $channels The channels that should trigger masking
     * @param int $priority Strategy priority
     */
    public static function forChannels(
        MaskingStrategyInterface $strategy,
        array $channels,
        int $priority = 70
    ): self {
        $condition = (static fn(LogRecord $logRecord): bool => in_array($logRecord->channel, $channels, true));

        return new self($strategy, ['channel' => $condition], true, $priority);
    }

    /**
     * Create a context-based conditional strategy.
     *
     * @param MaskingStrategyInterface $strategy The strategy to wrap
     * @param array<string, mixed> $requiredContext Context key-value pairs that must be present
     * @param int $priority Strategy priority
     */
    public static function forContext(
        MaskingStrategyInterface $strategy,
        array $requiredContext,
        int $priority = 70
    ): self {
        $condition = static function (LogRecord $logRecord) use ($requiredContext): bool {
            foreach ($requiredContext as $key => $expectedValue) {
                $actualValue = $logRecord->context[$key] ?? null;
                if ($actualValue !== $expectedValue) {
                    return false;
                }
            }

            return true;
        };

        return new self($strategy, ['context' => $condition], true, $priority);
    }
}
