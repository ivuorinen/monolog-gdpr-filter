<?php

declare(strict_types=1);

namespace Tests\Strategies;

use Ivuorinen\MonologGdprFilter\Strategies\MaskingStrategyInterface;
use Monolog\LogRecord;
use Ivuorinen\MonologGdprFilter\Strategies\ConditionalMaskingStrategy;
use Ivuorinen\MonologGdprFilter\Strategies\RegexMaskingStrategy;
use Ivuorinen\MonologGdprFilter\Exceptions\MaskingOperationFailedException;
use Ivuorinen\MonologGdprFilter\MaskConstants as Mask;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\TestConstants;
use Tests\TestHelpers;

#[CoversClass(ConditionalMaskingStrategy::class)]
final class ConditionalMaskingStrategyComprehensiveTest extends TestCase
{
    use TestHelpers;

    public function testMaskDelegatesToWrappedStrategy(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy([TestConstants::PATTERN_SECRET => Mask::MASK_REDACTED]);

        $conditions = [
            'always_true' => fn($record): true => true,
        ];

        $strategy = new ConditionalMaskingStrategy($wrappedStrategy, $conditions);

        $record = $this->createLogRecord('Test');

        $result = $strategy->mask('This is secret data', 'field', $record);

        $this->assertStringContainsString(Mask::MASK_REDACTED, $result);
        $this->assertStringNotContainsString('secret', $result);
    }

    public function testMaskThrowsWhenWrappedStrategyThrows(): void
    {
        // Create a mock strategy that always throws
        $wrappedStrategy = new class implements MaskingStrategyInterface {
            public function mask(mixed $value, string $path, LogRecord $logRecord): mixed
            {
                throw new MaskingOperationFailedException('Wrapped strategy failed');
            }

            public function shouldApply(mixed $value, string $path, LogRecord $logRecord): bool
            {
                return true;
            }

            public function getPriority(): int
            {
                return 50;
            }

            public function getName(): string
            {
                return 'Test Strategy';
            }

            public function validate(): bool
            {
                return true;
            }

            public function getConfiguration(): array
            {
                return [];
            }
        };

        $strategy = new ConditionalMaskingStrategy($wrappedStrategy, ['test' => fn($r): true => true]);

        $record = $this->createLogRecord('Test');

        $this->expectException(MaskingOperationFailedException::class);
        $this->expectExceptionMessage('Conditional masking failed');

        $strategy->mask('value', 'field', $record);
    }

    public function testShouldApplyReturnsFalseWhenConditionsNotMet(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy([TestConstants::PATTERN_SECRET => Mask::MASK_REDACTED]);

        $conditions = [
            'always_false' => fn($record): false => false,
        ];

        $strategy = new ConditionalMaskingStrategy($wrappedStrategy, $conditions);

        $record = $this->createLogRecord('Test');

        // Even though pattern matches, conditions not met
        $this->assertFalse($strategy->shouldApply('secret', 'field', $record));
    }

    public function testShouldApplyChecksWrappedStrategyWhenConditionsMet(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy([TestConstants::PATTERN_DIGITS => 'NUM']);

        $conditions = [
            'always_true' => fn($record): true => true,
        ];

        $strategy = new ConditionalMaskingStrategy($wrappedStrategy, $conditions);

        $record = $this->createLogRecord('Test');

        // Conditions met and pattern matches
        $this->assertTrue($strategy->shouldApply('Value: 123', 'field', $record));

        // Conditions met but pattern doesn't match
        $this->assertFalse($strategy->shouldApply('No numbers', 'field', $record));
    }

    public function testGetNameWithAndLogic(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy([TestConstants::PATTERN_TEST => Mask::MASK_MASKED]);

        $conditions = [
            'cond1' => fn($r): true => true,
            'cond2' => fn($r): true => true,
            'cond3' => fn($r): true => true,
        ];

        $strategy = new ConditionalMaskingStrategy($wrappedStrategy, $conditions, requireAllConditions: true);

        $name = $strategy->getName();

        $this->assertStringContainsString('3 conditions', $name);
        $this->assertStringContainsString('AND logic', $name);
        $this->assertStringContainsString('Regex Pattern Masking', $name);
    }

    public function testGetNameWithOrLogic(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy([TestConstants::PATTERN_TEST => Mask::MASK_MASKED]);

        $conditions = [
            'cond1' => fn($r): true => true,
            'cond2' => fn($r): true => true,
        ];

        $strategy = new ConditionalMaskingStrategy($wrappedStrategy, $conditions, requireAllConditions: false);

        $name = $strategy->getName();

        $this->assertStringContainsString('2 conditions', $name);
        $this->assertStringContainsString('OR logic', $name);
    }

    public function testValidateReturnsFalseForEmptyConditions(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy([TestConstants::PATTERN_TEST => Mask::MASK_MASKED]);

        $strategy = new ConditionalMaskingStrategy($wrappedStrategy, []);

        $this->assertFalse($strategy->validate());
    }

    public function testValidateReturnsFalseForNonCallableCondition(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy([TestConstants::PATTERN_TEST => Mask::MASK_MASKED]);

        $strategy = new ConditionalMaskingStrategy($wrappedStrategy, [
            'invalid' => 'not a callable',
        ]);

        $this->assertFalse($strategy->validate());
    }

    public function testValidateReturnsFalseWhenWrappedStrategyInvalid(): void
    {
        // Empty patterns make RegexMaskingStrategy invalid
        $wrappedStrategy = new RegexMaskingStrategy([]);

        $strategy = new ConditionalMaskingStrategy($wrappedStrategy, [
            'test' => fn($r): true => true,
        ]);

        $this->assertFalse($strategy->validate());
    }

