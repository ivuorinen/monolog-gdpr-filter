<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Strategies;

use Throwable;
use Monolog\LogRecord;
use Ivuorinen\MonologGdprFilter\Exceptions\MaskingOperationFailedException;
use Ivuorinen\MonologGdprFilter\Exceptions\GdprProcessorException;

/**
 * Strategy manager for coordinating multiple masking strategies.
 *
 * Manages a collection of masking strategies, applies them in priority order,
 * and provides utilities for strategy validation and introspection.
 *
 * @api
 */
class StrategyManager
{
    /** @var array<MaskingStrategyInterface> */
    private array $strategies = [];

    /** @var array<MaskingStrategyInterface> */
    private array $sortedStrategies = [];

    private bool $needsSorting = false;

    /**
     * @param array<MaskingStrategyInterface> $strategies Initial strategies to register
     */
    public function __construct(array $strategies = [])
    {
        foreach ($strategies as $strategy) {
            $this->addStrategy($strategy);
        }
    }

    /**
     * Add a masking strategy.
     *
     * @param MaskingStrategyInterface $strategy The strategy to add
     *
     * @throws GdprProcessorException If strategy validation fails
     */
    public function addStrategy(MaskingStrategyInterface $strategy): static
    {
        if (!$strategy->validate()) {
            throw GdprProcessorException::withContext(
                'Invalid masking strategy',
                [
                    'strategy_name' => $strategy->getName(),
                    'strategy_class' => $strategy::class,
                    'configuration' => $strategy->getConfiguration(),
                ]
            );
        }

        $this->strategies[] = $strategy;
        $this->needsSorting = true;

        return $this;
    }

    /**
     * Remove a strategy by instance.
     *
     * @param MaskingStrategyInterface $strategy The strategy to remove
     * @return bool True if the strategy was found and removed
     */
    public function removeStrategy(MaskingStrategyInterface $strategy): bool
    {
        $key = array_search($strategy, $this->strategies, true);
        if ($key !== false) {
            unset($this->strategies[$key]);
            $this->strategies = array_values($this->strategies);
            $this->needsSorting = true;
            return true;
        }

        return false;
    }

    /**
     * Remove all strategies of a specific class.
     *
     * @param string $className The class name to remove
     *
     * @return int The number of strategies removed
     *
     * @psalm-return int<0, max>
     */
    public function removeStrategiesByClass(string $className): int
    {
        /** @var int<0, max> $removed */
        $removed = 0;
        $this->strategies = array_filter(
            $this->strategies,
            function (MaskingStrategyInterface $strategy) use ($className, &$removed): bool {
                if ($strategy instanceof $className) {
                    $removed++;
                    return false;
                }

                return true;
            }
        );

        if ($removed > 0) {
            $this->strategies = array_values($this->strategies);
            $this->needsSorting = true;
        }

        return $removed;
    }

    /**
     * Clear all strategies.
     */
    public function clearStrategies(): static
    {
        $this->strategies = [];
        $this->sortedStrategies = [];
        $this->needsSorting = false;
        return $this;
    }

    /**
     * Apply masking strategies to a value.
     *
     * @param mixed $value The value to mask
     * @param string $path The field path where the value was found
     * @param LogRecord $logRecord The complete log record for context
     * @return mixed The masked value
     *
     * @throws MaskingOperationFailedException
     */
    public function maskValue(mixed $value, string $path, LogRecord $logRecord): mixed
    {
        $strategies = $this->getSortedStrategies();

        if ($strategies === []) {
            return $value; // No strategies configured
        }

        // Find the first applicable strategy (highest priority)
        foreach ($strategies as $strategy) {
            if ($strategy->shouldApply($value, $path, $logRecord)) {
                try {
                    return $strategy->mask($value, $path, $logRecord);
                } catch (Throwable $e) {
                    throw MaskingOperationFailedException::customCallbackFailed(
                        $path,
                        $value,
                        sprintf(
                            "Strategy '%s' failed: %s",
                            $strategy->getName(),
                            $e->getMessage()
                        ),
                        $e
                    );
                }
            }
        }

        // No applicable strategy found
        return $value;
    }

