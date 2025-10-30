<?php

declare(strict_types=1);

namespace Tests\Strategies;

use Ivuorinen\MonologGdprFilter\Exceptions\GdprProcessorException;
use Ivuorinen\MonologGdprFilter\MaskConstants;
use PHPUnit\Framework\TestCase;
use Tests\TestHelpers;
use Tests\TestConstants;
use Monolog\LogRecord;
use Monolog\Level;
use Ivuorinen\MonologGdprFilter\FieldMaskConfig;
use Ivuorinen\MonologGdprFilter\Strategies\AbstractMaskingStrategy;
use Ivuorinen\MonologGdprFilter\Strategies\RegexMaskingStrategy;
use Ivuorinen\MonologGdprFilter\Strategies\FieldPathMaskingStrategy;
use Ivuorinen\MonologGdprFilter\Strategies\ConditionalMaskingStrategy;
use Ivuorinen\MonologGdprFilter\Strategies\DataTypeMaskingStrategy;
use Ivuorinen\MonologGdprFilter\Strategies\StrategyManager;
use Ivuorinen\MonologGdprFilter\Exceptions\InvalidRegexPatternException;

/**
 * Tests for masking strategies.
 *
 * @api
 */
class MaskingStrategiesTest extends TestCase
{
    use TestHelpers;

    public function testRegexMaskingStrategy(): void
    {
        $patterns = [
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => MaskConstants::MASK_EMAIL,
            '/\b\d{4}-\d{4}-\d{4}-\d{4}\b/' => MaskConstants::MASK_CC,
        ];

        $strategy = new RegexMaskingStrategy($patterns);
        $logRecord = $this->createLogRecord();

        // Test name and priority
        $this->assertSame('Regex Pattern Masking (2 patterns)', $strategy->getName());
        $this->assertSame(60, $strategy->getPriority());

        // Test shouldApply
        $this->assertTrue($strategy->shouldApply(
            'Contact: john@example.com',
            TestConstants::FIELD_MESSAGE,
            $logRecord
        ));
        $this->assertFalse($strategy->shouldApply('No sensitive data here', TestConstants::FIELD_MESSAGE, $logRecord));

        // Test masking
        $masked = $strategy->mask(
            'Email: john@example.com, Card: 1234-5678-9012-3456',
            TestConstants::FIELD_MESSAGE,
            $logRecord
        );
        $this->assertEquals(
            'Email: ' . MaskConstants::MASK_EMAIL . ', Card: ' . MaskConstants::MASK_CC,
            $masked
        );

        // Test validation
        $this->assertTrue($strategy->validate());
    }

    public function testRegexMaskingStrategyWithInvalidPattern(): void
    {
        $this->expectException(InvalidRegexPatternException::class);
        $strategy = new RegexMaskingStrategy([TestConstants::PATTERN_INVALID_UNCLOSED_BRACKET => 'masked']);
        unset($strategy); // Satisfy SonarQube - this line won't be reached if exception is thrown
        $this->fail('Expected exception was not thrown');
    }

    public function testRegexMaskingStrategyWithReDoSPattern(): void
    {
        $this->expectException(InvalidRegexPatternException::class);
        $strategy = new RegexMaskingStrategy(['/(a+)+$/' => 'masked']);
        unset($strategy); // Satisfy SonarQube - this line won't be reached if exception is thrown
        $this->fail('Expected exception was not thrown');
    }

    public function testRegexMaskingStrategyWithIncludeExcludePaths(): void
    {
        $patterns = [TestConstants::PATTERN_TEST => MaskConstants::MASK_MASKED];
        $strategy = new RegexMaskingStrategy(
            $patterns,
            [TestConstants::PATH_USER_WILDCARD],
            [TestConstants::FIELD_USER_PUBLIC]
        );
        $logRecord = $this->createLogRecord();

        // Should apply to included paths
        $this->assertTrue(
            $strategy->shouldApply(TestConstants::DATA_TEST_DATA, TestConstants::FIELD_USER_EMAIL, $logRecord)
        );

        // Should not apply to excluded paths
        $this->assertFalse(
            $strategy->shouldApply(TestConstants::DATA_TEST_DATA, TestConstants::FIELD_USER_PUBLIC, $logRecord)
        );

        // Should not apply to non-included paths
        $this->assertFalse(
            $strategy->shouldApply(TestConstants::DATA_TEST_DATA, TestConstants::FIELD_SYSTEM_LOG, $logRecord)
        );
    }