    public function testValidateReturnsTrueForValidConfiguration(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy([TestConstants::PATTERN_TEST => Mask::MASK_MASKED]);

        $strategy = new ConditionalMaskingStrategy($wrappedStrategy, [
            'cond1' => fn($r): true => true,
            'cond2' => fn($r): true => true,
        ]);

        $this->assertTrue($strategy->validate());
    }

    public function testConditionsAreMetWithAllTrueAndLogic(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy([TestConstants::PATTERN_SECRET => Mask::MASK_MASKED]);

        $conditions = [
            'cond1' => fn($r): true => true,
            'cond2' => fn($r): true => true,
            'cond3' => fn($r): true => true,
        ];

        $strategy = new ConditionalMaskingStrategy($wrappedStrategy, $conditions, requireAllConditions: true);

        $record = $this->createLogRecord('Test');

        // All conditions true with AND logic
        $this->assertTrue($strategy->shouldApply('secret', 'field', $record));
    }

    public function testConditionsAreMetWithOneFalseAndLogic(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy([TestConstants::PATTERN_SECRET => Mask::MASK_MASKED]);

        $conditions = [
            'cond1' => fn($r): true => true,
            'cond2' => fn($r): false => false, // One false
            'cond3' => fn($r): true => true,
        ];

        $strategy = new ConditionalMaskingStrategy($wrappedStrategy, $conditions, requireAllConditions: true);

        $record = $this->createLogRecord('Test');

        // One false with AND logic should fail
        $this->assertFalse($strategy->shouldApply('secret', 'field', $record));
    }

    public function testConditionsAreMetWithAllFalseOrLogic(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy([TestConstants::PATTERN_SECRET => Mask::MASK_MASKED]);

        $conditions = [
            'cond1' => fn($r): false => false,
            'cond2' => fn($r): false => false,
            'cond3' => fn($r): false => false,
        ];

        $strategy = new ConditionalMaskingStrategy($wrappedStrategy, $conditions, requireAllConditions: false);

        $record = $this->createLogRecord('Test');

        // All false with OR logic should fail
        $this->assertFalse($strategy->shouldApply('secret', 'field', $record));
    }

    public function testConditionsAreMetWithOneTrueOrLogic(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy([TestConstants::PATTERN_SECRET => Mask::MASK_MASKED]);

        $conditions = [
            'cond1' => fn($r): false => false,
            'cond2' => fn($r): true => true, // One true
            'cond3' => fn($r): false => false,
        ];

        $strategy = new ConditionalMaskingStrategy($wrappedStrategy, $conditions, requireAllConditions: false);

        $record = $this->createLogRecord('Test');

        // One true with OR logic should succeed
        $this->assertTrue($strategy->shouldApply('secret', 'field', $record));
    }

    public function testForLevelsFactoryWithCustomPriority(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy([TestConstants::PATTERN_TEST => Mask::MASK_MASKED]);

        $strategy = ConditionalMaskingStrategy::forLevels($wrappedStrategy, ['Error'], priority: 85);

        $this->assertSame(85, $strategy->getPriority());
    }

    public function testForChannelsFactoryWithCustomPriority(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy([TestConstants::PATTERN_TEST => Mask::MASK_MASKED]);

        $strategy = ConditionalMaskingStrategy::forChannels($wrappedStrategy, ['app'], priority: 90);

        $this->assertSame(90, $strategy->getPriority());
    }

    public function testForContextFactoryWithCustomPriority(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy([TestConstants::PATTERN_TEST => Mask::MASK_MASKED]);

        $strategy = ConditionalMaskingStrategy::forContext(
            $wrappedStrategy,
            ['key' => 'value'],
            priority: 95
        );

        $this->assertSame(95, $strategy->getPriority());
    }

    public function testGetConfiguration(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy([TestConstants::PATTERN_TEST => Mask::MASK_MASKED]);

        $conditions = [
            'cond1' => fn($r): true => true,
            'cond2' => fn($r): true => true,
        ];

        $strategy = new ConditionalMaskingStrategy($wrappedStrategy, $conditions, requireAllConditions: true);

        $config = $strategy->getConfiguration();

        $this->assertArrayHasKey('wrapped_strategy', $config);
        $this->assertArrayHasKey('conditions', $config);
        $this->assertArrayHasKey('require_all_conditions', $config);
        $this->assertSame('Regex Pattern Masking (1 patterns)', $config['wrapped_strategy']);
        $this->assertSame(['cond1', 'cond2'], $config['conditions']);
        $this->assertTrue($config['require_all_conditions']);
    }

    public function testForContextWithPartialMatch(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy([TestConstants::PATTERN_SECRET => Mask::MASK_MASKED]);

        $strategy = ConditionalMaskingStrategy::forContext(
            $wrappedStrategy,
            ['env' => 'prod', 'region' => 'us-east']
        );

        // Has env=prod but wrong region
        $record1 = $this->createLogRecord('Test', ['env' => 'prod', 'region' => 'eu-west']);
        $this->assertFalse($strategy->shouldApply('secret', 'field', $record1));

        // Has both correct values
        $record2 = $this->createLogRecord('Test', ['env' => 'prod', 'region' => 'us-east']);
        $this->assertTrue($strategy->shouldApply('secret', 'field', $record2));
    }

    public function testForContextWithMissingKey(): void
    {
        $wrappedStrategy = new RegexMaskingStrategy([TestConstants::PATTERN_SECRET => Mask::MASK_MASKED]);

        $strategy = ConditionalMaskingStrategy::forContext(
            $wrappedStrategy,
            ['required_key' => 'value']
        );

        $record = $this->createLogRecord('Test', ['other_key' => 'value']);

        $this->assertFalse($strategy->shouldApply('secret', 'field', $record));
    }
}
