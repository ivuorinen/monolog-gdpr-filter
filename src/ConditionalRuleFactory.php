<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter;

use Ivuorinen\MonologGdprFilter\ArrayAccessor\ArrayAccessorFactory;
use Closure;
use Monolog\LogRecord;

/**
 * Factory for creating conditional masking rules.
 *
 * This class provides methods to create various types of
 * conditional rules that determine when masking should be applied.
 *
 * Can be used as an instance (for DI) or via static methods (backward compatible).
 */
final class ConditionalRuleFactory
{
    private readonly ArrayAccessorFactory $accessorFactory;

    public function __construct(?ArrayAccessorFactory $accessorFactory = null)
    {
        $this->accessorFactory = $accessorFactory ?? ArrayAccessorFactory::default();
    }

    /**
     * Create a conditional rule based on log level.
     *
     * @param array<string> $levels Log levels that should trigger masking
     *
     * @psalm-return Closure(LogRecord):bool
     */
    public static function createLevelBasedRule(array $levels): Closure
    {
        return fn(LogRecord $record): bool => in_array($record->level->name, $levels, true);
    }

    /**
     * Create a conditional rule based on context field presence.
     *
     * @param string $fieldPath Dot-notation path to check
     *
     * @psalm-return Closure(LogRecord):bool
     */
    public static function createContextFieldRule(string $fieldPath): Closure
    {
        $factory = ArrayAccessorFactory::default();
        return function (LogRecord $record) use ($fieldPath, $factory): bool {
            $accessor = $factory->create($record->context);
            return $accessor->has($fieldPath);
        };
    }

    /**
     * Create a conditional rule based on context field value.
     *
     * @param string $fieldPath Dot-notation path to check
     * @param mixed $expectedValue Expected value
     *
     * @psalm-return Closure(LogRecord):bool
     */
    public static function createContextValueRule(string $fieldPath, mixed $expectedValue): Closure
    {
        $factory = ArrayAccessorFactory::default();
        return function (LogRecord $record) use ($fieldPath, $expectedValue, $factory): bool {
            $accessor = $factory->create($record->context);
            return $accessor->get($fieldPath) === $expectedValue;
        };
    }

    /**
     * Create a conditional rule based on channel name.
     *
     * @param array<string> $channels Channel names that should trigger masking
     *
     * @psalm-return Closure(LogRecord):bool
     */
    public static function createChannelBasedRule(array $channels): Closure
    {
        return fn(LogRecord $record): bool => in_array($record->channel, $channels, true);
    }

    /**
     * Instance method: Create a context field presence rule.
     *
     * @param string $fieldPath Dot-notation path to check
     *
     * @psalm-return Closure(LogRecord):bool
     */
    public function contextFieldRule(string $fieldPath): Closure
    {
        $factory = $this->accessorFactory;
        return function (LogRecord $record) use ($fieldPath, $factory): bool {
            $accessor = $factory->create($record->context);
            return $accessor->has($fieldPath);
        };
    }

    /**
     * Instance method: Create a context field value rule.
     *
     * @param string $fieldPath Dot-notation path to check
     * @param mixed $expectedValue Expected value
     *
     * @psalm-return Closure(LogRecord):bool
     */
    public function contextValueRule(string $fieldPath, mixed $expectedValue): Closure
    {
        $factory = $this->accessorFactory;
        return function (LogRecord $record) use ($fieldPath, $expectedValue, $factory): bool {
            $accessor = $factory->create($record->context);
            return $accessor->get($fieldPath) === $expectedValue;
        };
    }
}