    public function testFieldPathMaskingStrategy(): void
    {
        $configs = [
            TestConstants::FIELD_USER_EMAIL => MaskConstants::MASK_EMAIL,
            TestConstants::FIELD_USER_PASSWORD => FieldMaskConfig::remove(),
            TestConstants::FIELD_USER_NAME => FieldMaskConfig::regexMask('/\w+/', MaskConstants::MASK_GENERIC),
        ];

        $strategy = new FieldPathMaskingStrategy($configs);
        $logRecord = $this->createLogRecord();

        // Test name and priority
        $this->assertSame('Field Path Masking (3 fields)', $strategy->getName());
        $this->assertSame(80, $strategy->getPriority());

        // Test shouldApply
        $this->assertTrue($strategy->shouldApply('john@example.com', TestConstants::FIELD_USER_EMAIL, $logRecord));
        $this->assertFalse($strategy->shouldApply('some value', 'other.field', $logRecord));

        // Test static replacement
        $masked = $strategy->mask('john@example.com', TestConstants::FIELD_USER_EMAIL, $logRecord);
        $this->assertEquals(MaskConstants::MASK_EMAIL, $masked);

        // Test removal (returns null)
        $masked = $strategy->mask('password123', TestConstants::FIELD_USER_PASSWORD, $logRecord);
        $this->assertNull($masked);

        // Test regex replacement
        $masked = $strategy->mask('John Doe', TestConstants::FIELD_USER_NAME, $logRecord);
        $this->assertEquals('*** ***', $masked);

        // Test validation
        $this->assertTrue($strategy->validate());
    }

    public function testFieldPathMaskingStrategyWithWildcards(): void
    {
        $strategy = new FieldPathMaskingStrategy([TestConstants::PATH_USER_WILDCARD => MaskConstants::MASK_MASKED]);
        $logRecord = $this->createLogRecord();

        $this->assertTrue($strategy->shouldApply('value', TestConstants::FIELD_USER_EMAIL, $logRecord));
        $this->assertTrue($strategy->shouldApply('value', TestConstants::FIELD_USER_NAME, $logRecord));
        $this->assertFalse($strategy->shouldApply('value', TestConstants::FIELD_SYSTEM_LOG, $logRecord));
    }

    public function testConditionalMaskingStrategy(): void
    {
        $baseStrategy = new RegexMaskingStrategy([TestConstants::PATTERN_TEST => MaskConstants::MASK_MASKED]);
        $conditions = [
            'level' => fn(LogRecord $r): bool => $r->level === Level::Error,
            'channel' => fn(LogRecord $r): bool => $r->channel === 'security',
        ];

        $strategy = new ConditionalMaskingStrategy($baseStrategy, $conditions);

        // Test name
        $this->assertStringContainsString('Conditional Masking (2 conditions, AND logic)', $strategy->getName());
        $this->assertSame(70, $strategy->getPriority());

        // Test conditions not met
        $logRecord = $this->createLogRecord(
            TestConstants::DATA_TEST_DATA,
            [],
            Level::Info,
            TestConstants::CHANNEL_TEST
        );
        $this->assertFalse($strategy->shouldApply(
            TestConstants::DATA_TEST_DATA,
            TestConstants::FIELD_MESSAGE,
            $logRecord
        ));

        // Test conditions met
        $logRecord = $this->createLogRecord(
            TestConstants::DATA_TEST_DATA,
            [],
            Level::Error,
            TestConstants::CHANNEL_SECURITY
        );
        $this->assertTrue($strategy->shouldApply(
            TestConstants::DATA_TEST_DATA,
            TestConstants::FIELD_MESSAGE,
            $logRecord
        ));

        // Test masking when conditions are met
        $masked = $strategy->mask(TestConstants::DATA_TEST_DATA, TestConstants::FIELD_MESSAGE, $logRecord);
        $this->assertEquals('***MASKED*** data', $masked);

        // Test validation
        $this->assertTrue($strategy->validate());
    }

