<?php

declare(strict_types=1);

namespace Tests\Strategies;

use PHPUnit\Framework\TestCase;
use Tests\TestConstants;
use Tests\TestHelpers;
use Monolog\LogRecord;
use Monolog\Level;
use Ivuorinen\MonologGdprFilter\Strategies\ConditionalMaskingStrategy;
use Ivuorinen\MonologGdprFilter\MaskConstants;
use Ivuorinen\MonologGdprFilter\Strategies\RegexMaskingStrategy;

/**
 * Enhanced tests for ConditionalMaskingStrategy to improve coverage.
 */
final class ConditionalMaskingStrategyEnhancedTest extends TestCase
{
    use TestHelpers;

    public function testOrLogicWithMultipleConditions(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy(['/secret/' => MaskConstants::MASK_MASKED]);

        $conditions = [
            'is_error' => fn(LogRecord $record): bool => $record->level === Level::Error,
            'is_debug' => fn(LogRecord $record): bool => $record->level === Level::Debug,
        ];

        // OR logic - at least one condition must be true
        $strategy = new ConditionalMaskingStrategy($wrappedStrategy, $conditions, false);

        $errorRecord = $this->createLogRecord('Test', [], Level::Error);
        $debugRecord = $this->createLogRecord('Test', [], Level::Debug);
        $infoRecord = $this->createLogRecord('Test', [], Level::Info);

        // Should apply when at least one condition is met
        $this->assertTrue($strategy->shouldApply('secret', 'message', $errorRecord));
        $this->assertTrue($strategy->shouldApply('secret', 'message', $debugRecord));

        // Should not apply when no conditions are met
        $this->assertFalse($strategy->shouldApply('secret', 'message', $infoRecord));
    }

    public function testEmptyConditionsArray(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy(['/secret/' => MaskConstants::MASK_MASKED]);

        // Empty conditions should always apply masking
        $strategy = new ConditionalMaskingStrategy($wrappedStrategy, []);

        $logRecord = $this->createLogRecord();

        $this->assertTrue($strategy->shouldApply('secret', 'message', $logRecord));
    }

    public function testConditionThrowingExceptionInAndLogic(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy(['/secret/' => MaskConstants::MASK_MASKED]);

        $conditions = [
            'always_true' =>
            /**
             * @return true
             */
            fn(LogRecord $record): bool => true,
            'throws_exception' =>
            /**
             * @param \Monolog\LogRecord $_record Intentionally unused parameter
             * @return never
             */
            function (LogRecord $_record): never {
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
        $wrappedStrategy = new RegexMaskingStrategy(['/secret/' => MaskConstants::MASK_MASKED]);

        $conditions = [
            'throws_exception' =>
            /**
             * @param \Monolog\LogRecord $_record Intentionally unused parameter
             * @return never
             */
            function (LogRecord $_record): never {
                throw new \RuntimeException('Condition failed');
            },
            'always_true' =>
            /**
             * @return true
             */
            fn(LogRecord $record): bool => true,
        ];

        // OR logic - exception ignored, other condition can still pass
        $strategy = new ConditionalMaskingStrategy($wrappedStrategy, $conditions, false);

        $logRecord = $this->createLogRecord();

        // Should apply because at least one condition is true (exception ignored)
        $this->assertTrue($strategy->shouldApply('secret', 'message', $logRecord));
    }

    public function testGetWrappedStrategy(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy(['/secret/' => MaskConstants::MASK_MASKED]);
        $strategy = new ConditionalMaskingStrategy($wrappedStrategy, []);

        $this->assertSame($wrappedStrategy, $strategy->getWrappedStrategy());
    }

    public function testGetConditionNames(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy(['/secret/' => MaskConstants::MASK_MASKED]);

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
        $wrappedStrategy = new RegexMaskingStrategy(['/secret/' => MaskConstants::MASK_MASKED]);

        // forLevels expects level names as strings
        $strategy = ConditionalMaskingStrategy::forLevels(
            $wrappedStrategy,
            ['Error', 'Warning', 'Critical']
        );

        $errorRecord = $this->createLogRecord(TestConstants::MESSAGE_DEFAULT, [], Level::Error);
        $warningRecord = $this->createLogRecord(TestConstants::MESSAGE_DEFAULT, [], Level::Warning);
        $criticalRecord = $this->createLogRecord(TestConstants::MESSAGE_DEFAULT, [], Level::Critical);
        $infoRecord = $this->createLogRecord(TestConstants::MESSAGE_DEFAULT, [], Level::Info);

        $this->assertTrue($strategy->shouldApply('secret', 'message', $errorRecord));
        $this->assertTrue($strategy->shouldApply('secret', 'message', $warningRecord));
        $this->assertTrue($strategy->shouldApply('secret', 'message', $criticalRecord));
        $this->assertFalse($strategy->shouldApply('secret', 'message', $infoRecord));
    }

    public function testFactoryForChannelWithMultipleChannels(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy(['/secret/' => MaskConstants::MASK_MASKED]);

        $strategy = ConditionalMaskingStrategy::forChannels(
            $wrappedStrategy,
            ['security', 'audit', 'admin']
        );

        $securityRecord = $this->createLogRecord(TestConstants::MESSAGE_DEFAULT, [], Level::Info, 'security');
        $auditRecord = $this->createLogRecord(TestConstants::MESSAGE_DEFAULT, [], Level::Info, 'audit');
        $testRecord = $this->createLogRecord(TestConstants::MESSAGE_DEFAULT, [], Level::Info, 'test');

        $this->assertTrue($strategy->shouldApply('secret', 'message', $securityRecord));
        $this->assertTrue($strategy->shouldApply('secret', 'message', $auditRecord));
        $this->assertFalse($strategy->shouldApply('secret', 'message', $testRecord));
    }

    public function testFactoryForContextKeyValue(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy(['/secret/' => MaskConstants::MASK_MASKED]);

        $strategy = ConditionalMaskingStrategy::forContext(
            $wrappedStrategy,
            ['env' => 'production', 'sensitive' => true]
        );

        $prodRecord = $this->createLogRecord(TestConstants::MESSAGE_DEFAULT, ['env' => 'production', 'sensitive' => true]);
        $devRecord = $this->createLogRecord(TestConstants::MESSAGE_DEFAULT, ['env' => 'development', 'sensitive' => true]);
        $noContextRecord = $this->createLogRecord(TestConstants::MESSAGE_DEFAULT);

        $this->assertTrue($strategy->shouldApply('secret', 'message', $prodRecord));
        $this->assertFalse($strategy->shouldApply('secret', 'message', $devRecord));
        $this->assertFalse($strategy->shouldApply('secret', 'message', $noContextRecord));
    }
}