    /**
     * Check if any strategy would apply to a given value/context.
     *
     * @param mixed $value The value to check
     * @param string $path The field path where the value was found
     * @param LogRecord $logRecord The complete log record for context
     * @return bool True if at least one strategy would apply
     */
    public function hasApplicableStrategy(mixed $value, string $path, LogRecord $logRecord): bool
    {
        foreach ($this->getSortedStrategies() as $strategy) {
            if ($strategy->shouldApply($value, $path, $logRecord)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all applicable strategies for a given value/context.
     *
     * @param mixed $value The value to check
     * @param string $path The field path where the value was found
     * @param LogRecord $logRecord The complete log record for context
     *
     * @return MaskingStrategyInterface[] Applicable strategies in priority order
     *
     * @psalm-return list<MaskingStrategyInterface>
     */
    public function getApplicableStrategies(mixed $value, string $path, LogRecord $logRecord): array
    {
        $applicable = [];
        foreach ($this->getSortedStrategies() as $strategy) {
            if ($strategy->shouldApply($value, $path, $logRecord)) {
                $applicable[] = $strategy;
            }
        }

        return $applicable;
    }

    /**
     * Get all registered strategies.
     *
     * @return array<MaskingStrategyInterface> All strategies
     */
    public function getAllStrategies(): array
    {
        return $this->strategies;
    }

    /**
     * Get strategies sorted by priority (highest first).
     *
     * @return MaskingStrategyInterface[] Sorted strategies
     *
     * @psalm-return list<MaskingStrategyInterface>
     */
    public function getSortedStrategies(): array
    {
        if ($this->needsSorting || $this->sortedStrategies === []) {
            $this->sortedStrategies = $this->strategies;
            usort($this->sortedStrategies, fn($a, $b): int => $b->getPriority() <=> $a->getPriority());
            $this->needsSorting = false;
        }

        return $this->sortedStrategies;
    }

    /**
     * Get strategy statistics.
     *
     * @return (((array|int|string)[]|int)[]|int)[]
     *
     * @psalm-return array{total_strategies: int<0, max>, strategy_types: array<string, 1|2>, priority_distribution: array{'90-100 (Critical)'?: 1|2, '80-89 (High)'?: 1|2, '60-79 (Medium-High)'?: 1|2, '40-59 (Medium)'?: 1|2, '20-39 (Low-Medium)'?: 1|2, '0-19 (Low)'?: 1|2}, strategies: list{0?: array{name: string, class: string, priority: int<min, max>, configuration: array<string, mixed>},...}}
     */
    public function getStatistics(): array
    {
        $strategies = $this->getAllStrategies();
        $stats = [
            'total_strategies' => count($strategies),
            'strategy_types' => [],
            'priority_distribution' => [],
            'strategies' => [],
        ];

        foreach ($strategies as $strategy) {
            $className = $strategy::class;
            $lastBackslashPos = strrpos($className, '\\');
            $shortName = $lastBackslashPos !== false ? substr($className, $lastBackslashPos + 1) : $className;

            // Count by type
            $stats['strategy_types'][$shortName] = ($stats['strategy_types'][$shortName] ?? 0) + 1;

            // Priority distribution
            $priority = $strategy->getPriority();
            $priorityRange = match (true) {
                $priority >= 90 => '90-100 (Critical)',
                $priority >= 80 => '80-89 (High)',
                $priority >= 60 => '60-79 (Medium-High)',
                $priority >= 40 => '40-59 (Medium)',
                $priority >= 20 => '20-39 (Low-Medium)',
                default => '0-19 (Low)',
            };
            $stats['priority_distribution'][$priorityRange] = (
                $stats['priority_distribution'][$priorityRange] ?? 0
            ) + 1;

            // Individual strategy info
            $stats['strategies'][] = [
                'name' => $strategy->getName(),
                'class' => $shortName,
                'priority' => $priority,
                'configuration' => $strategy->getConfiguration(),
            ];
        }

        return $stats;
    }

    /**
     * Validate all registered strategies.
     *
     * @return string[]
     *
     * @psalm-return array<string, string>
     */
    public function validateAllStrategies(): array
    {
        $errors = [];

        foreach ($this->strategies as $strategy) {
            try {
                if (!$strategy->validate()) {
                    $errors[$strategy->getName()] = 'Strategy validation failed';
                }
            } catch (Throwable $e) {
                $errors[$strategy->getName()] = 'Validation error: ' . $e->getMessage();
            }
        }

        return $errors;
    }

    /**
     * Create a default strategy manager with common strategies.
     *
     * @param array<string, string> $regexPatterns Regex patterns for RegexMaskingStrategy
     * @param array<string, mixed> $fieldConfigs Field configurations for FieldPathMaskingStrategy
     * @param array<string, string> $typeMasks Type masks for DataTypeMaskingStrategy
     */
    public static function createDefault(
        array $regexPatterns = [],
        array $fieldConfigs = [],
        array $typeMasks = []
    ): self {
        $manager = new self();

        // Add regex strategy if patterns provided
        if ($regexPatterns !== []) {
            $manager->addStrategy(new RegexMaskingStrategy($regexPatterns));
        }

        // Add field path strategy if configs provided
        if ($fieldConfigs !== []) {
            $manager->addStrategy(new FieldPathMaskingStrategy($fieldConfigs));
        }

        // Add data type strategy if masks provided
        if ($typeMasks !== []) {
            $manager->addStrategy(new DataTypeMaskingStrategy($typeMasks));
        }

        return $manager;
    }
}
