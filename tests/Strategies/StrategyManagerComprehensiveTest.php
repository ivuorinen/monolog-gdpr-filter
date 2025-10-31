<?php

declare(strict_types=1);

namespace Tests\Strategies;

use Ivuorinen\MonologGdprFilter\Strategies\MaskingStrategyInterface;
use Monolog\LogRecord;
use Ivuorinen\MonologGdprFilter\Strategies\StrategyManager;
use Ivuorinen\MonologGdprFilter\Strategies\RegexMaskingStrategy;
use Ivuorinen\MonologGdprFilter\Strategies\FieldPathMaskingStrategy;
use Ivuorinen\MonologGdprFilter\Strategies\DataTypeMaskingStrategy;
use Ivuorinen\MonologGdprFilter\Exceptions\GdprProcessorException;
use Ivuorinen\MonologGdprFilter\Exceptions\MaskingOperationFailedException;
use Ivuorinen\MonologGdprFilter\MaskConstants as Mask;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\TestConstants;
use Tests\TestHelpers;

#[CoversClass(StrategyManager::class)]
final class StrategyManagerComprehensiveTest extends TestCase
{
    use TestHelpers;

    public function testConstructorAcceptsInitialStrategies(): void
    {
        $strategy1 = new RegexMaskingStrategy([TestConstants::PATTERN_TEST => Mask::MASK_MASKED]);
        $strategy2 = new DataTypeMaskingStrategy(['string' => Mask::MASK_MASKED]);

        $manager = new StrategyManager([$strategy1, $strategy2]);

        $this->assertCount(2, $manager->getAllStrategies());
    }

    public function testAddStrategyThrowsOnInvalidStrategy(): void
    {
        $invalidStrategy = new RegexMaskingStrategy([]); // Empty patterns = invalid

        $this->expectException(GdprProcessorException::class);
        $this->expectExceptionMessage('Invalid masking strategy');

        $manager = new StrategyManager();
        $manager->addStrategy($invalidStrategy);
    }

    public function testAddStrategyReturnsManager(): void
    {
        $manager = new StrategyManager();
        $strategy = new RegexMaskingStrategy([TestConstants::PATTERN_TEST => Mask::MASK_MASKED]);

        $result = $manager->addStrategy($strategy);

        $this->assertSame($manager, $result);
    }

    public function testRemoveStrategyReturnsTrue(): void
    {
        $strategy = new RegexMaskingStrategy([TestConstants::PATTERN_TEST => Mask::MASK_MASKED]);
        $manager = new StrategyManager([$strategy]);

        $result = $manager->removeStrategy($strategy);

        $this->assertTrue($result);
        $this->assertCount(0, $manager->getAllStrategies());
    }

    public function testRemoveStrategyReturnsFalseWhenNotFound(): void
    {
        $strategy1 = new RegexMaskingStrategy(['/test1/' => Mask::MASK_MASKED]);
        $strategy2 = new RegexMaskingStrategy(['/test2/' => Mask::MASK_MASKED]);

        $manager = new StrategyManager([$strategy1]);

        $result = $manager->removeStrategy($strategy2);

        $this->assertFalse($result);
        $this->assertCount(1, $manager->getAllStrategies());
    }

    public function testRemoveStrategiesByClass(): void
    {
        $regex1 = new RegexMaskingStrategy(['/test1/' => 'M1']);
        $regex2 = new RegexMaskingStrategy(['/test2/' => 'M2']);
        $dataType = new DataTypeMaskingStrategy(['string' => Mask::MASK_MASKED]);

        $manager = new StrategyManager([$regex1, $regex2, $dataType]);

        $removed = $manager->removeStrategiesByClass(RegexMaskingStrategy::class);

        $this->assertSame(2, $removed);
        $this->assertCount(1, $manager->getAllStrategies());
    }

    public function testRemoveStrategiesByClassReturnsZeroWhenNoneFound(): void
    {
        $dataType = new DataTypeMaskingStrategy(['string' => Mask::MASK_MASKED]);
        $manager = new StrategyManager([$dataType]);

        $removed = $manager->removeStrategiesByClass(RegexMaskingStrategy::class);

        $this->assertSame(0, $removed);
        $this->assertCount(1, $manager->getAllStrategies());
    }