    public function testConditionalMaskingStrategyFactoryMethods(): void
    {
        $baseStrategy = new RegexMaskingStrategy([TestConstants::PATTERN_TEST => MaskConstants::MASK_MASKED]);

        // Test forLevels
        $levelStrategy = ConditionalMaskingStrategy::forLevels($baseStrategy, ['Error', 'Critical']);
        $this->assertInstanceOf(ConditionalMaskingStrategy::class, $levelStrategy);

        $errorRecord = $this->createLogRecord(TestConstants::DATA_TEST, [], Level::Error, TestConstants::CHANNEL_TEST);
        $infoRecord = $this->createLogRecord(TestConstants::DATA_TEST, [], Level::Info, TestConstants::CHANNEL_TEST);
        $this->assertTrue($levelStrategy->shouldApply(
            TestConstants::DATA_TEST,
            TestConstants::FIELD_MESSAGE,
            $errorRecord
        ));
        $this->assertFalse($levelStrategy->shouldApply(
            TestConstants::DATA_TEST,
            TestConstants::FIELD_MESSAGE,
            $infoRecord
        ));

        // Test forChannels
        $channelStrategy = ConditionalMaskingStrategy::forChannels(
            $baseStrategy,
            [TestConstants::CHANNEL_SECURITY, TestConstants::CHANNEL_AUDIT]
        );
        $securityRecord = $this->createLogRecord(
            TestConstants::DATA_TEST,
            [],
            Level::Error,
            TestConstants::CHANNEL_SECURITY
        );
        $generalRecord = $this->createLogRecord(TestConstants::DATA_TEST, [], Level::Error, 'general');
        $this->assertTrue($channelStrategy->shouldApply(
            TestConstants::DATA_TEST,
            TestConstants::FIELD_MESSAGE,
            $securityRecord
        ));
        $this->assertFalse($channelStrategy->shouldApply(
            TestConstants::DATA_TEST,
            TestConstants::FIELD_MESSAGE,
            $generalRecord
        ));

        // Test forContext
        $contextStrategy = ConditionalMaskingStrategy::forContext($baseStrategy, ['sensitive' => true]);
        $sensitiveRecord = $this->createLogRecord(TestConstants::DATA_TEST, ['sensitive' => true]);
        $normalRecord = $this->createLogRecord(TestConstants::DATA_TEST, ['sensitive' => false]);
        $this->assertTrue($contextStrategy->shouldApply(
            TestConstants::DATA_TEST,
            TestConstants::FIELD_MESSAGE,
            $sensitiveRecord
        ));
        $this->assertFalse($contextStrategy->shouldApply(
            TestConstants::DATA_TEST,
            TestConstants::FIELD_MESSAGE,
            $normalRecord
        ));
    }

    public function testDataTypeMaskingStrategy(): void
    {
        $typeMasks = [
            'string' => MaskConstants::MASK_STRING,
            'integer' => '999',
            'boolean' => 'false',
        ];

        $strategy = new DataTypeMaskingStrategy($typeMasks);
        $logRecord = $this->createLogRecord();

        // Test name and priority
        $this->assertStringContainsString('Data Type Masking (3 types:', $strategy->getName());
        $this->assertSame(40, $strategy->getPriority());

        // Test shouldApply
        $this->assertTrue($strategy->shouldApply('string value', TestConstants::FIELD_GENERIC, $logRecord));
        $this->assertTrue($strategy->shouldApply(123, TestConstants::FIELD_GENERIC, $logRecord));
        $this->assertTrue($strategy->shouldApply(true, TestConstants::FIELD_GENERIC, $logRecord));
        $this->assertFalse($strategy->shouldApply([], TestConstants::FIELD_GENERIC, $logRecord)); // No mask for arrays

        // Test masking
        $this->assertEquals(
            MaskConstants::MASK_STRING,
            $strategy->mask('original string', TestConstants::FIELD_GENERIC, $logRecord)
        );
        $this->assertEquals(999, $strategy->mask(123, TestConstants::FIELD_GENERIC, $logRecord));
        $this->assertFalse($strategy->mask(true, TestConstants::FIELD_GENERIC, $logRecord));

        // Test validation
        $this->assertTrue($strategy->validate());
    }

    public function testDataTypeMaskingStrategyFactoryMethods(): void
    {
        // Test createDefault
        $defaultStrategy = DataTypeMaskingStrategy::createDefault();
        $this->assertInstanceOf(DataTypeMaskingStrategy::class, $defaultStrategy);
        $this->assertTrue($defaultStrategy->validate());

        // Test createSensitiveOnly
        $sensitiveStrategy = DataTypeMaskingStrategy::createSensitiveOnly();
        $this->assertInstanceOf(DataTypeMaskingStrategy::class, $sensitiveStrategy);
        $this->assertTrue($sensitiveStrategy->validate());

        $logRecord = $this->createLogRecord();
        $this->assertTrue($sensitiveStrategy->shouldApply('string', TestConstants::FIELD_GENERIC, $logRecord));
        // Integers not considered sensitive
        $this->assertFalse($sensitiveStrategy->shouldApply(123, TestConstants::FIELD_GENERIC, $logRecord));
    }

