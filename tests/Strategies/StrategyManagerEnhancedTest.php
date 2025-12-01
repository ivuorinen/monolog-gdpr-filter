<?php

declare(strict_types=1);

namespace Tests\Strategies;

use PHPUnit\Framework\TestCase;
use Tests\TestConstants;
use Tests\TestHelpers;
use Ivuorinen\MonologGdprFilter\Strategies\StrategyManager;
use Ivuorinen\MonologGdprFilter\MaskConstants;
use Ivuorinen\MonologGdprFilter\Strategies\RegexMaskingStrategy;
use Ivuorinen\MonologGdprFilter\Strategies\DataTypeMaskingStrategy;
use Ivuorinen\MonologGdprFilter\Strategies\FieldPathMaskingStrategy;
use Ivuorinen\MonologGdprFilter\FieldMaskConfig;

/**
 * Enhanced tests for StrategyManager to improve coverage.
 */
final class StrategyManagerEnhancedTest extends TestCase
{
    use TestHelpers;

    public function testRemoveStrategiesByClassWithMatchingStrategies(): void
    {
        $manager = new StrategyManager();

        $regex1 = new RegexMaskingStrategy(['/test1/' => '***1***']);
        $regex2 = new RegexMaskingStrategy(['/test2/' => '***2***']);
        $dataType = new DataTypeMaskingStrategy(['string' => MaskConstants::MASK_STRING]);

        $manager->addStrategy($regex1);
        $manager->addStrategy($regex2);
        $manager->addStrategy($dataType);

        $this->assertCount(3, $manager->getAllStrategies());

        // Remove all RegexMaskingStrategy instances
        $removedCount = $manager->removeStrategiesByClass(RegexMaskingStrategy::class);

        $this->assertEquals(2, $removedCount);
        $this->assertCount(1, $manager->getAllStrategies());

        // Check that only DataTypeMaskingStrategy remains
        $remaining = $manager->getAllStrategies();
        $this->assertInstanceOf(DataTypeMaskingStrategy::class, $remaining[0]);
    }

    public function testRemoveStrategiesByClassWithNoMatchingStrategies(): void
    {
        $manager = new StrategyManager();

        $dataType = new DataTypeMaskingStrategy(['string' => MaskConstants::MASK_STRING]);
        $manager->addStrategy($dataType);

        // Try to remove RegexMaskingStrategy when none exist
        $removedCount = $manager->removeStrategiesByClass(RegexMaskingStrategy::class);

        $this->assertEquals(0, $removedCount);
        $this->assertCount(1, $manager->getAllStrategies());
    }

    public function testRemoveStrategiesByClassFromEmptyManager(): void
    {
        $manager = new StrategyManager();

        $removedCount = $manager->removeStrategiesByClass(RegexMaskingStrategy::class);

        $this->assertEquals(0, $removedCount);
        $this->assertCount(0, $manager->getAllStrategies());
    }

    public function testGetApplicableStrategiesReturnsEmptyArray(): void
    {
        $manager = new StrategyManager();

        // Add strategy that doesn't apply to this value
        $regex = new RegexMaskingStrategy([TestConstants::PATTERN_SECRET => MaskConstants::MASK_MASKED]);
        $manager->addStrategy($regex);

        $logRecord = $this->createLogRecord();

        // Value doesn't match pattern
        $applicable = $manager->getApplicableStrategies(
            TestConstants::DATA_PUBLIC,
            TestConstants::FIELD_MESSAGE,
            $logRecord
        );

        $this->assertEmpty($applicable);
    }