    public function testClearStrategiesRemovesAll(): void
    {
        $strategy1 = new RegexMaskingStrategy(['/test1/' => 'M1']);
        $strategy2 = new DataTypeMaskingStrategy(['string' => Mask::MASK_MASKED]);

        $manager = new StrategyManager([$strategy1, $strategy2]);

        $result = $manager->clearStrategies();

        $this->assertSame($manager, $result);
        $this->assertCount(0, $manager->getAllStrategies());
        $this->assertCount(0, $manager->getSortedStrategies());
    }

    public function testMaskValueReturnsOriginalWhenNoStrategies(): void
    {
        $manager = new StrategyManager();
        $record = $this->createLogRecord('Test');

        $result = $manager->maskValue('test value', 'field', $record);

        $this->assertSame('test value', $result);
    }

    public function testMaskValueAppliesFirstApplicableStrategy(): void
    {
        // High priority strategy
        $highPrio = new RegexMaskingStrategy([TestConstants::PATTERN_SECRET => 'HIGH'], [], [], 90);
        // Low priority strategy
        $lowPrio = new RegexMaskingStrategy([TestConstants::PATTERN_SECRET => 'LOW'], [], [], 10);

        $manager = new StrategyManager([$lowPrio, $highPrio]);
        $record = $this->createLogRecord('Test');

        $result = $manager->maskValue('secret data', 'field', $record);

        // High priority strategy should be applied
        $this->assertStringContainsString('HIGH', $result);
        $this->assertStringNotContainsString('LOW', $result);
    }

    public function testMaskValueReturnsOriginalWhenNoApplicableStrategy(): void
    {
        $strategy = new RegexMaskingStrategy([TestConstants::PATTERN_SECRET => Mask::MASK_MASKED]);
        $manager = new StrategyManager([$strategy]);
        $record = $this->createLogRecord('Test');

        // Value doesn't match pattern
        $result = $manager->maskValue(TestConstants::DATA_PUBLIC, 'field', $record);

        $this->assertSame(TestConstants::DATA_PUBLIC, $result);
    }