    public function testAbstractMaskingStrategyUtilities(): void
    {
        $strategy = new class extends AbstractMaskingStrategy {
            #[\Override]
            public function mask(mixed $value, string $path, LogRecord $logRecord): mixed
            {
                return $this->valueToString($value);
            }

            #[\Override]
            public function shouldApply(mixed $value, string $path, LogRecord $logRecord): bool
            {
                return $this->pathMatches($path, TestConstants::PATH_USER_WILDCARD);
            }

            /**
             * @psalm-return 'Test Strategy'
             */
            #[\Override]
            public function getName(): string
            {
                return 'Test Strategy';
            }

            // Expose protected methods for testing
            public function testValueToString(mixed $value): string
            {
                return $this->valueToString($value);
            }

            public function testPathMatches(string $path, string $pattern): bool
            {
                return $this->pathMatches($path, $pattern);
            }

            /**
             * @param array<string, mixed> $conditions
             */
            public function testRecordMatches(LogRecord $logRecord, array $conditions): bool
            {
                return $this->recordMatches($logRecord, $conditions);
            }

            public function testPreserveValueType(mixed $original, string $masked): mixed
            {
                return $this->preserveValueType($original, $masked);
            }
        };

        // Test valueToString
        $this->assertSame('string', $strategy->testValueToString('string'));
        $this->assertSame('123', $strategy->testValueToString(123));
        $this->assertSame('1', $strategy->testValueToString(true));
        $this->assertSame('', $strategy->testValueToString(null));

        // Test pathMatches
        $this->assertTrue($strategy->testPathMatches(
            TestConstants::FIELD_USER_EMAIL,
            TestConstants::PATH_USER_WILDCARD
        ));
        $this->assertTrue($strategy->testPathMatches(
            TestConstants::FIELD_USER_NAME,
            TestConstants::PATH_USER_WILDCARD
        ));
        $this->assertFalse($strategy->testPathMatches(
            TestConstants::FIELD_SYSTEM_LOG,
            TestConstants::PATH_USER_WILDCARD
        ));
        $this->assertTrue($strategy->testPathMatches('exact.match', 'exact.match'));

        // Test recordMatches
        $logRecord = $this->createLogRecord('Test', ['key' => 'value'], Level::Error, 'test');
        $this->assertTrue($strategy->testRecordMatches($logRecord, ['level' => 'Error']));
        $this->assertTrue($strategy->testRecordMatches($logRecord, ['channel' => 'test']));
        $this->assertTrue($strategy->testRecordMatches($logRecord, ['key' => 'value']));
        $this->assertFalse($strategy->testRecordMatches($logRecord, ['level' => 'Info']));

        // Test preserveValueType
        $this->assertEquals('masked', $strategy->testPreserveValueType('original', 'masked'));
        $this->assertEquals(123, $strategy->testPreserveValueType(456, '123'));
        $this->assertEqualsWithDelta(12.5, $strategy->testPreserveValueType(45.6, '12.5'), PHP_FLOAT_EPSILON);
        $this->assertTrue($strategy->testPreserveValueType(false, 'true'));
    }