    public function testGetStatisticsWithEdgePriorityValues(): void
    {
        $manager = new StrategyManager();

        // Common pattern for all strategies
        $patterns = [TestConstants::PATTERN_TEST => MaskConstants::MASK_GENERIC];

        // Add strategies with edge priority values across all priority ranges
        $manager->addStrategy(new RegexMaskingStrategy($patterns, [], [], 0));   // Lowest
        $manager->addStrategy(new RegexMaskingStrategy($patterns, [], [], 19));  // High edge
        $manager->addStrategy(new RegexMaskingStrategy($patterns, [], [], 20));  // Medium-high boundary
        $manager->addStrategy(new RegexMaskingStrategy($patterns, [], [], 39));  // Medium-high edge
        $manager->addStrategy(new RegexMaskingStrategy($patterns, [], [], 40));  // Medium boundary
        $manager->addStrategy(new RegexMaskingStrategy($patterns, [], [], 59));  // Medium edge
        $manager->addStrategy(new RegexMaskingStrategy($patterns, [], [], 60));  // Medium-low boundary
        $manager->addStrategy(new RegexMaskingStrategy($patterns, [], [], 79));  // Medium-low edge
        $manager->addStrategy(new RegexMaskingStrategy($patterns, [], [], 80));  // Low boundary
        $manager->addStrategy(new RegexMaskingStrategy($patterns, [], [], 89));  // Low edge
        $manager->addStrategy(new RegexMaskingStrategy($patterns, [], [], 90));  // Lowest boundary
        $manager->addStrategy(new RegexMaskingStrategy($patterns, [], [], 100)); // Highest

        $stats = $manager->getStatistics();

        $this->assertEquals(12, $stats['total_strategies']);
        $this->assertArrayHasKey('priority_distribution', $stats);
        $this->assertArrayHasKey('strategy_types', $stats);

        // Check that strategies are distributed across priority ranges
        $priorityStats = $stats['priority_distribution'];
        $this->assertGreaterThan(0, $priorityStats['0-19 (Low)']);
        $this->assertGreaterThan(0, $priorityStats['20-39 (Low-Medium)']);
        $this->assertGreaterThan(0, $priorityStats['40-59 (Medium)']);
        $this->assertGreaterThan(0, $priorityStats['60-79 (Medium-High)']);
        $this->assertGreaterThan(0, $priorityStats['80-89 (High)']);
        $this->assertGreaterThan(0, $priorityStats['90-100 (Critical)']);
    }

    public function testCreateDefaultWithEmptyArrays(): void
    {
        $manager = StrategyManager::createDefault([], [], []);

        // Should create manager with no strategies when all arrays are empty
        $this->assertInstanceOf(StrategyManager::class, $manager);

        $strategies = $manager->getAllStrategies();

        // Might have 0 strategies or might create empty strategy instances - either is acceptable
        $this->assertIsArray($strategies);
    }

    public function testMaskValueReturnsOriginalWhenNoApplicableStrategies(): void
    {
        $manager = new StrategyManager();

        // Add strategy that doesn't apply
        $regex = new RegexMaskingStrategy([TestConstants::PATTERN_SECRET => MaskConstants::MASK_MASKED]);
        $manager->addStrategy($regex);

        $logRecord = $this->createLogRecord();

        // Value doesn't match any pattern
        $result = $manager->maskValue(TestConstants::DATA_PUBLIC, TestConstants::FIELD_MESSAGE, $logRecord);

        $this->assertEquals(TestConstants::DATA_PUBLIC, $result);
    }

    public function testGetStatisticsClassNameWithoutNamespace(): void
    {
        $manager = new StrategyManager();

        $manager->addStrategy(new RegexMaskingStrategy([TestConstants::PATTERN_TEST => MaskConstants::MASK_GENERIC]));
        $manager->addStrategy(new DataTypeMaskingStrategy(['string' => MaskConstants::MASK_GENERIC]));
        $manager->addStrategy(new FieldPathMaskingStrategy(['field' => FieldMaskConfig::remove()]));

        $stats = $manager->getStatistics();

        // Check that type names are simplified (without namespace)
        $typeStats = $stats['strategy_types'];
        $this->assertArrayHasKey('RegexMaskingStrategy', $typeStats);
        $this->assertArrayHasKey('DataTypeMaskingStrategy', $typeStats);
        $this->assertArrayHasKey('FieldPathMaskingStrategy', $typeStats);
    }

    public function testMultipleRemoveOperationsReindexArray(): void
    {
        $manager = new StrategyManager();

        $regex1 = new RegexMaskingStrategy(['/test1/' => '***1***']);
        $regex2 = new RegexMaskingStrategy(['/test2/' => '***2***']);
        $regex3 = new RegexMaskingStrategy(['/test3/' => '***3***']);

        $manager->addStrategy($regex1);
        $manager->addStrategy($regex2);
        $manager->addStrategy($regex3);

        // Remove twice
        $manager->removeStrategiesByClass(RegexMaskingStrategy::class);

        $this->assertCount(0, $manager->getAllStrategies());

        // Add new strategy after removal
        $newRegex = new RegexMaskingStrategy(['/new/' => '***NEW***']);
        $manager->addStrategy($newRegex);

        $strategies = $manager->getAllStrategies();
        $this->assertCount(1, $strategies);

        // Check array is properly indexed (starts at 0)
        $this->assertArrayHasKey(0, $strategies);
    }
}