    public function testMaskValueThrowsWhenStrategyFails(): void
    {
        // Create a mock strategy that throws
        $failingStrategy = new class implements MaskingStrategyInterface {
            public function mask(mixed $value, string $path, LogRecord $logRecord): mixed
            {
                throw new MaskingOperationFailedException('Strategy execution failed');
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
                return 'Failing Strategy';
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

        $manager = new StrategyManager([$failingStrategy]);
        $record = $this->createLogRecord('Test');

        $this->expectException(MaskingOperationFailedException::class);
        $this->expectExceptionMessage("Strategy 'Failing Strategy' failed");

        $manager->maskValue('test', 'field', $record);
    }

    public function testHasApplicableStrategyReturnsTrue(): void
    {
        $strategy = new RegexMaskingStrategy([TestConstants::PATTERN_SECRET => Mask::MASK_MASKED]);
        $manager = new StrategyManager([$strategy]);
        $record = $this->createLogRecord('Test');

        $result = $manager->hasApplicableStrategy('secret data', 'field', $record);

        $this->assertTrue($result);
    }

    public function testHasApplicableStrategyReturnsFalse(): void
    {
        $strategy = new RegexMaskingStrategy([TestConstants::PATTERN_SECRET => Mask::MASK_MASKED]);
        $manager = new StrategyManager([$strategy]);
        $record = $this->createLogRecord('Test');

        $result = $manager->hasApplicableStrategy(TestConstants::DATA_PUBLIC, 'field', $record);

        $this->assertFalse($result);
    }

    public function testGetApplicableStrategiesReturnsMultiple(): void
    {
        $regex = new RegexMaskingStrategy(['/.*/' => 'REGEX'], [], [], 60);
        $dataType = new DataTypeMaskingStrategy(['string' => 'TYPE'], [], [], 40);

        $manager = new StrategyManager([$regex, $dataType]);
        $record = $this->createLogRecord('Test');

        $applicable = $manager->getApplicableStrategies('test', 'field', $record);

        $this->assertCount(2, $applicable);
        // Should be sorted by priority
        $this->assertSame($regex, $applicable[0]);
        $this->assertSame($dataType, $applicable[1]);
    }

    public function testGetApplicableStrategiesReturnsEmpty(): void
    {
        $strategy = new RegexMaskingStrategy([TestConstants::PATTERN_SECRET => Mask::MASK_MASKED]);
        $manager = new StrategyManager([$strategy]);
        $record = $this->createLogRecord('Test');

        $applicable = $manager->getApplicableStrategies('public', 'field', $record);

        $this->assertCount(0, $applicable);
    }

    public function testGetSortedStrategiesSortsByPriority(): void
    {
        $low = new RegexMaskingStrategy(['/l/' => 'L'], [], [], 10);
        $high = new RegexMaskingStrategy(['/h/' => 'H'], [], [], 90);
        $medium = new RegexMaskingStrategy(['/m/' => 'M'], [], [], 50);

        $manager = new StrategyManager([$low, $high, $medium]);

        $sorted = $manager->getSortedStrategies();

        $this->assertSame($high, $sorted[0]);
        $this->assertSame($medium, $sorted[1]);
        $this->assertSame($low, $sorted[2]);
    }

    public function testGetSortedStrategiesCachesResult(): void
    {
        $strategy = new RegexMaskingStrategy([TestConstants::PATTERN_TEST => Mask::MASK_MASKED]);
        $manager = new StrategyManager([$strategy]);

        $sorted1 = $manager->getSortedStrategies();
        $sorted2 = $manager->getSortedStrategies();

        // Should return same array instance (cached)
        $this->assertSame($sorted1, $sorted2);
    }

    public function testGetSortedStrategiesInvalidatesCacheOnAdd(): void
    {
        $strategy1 = new RegexMaskingStrategy(['/test1/' => 'M1']);
        $manager = new StrategyManager([$strategy1]);

        $sorted1 = $manager->getSortedStrategies();
        $this->assertCount(1, $sorted1);

        $strategy2 = new RegexMaskingStrategy(['/test2/' => 'M2']);
        $manager->addStrategy($strategy2);

        $sorted2 = $manager->getSortedStrategies();
        $this->assertCount(2, $sorted2);
    }

    public function testGetStatistics(): void
    {
        $regex = new RegexMaskingStrategy([TestConstants::PATTERN_TEST => 'M'], [], [], 85);
        $dataType = new DataTypeMaskingStrategy(['string' => 'M'], [], [], 45);

        $manager = new StrategyManager([$regex, $dataType]);

        $stats = $manager->getStatistics();

        $this->assertArrayHasKey('total_strategies', $stats);
        $this->assertArrayHasKey('strategy_types', $stats);
        $this->assertArrayHasKey('priority_distribution', $stats);
        $this->assertArrayHasKey('strategies', $stats);

        $this->assertSame(2, $stats['total_strategies']);
        $this->assertArrayHasKey('RegexMaskingStrategy', $stats['strategy_types']);
        $this->assertArrayHasKey('DataTypeMaskingStrategy', $stats['strategy_types']);
        $this->assertArrayHasKey('80-89 (High)', $stats['priority_distribution']);
        $this->assertArrayHasKey('40-59 (Medium)', $stats['priority_distribution']);
        $this->assertCount(2, $stats['strategies']);
    }

    public function testGetStatisticsPriorityDistribution(): void
    {
        $critical = new RegexMaskingStrategy(['/c/' => 'C'], [], [], 95);
        $high = new RegexMaskingStrategy(['/h/' => 'H'], [], [], 85);
        $mediumHigh = new RegexMaskingStrategy(['/mh/' => 'MH'], [], [], 65);
        $medium = new RegexMaskingStrategy(['/m/' => 'M'], [], [], 45);
        $lowMedium = new RegexMaskingStrategy(['/lm/' => 'LM'], [], [], 25);
        $low = new RegexMaskingStrategy(['/l/' => 'L'], [], [], 5);

        $manager = new StrategyManager([$critical, $high, $mediumHigh, $medium, $lowMedium, $low]);

        $stats = $manager->getStatistics();

        $this->assertArrayHasKey('90-100 (Critical)', $stats['priority_distribution']);
        $this->assertArrayHasKey('80-89 (High)', $stats['priority_distribution']);
        $this->assertArrayHasKey('60-79 (Medium-High)', $stats['priority_distribution']);
        $this->assertArrayHasKey('40-59 (Medium)', $stats['priority_distribution']);
        $this->assertArrayHasKey('20-39 (Low-Medium)', $stats['priority_distribution']);
        $this->assertArrayHasKey('0-19 (Low)', $stats['priority_distribution']);
    }

    public function testValidateAllStrategiesReturnsEmpty(): void
    {
        $strategy1 = new RegexMaskingStrategy(['/test1/' => 'M1']);
        $strategy2 = new DataTypeMaskingStrategy(['string' => Mask::MASK_MASKED]);

        $manager = new StrategyManager([$strategy1, $strategy2]);

        $errors = $manager->validateAllStrategies();

        $this->assertEmpty($errors);
    }

    public function testValidateAllStrategiesReturnsErrors(): void
    {
        // Create an invalid strategy by using empty array
        $invalidStrategy = new class implements MaskingStrategyInterface {
            public function mask(mixed $value, string $path, LogRecord $logRecord): mixed
            {
                return $value;
            }

            public function shouldApply(mixed $value, string $path, LogRecord $logRecord): bool
            {
                return false;
            }

            public function getPriority(): int
            {
                return 50;
            }

            public function getName(): string
            {
                return 'Invalid Strategy';
            }

            public function validate(): bool
            {
                return false;
            }

            public function getConfiguration(): array
            {
                return [];
            }
        };

        // Bypass addStrategy validation by directly manipulating internal array
        $manager = new StrategyManager();
        $reflection = new \ReflectionClass($manager);
        $property = $reflection->getProperty('strategies');
        $property->setValue($manager, [$invalidStrategy]);

        $errors = $manager->validateAllStrategies();

        $this->assertNotEmpty($errors);
        $this->assertArrayHasKey('Invalid Strategy', $errors);
    }

    public function testValidateAllStrategiesCatchesExceptions(): void
    {
        $throwingStrategy = new class implements MaskingStrategyInterface {
            public function mask(mixed $value, string $path, LogRecord $logRecord): mixed
            {
                return $value;
            }

            public function shouldApply(mixed $value, string $path, LogRecord $logRecord): bool
            {
                return false;
            }

            public function getPriority(): int
            {
                return 50;
            }

            public function getName(): string
            {
                return 'Throwing Strategy';
            }

            public function validate(): bool
            {
                throw new MaskingOperationFailedException('Validation error');
            }

            public function getConfiguration(): array
            {
                return [];
            }
        };

        $manager = new StrategyManager();
        $reflection = new \ReflectionClass($manager);
        $property = $reflection->getProperty('strategies');
        $property->setValue($manager, [$throwingStrategy]);

        $errors = $manager->validateAllStrategies();

        $this->assertNotEmpty($errors);
        $this->assertArrayHasKey('Throwing Strategy', $errors);
        $this->assertStringContainsString('Validation error', $errors['Throwing Strategy']);
    }

    public function testCreateDefaultWithAllParameters(): void
    {
        $manager = StrategyManager::createDefault(
            regexPatterns: [TestConstants::PATTERN_TEST => Mask::MASK_MASKED],
            fieldConfigs: ['field' => 'VALUE'],
            typeMasks: ['string' => 'TYPE']
        );

        $strategies = $manager->getAllStrategies();

        $this->assertCount(3, $strategies);
    }

    public function testCreateDefaultWithOnlyRegex(): void
    {
        $manager = StrategyManager::createDefault(
            regexPatterns: [TestConstants::PATTERN_TEST => Mask::MASK_MASKED]
        );

        $strategies = $manager->getAllStrategies();

        $this->assertCount(1, $strategies);
        $this->assertInstanceOf(RegexMaskingStrategy::class, $strategies[0]);
    }

    public function testCreateDefaultWithOnlyFieldConfigs(): void
    {
        $manager = StrategyManager::createDefault(
            fieldConfigs: ['field' => 'VALUE']
        );

        $strategies = $manager->getAllStrategies();

        $this->assertCount(1, $strategies);
        $this->assertInstanceOf(FieldPathMaskingStrategy::class, $strategies[0]);
    }

    public function testCreateDefaultWithOnlyTypeMasks(): void
    {
        $manager = StrategyManager::createDefault(
            typeMasks: ['string' => Mask::MASK_MASKED]
        );

        $strategies = $manager->getAllStrategies();

        $this->assertCount(1, $strategies);
        $this->assertInstanceOf(DataTypeMaskingStrategy::class, $strategies[0]);
    }

    public function testCreateDefaultWithNoParameters(): void
    {
        $manager = StrategyManager::createDefault();

        $this->assertCount(0, $manager->getAllStrategies());
    }
}
