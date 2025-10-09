<?php

declare(strict_types=1);

namespace Tests\Strategies;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Monolog\LogRecord;
use Monolog\Level;
use Ivuorinen\MonologGdprFilter\Strategies\ConditionalMaskingStrategy;
use Ivuorinen\MonologGdprFilter\Strategies\RegexMaskingStrategy;

/**
 * Enhanced tests for ConditionalMaskingStrategy to improve coverage.
 */
final class ConditionalMaskingStrategyEnhancedTest extends TestCase
{
    /**
     * @param array<mixed> $context
     */
    private function createLogRecord(
        string $message = 'Test',
        Level $level = Level::Info,
        string $channel = 'test',
        array $context = []
    ): LogRecord {
        return new LogRecord(
            new DateTimeImmutable(),
            $channel,
            $level,
            $message,
            $context
        );
    }

    public function testOrLogicWithMultipleConditions(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy(['/secret/' => '***MASKED***']);

        $conditions = [
            'is_error' => fn(LogRecord $record): bool => $record->level === Level::Error,
            'is_debug' => fn(LogRecord $record): bool => $record->level === Level::Debug,
        ];

        // OR logic - at least one condition must be true
        $strategy = new ConditionalMaskingStrategy($wrappedStrategy, $conditions, false);

        $errorRecord = $this->createLogRecord(level: Level::Error);
        $debugRecord = $this->createLogRecord(level: Level::Debug);
        $infoRecord = $this->createLogRecord(level: Level::Info);

        // Should apply when at least one condition is met
        $this->assertTrue($strategy->shouldApply('secret', 'message', $errorRecord));
        $this->assertTrue($strategy->shouldApply('secret', 'message', $debugRecord));

        // Should not apply when no conditions are met
        $this->assertFalse($strategy->shouldApply('secret', 'message', $infoRecord));
    }

    public function testEmptyConditionsArray(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy(['/secret/' => '***MASKED***']);

        // Empty conditions should always apply masking
        $strategy = new ConditionalMaskingStrategy($wrappedStrategy, []);

        $logRecord = $this->createLogRecord();

        $this->assertTrue($strategy->shouldApply('secret', 'message', $logRecord));
    }

    public function testConditionThrowingExceptionInAndLogic(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy(['/secret/' => '***MASKED***']);

        $conditions = [
            'always_true' => fn(LogRecord $record): bool => true,
            'throws_exception' => function (LogRecord $record): bool {
                throw new \RuntimeException('Condition failed');
            },
        ];

        // AND logic - exception should cause condition to fail, masking not applied
        $strategy = new ConditionalMaskingStrategy($wrappedStrategy, $conditions, true);

        $logRecord = $this->createLogRecord();

        // Should not apply because one condition threw exception
        $this->assertFalse($strategy->shouldApply('secret', 'message', $logRecord));
    }

    public function testConditionThrowingExceptionInOrLogic(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy(['/secret/' => '***MASKED***']);

        $conditions = [
            'throws_exception' => function (LogRecord $record): bool {
                throw new \RuntimeException('Condition failed');
            },
            'always_true' => fn(LogRecord $record): bool => true,
        ];

        // OR logic - exception ignored, other condition can still pass
        $strategy = new ConditionalMaskingStrategy($wrappedStrategy, $conditions, false);

        $logRecord = $this->createLogRecord();

        // Should apply because at least one condition is true (exception ignored)
        $this->assertTrue($strategy->shouldApply('secret', 'message', $logRecord));
    }

    public function testGetWrappedStrategy(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy(['/secret/' => '***MASKED***']);
        $strategy = new ConditionalMaskingStrategy($wrappedStrategy, []);

        $this->assertSame($wrappedStrategy, $strategy->getWrappedStrategy());
    }

    public function testGetConditionNames(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy(['/secret/' => '***MASKED***']);

        $conditions = [
            'is_error' => fn(LogRecord $record): bool => $record->level === Level::Error,
            'has_context' => fn(LogRecord $record): bool => $record->context !== [],
        ];

        $strategy = new ConditionalMaskingStrategy($wrappedStrategy, $conditions);

        $names = $strategy->getConditionNames();
        $this->assertEquals(['is_error', 'has_context'], $names);
    }

    public function testFactoryForLevelWithMultipleLevels(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy(['/secret/' => '***MASKED***']);

        // forLevels expects level names as strings
        $strategy = ConditionalMaskingStrategy::forLevels(
            $wrappedStrategy,
            ['Error', 'Warning', 'Critical']
        );

        $errorRecord = $this->createLogRecord(level: Level::Error);
        $warningRecord = $this->createLogRecord(level: Level::Warning);
        $criticalRecord = $this->createLogRecord(level: Level::Critical);
        $infoRecord = $this->createLogRecord(level: Level::Info);

        $this->assertTrue($strategy->shouldApply('secret', 'message', $errorRecord));
        $this->assertTrue($strategy->shouldApply('secret', 'message', $warningRecord));
        $this->assertTrue($strategy->shouldApply('secret', 'message', $criticalRecord));
        $this->assertFalse($strategy->shouldApply('secret', 'message', $infoRecord));
    }

    public function testFactoryForChannelWithMultipleChannels(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy(['/secret/' => '***MASKED***']);

        $strategy = ConditionalMaskingStrategy::forChannels(
            $wrappedStrategy,
            ['security', 'audit', 'admin']
        );

        $securityRecord = $this->createLogRecord(channel: 'security');
        $auditRecord = $this->createLogRecord(channel: 'audit');
        $testRecord = $this->createLogRecord(channel: 'test');

        $this->assertTrue($strategy->shouldApply('secret', 'message', $securityRecord));
        $this->assertTrue($strategy->shouldApply('secret', 'message', $auditRecord));
        $this->assertFalse($strategy->shouldApply('secret', 'message', $testRecord));
    }

    public function testFactoryForContextKeyValue(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy(['/secret/' => '***MASKED***']);

        $strategy = ConditionalMaskingStrategy::forContext(
            $wrappedStrategy,
            ['env' => 'production', 'sensitive' => true]
        );

        $prodRecord = $this->createLogRecord(context: ['env' => 'production', 'sensitive' => true]);
        $devRecord = $this->createLogRecord(context: ['env' => 'development', 'sensitive' => true]);
        $noContextRecord = $this->createLogRecord();

        $this->assertTrue($strategy->shouldApply('secret', 'message', $prodRecord));
        $this->assertFalse($strategy->shouldApply('secret', 'message', $devRecord));
        $this->assertFalse($strategy->shouldApply('secret', 'message', $noContextRecord));
    }
}