    public function testStrategyManager(): void
    {
        $manager = new StrategyManager();
        $strategy1 = new RegexMaskingStrategy(['/test1/' => 'masked1'], [], [], 80);
        $strategy2 = new RegexMaskingStrategy(['/test2/' => 'masked2'], [], [], 60);

        // Test adding strategies
        $manager->addStrategy($strategy1);
        $manager->addStrategy($strategy2);
        $this->assertCount(2, $manager->getAllStrategies());

        // Test sorting by priority
        $sorted = $manager->getSortedStrategies();
        $this->assertSame($strategy1, $sorted[0]); // Higher priority first
        $this->assertSame($strategy2, $sorted[1]);

        // Test masking (should use highest priority applicable strategy)
        $logRecord = $this->createLogRecord();
        $result = $manager->maskValue('test1 test2', TestConstants::FIELD_MESSAGE, $logRecord);
        $this->assertEquals('masked1 test2', $result); // Only first strategy applied

        // Test hasApplicableStrategy
        $this->assertTrue($manager->hasApplicableStrategy('test1', TestConstants::FIELD_MESSAGE, $logRecord));
        $this->assertFalse($manager->hasApplicableStrategy('no match', TestConstants::FIELD_MESSAGE, $logRecord));

        // Test getApplicableStrategies
        $applicable = $manager->getApplicableStrategies('test1 test2', TestConstants::FIELD_MESSAGE, $logRecord);
        $this->assertCount(2, $applicable); // Both strategies would match

        // Test removeStrategy
        $this->assertTrue($manager->removeStrategy($strategy1));
        $this->assertCount(1, $manager->getAllStrategies());
        $this->assertFalse($manager->removeStrategy($strategy1)); // Already removed

        // Test clearStrategies
        $manager->clearStrategies();
        $this->assertCount(0, $manager->getAllStrategies());
    }

    public function testStrategyManagerStatistics(): void
    {
        $manager = new StrategyManager();
        $manager->addStrategy(new RegexMaskingStrategy(
            [TestConstants::PATTERN_TEST => TestConstants::DATA_MASKED],
            [],
            [],
            90
        ));
        $manager->addStrategy(new DataTypeMaskingStrategy(['string' => TestConstants::DATA_MASKED], [], [], 40));

        $stats = $manager->getStatistics();

        $this->assertEquals(2, $stats['total_strategies']);
        $this->assertArrayHasKey('RegexMaskingStrategy', $stats['strategy_types']);
        $this->assertArrayHasKey('DataTypeMaskingStrategy', $stats['strategy_types']);
        $this->assertArrayHasKey('90-100 (Critical)', $stats['priority_distribution']);
        $this->assertArrayHasKey('40-59 (Medium)', $stats['priority_distribution']);
        $this->assertCount(2, $stats['strategies']);
    }

    public function testStrategyManagerValidation(): void
    {
        $manager = new StrategyManager();
        $validStrategy = new RegexMaskingStrategy([TestConstants::PATTERN_TEST => TestConstants::DATA_MASKED]);

        // Test adding valid strategy
        $manager->addStrategy($validStrategy);
        $errors = $manager->validateAllStrategies();
        $this->assertEmpty($errors);

        // Test validation with invalid strategy (empty patterns)
        $invalidStrategy = new class extends AbstractMaskingStrategy {
            #[\Override]
            public function mask(mixed $value, string $path, LogRecord $logRecord): mixed
            {
                return $value;
            }

            /**
             * @return false
             */
            #[\Override]
            public function shouldApply(mixed $value, string $path, LogRecord $logRecord): bool
            {
                return false;
            }

            /**
             * @psalm-return 'Invalid'
             */
            #[\Override]
            public function getName(): string
            {
                return 'Invalid';
            }

            /**
             * @return false
             */
            #[\Override]
            public function validate(): bool
            {
                // Always invalid
                return false;
            }
        };

        $this->expectException(GdprProcessorException::class);
        $manager->addStrategy($invalidStrategy);
    }

    public function testStrategyManagerCreateDefault(): void
    {
        $regexPatterns = [TestConstants::PATTERN_TEST => TestConstants::DATA_MASKED];
        $fieldConfigs = [TestConstants::FIELD_GENERIC => TestConstants::DATA_MASKED];
        $typeMasks = ['string' => TestConstants::DATA_MASKED];

        $manager = StrategyManager::createDefault($regexPatterns, $fieldConfigs, $typeMasks);

        $strategies = $manager->getAllStrategies();
        $this->assertCount(3, $strategies);

        // Check that we have the expected strategy types
        $classNames = array_map('get_class', $strategies);
        $this->assertContains(RegexMaskingStrategy::class, $classNames);
        $this->assertContains(FieldPathMaskingStrategy::class, $classNames);
        $this->assertContains(DataTypeMaskingStrategy::class, $classNames);
    }

    public function testMaskingOperationFailedException(): void
    {
        // Test that invalid patterns are caught during construction
        $this->expectException(InvalidRegexPatternException::class);
        $strategy = new RegexMaskingStrategy(['/[/' => 'invalid']); // Invalid pattern should throw exception
        unset($strategy); // Satisfy SonarQube - this line won't be reached if exception is thrown
        $this->fail('Expected exception was not thrown');
    }
}
