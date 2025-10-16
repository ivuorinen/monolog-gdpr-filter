<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter;

use Adbar\Dot;
use Closure;
use Monolog\LogRecord;

/**
 * Factory for creating conditional masking rules.
 *
 * This class provides static methods to create various types of
 * conditional rules that determine when masking should be applied.
 */
final class ConditionalRuleFactory
{
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
        return function (LogRecord $record) use ($fieldPath): bool {
            $accessor = new Dot($record->context);
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
        return function (LogRecord $record) use ($fieldPath, $expectedValue): bool {
            $accessor = new Dot($record->context);
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
}
