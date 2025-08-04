<?php

declare(strict_types=1);

namespace Tests\Strategies;

use DateTimeImmutable;
use Ivuorinen\MonologGdprFilter\Exceptions\GdprProcessorException;
use PHPUnit\Framework\TestCase;
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
    private function createLogRecord(
        string $message = 'Test message',
        array $context = [],
        string $channel = 'test',
        Level $level = Level::Info
    ): LogRecord {
        return new LogRecord(
            new DateTimeImmutable(),
            $channel,
            $level,
            $message,
            $context
        );
    }

    public function testRegexMaskingStrategy(): void
    {
        $patterns = [
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '***EMAIL***',
            '/\b\d{4}-\d{4}-\d{4}-\d{4}\b/' => '***CARD***',
        ];

        $strategy = new RegexMaskingStrategy($patterns);
        $logRecord = $this->createLogRecord();

        // Test name and priority
        $this->assertSame('Regex Pattern Masking (2 patterns)', $strategy->getName());
        $this->assertSame(60, $strategy->getPriority());

        // Test shouldApply
        $this->assertTrue($strategy->shouldApply('Contact: john@example.com', 'message', $logRecord));
        $this->assertFalse($strategy->shouldApply('No sensitive data here', 'message', $logRecord));

        // Test masking
        $masked = $strategy->mask('Email: john@example.com, Card: 1234-5678-9012-3456', 'message', $logRecord);
        $this->assertEquals('Email: ***EMAIL***, Card: ***CARD***', $masked);

        // Test validation
        $this->assertTrue($strategy->validate());
    }

    public function testRegexMaskingStrategyWithInvalidPattern(): void
    {
        $this->expectException(InvalidRegexPatternException::class);
        new RegexMaskingStrategy(['/invalid[/' => 'masked']);
    }

    public function testRegexMaskingStrategyWithReDoSPattern(): void
    {
        $this->expectException(InvalidRegexPatternException::class);
        new RegexMaskingStrategy(['/(a+)+$/' => 'masked']);
    }

    public function testRegexMaskingStrategyWithIncludeExcludePaths(): void
    {
        $patterns = ['/test/' => '***MASKED***'];
        $strategy = new RegexMaskingStrategy($patterns, ['user.*'], ['user.public']);
        $logRecord = $this->createLogRecord();

        // Should apply to included paths
        $this->assertTrue($strategy->shouldApply('test data', 'user.email', $logRecord));

        // Should not apply to excluded paths
        $this->assertFalse($strategy->shouldApply('test data', 'user.public', $logRecord));

        // Should not apply to non-included paths
        $this->assertFalse($strategy->shouldApply('test data', 'system.log', $logRecord));
    }

    public function testFieldPathMaskingStrategy(): void
    {
        $configs = [
            'user.email' => '***EMAIL***',
            'user.password' => FieldMaskConfig::remove(),
            'user.name' => FieldMaskConfig::regexMask('/\w+/', '***'),
        ];

        $strategy = new FieldPathMaskingStrategy($configs);
        $logRecord = $this->createLogRecord();

        // Test name and priority
        $this->assertSame('Field Path Masking (3 fields)', $strategy->getName());
        $this->assertSame(80, $strategy->getPriority());

        // Test shouldApply
        $this->assertTrue($strategy->shouldApply('john@example.com', 'user.email', $logRecord));
        $this->assertFalse($strategy->shouldApply('some value', 'other.field', $logRecord));

        // Test static replacement
        $masked = $strategy->mask('john@example.com', 'user.email', $logRecord);
        $this->assertEquals('***EMAIL***', $masked);

        // Test removal (returns null)
        $masked = $strategy->mask('password123', 'user.password', $logRecord);
        $this->assertNull($masked);

        // Test regex replacement
        $masked = $strategy->mask('John Doe', 'user.name', $logRecord);
        $this->assertEquals('*** ***', $masked);

        // Test validation
        $this->assertTrue($strategy->validate());
    }

    public function testFieldPathMaskingStrategyWithWildcards(): void
    {
        $strategy = new FieldPathMaskingStrategy(['user.*' => '***MASKED***']);
        $logRecord = $this->createLogRecord();

        $this->assertTrue($strategy->shouldApply('value', 'user.email', $logRecord));
        $this->assertTrue($strategy->shouldApply('value', 'user.name', $logRecord));
        $this->assertFalse($strategy->shouldApply('value', 'system.log', $logRecord));
    }

    public function testConditionalMaskingStrategy(): void
    {
        $baseStrategy = new RegexMaskingStrategy(['/test/' => '***MASKED***']);
        $conditions = [
            'level' => fn(LogRecord $r): bool => $r->level === Level::Error,
            'channel' => fn(LogRecord $r): bool => $r->channel === 'security',
        ];

        $strategy = new ConditionalMaskingStrategy($baseStrategy, $conditions);

        // Test name
        $this->assertStringContainsString('Conditional Masking (2 conditions, AND logic)', $strategy->getName());
        $this->assertSame(70, $strategy->getPriority());

        // Test conditions not met
        $logRecord = $this->createLogRecord('test data', [], 'test', Level::Info);
        $this->assertFalse($strategy->shouldApply('test data', 'message', $logRecord));

        // Test conditions met
        $logRecord = $this->createLogRecord('test data', [], 'security', Level::Error);
        $this->assertTrue($strategy->shouldApply('test data', 'message', $logRecord));

        // Test masking when conditions are met
        $masked = $strategy->mask('test data', 'message', $logRecord);
        $this->assertEquals('***MASKED*** data', $masked);

        // Test validation
        $this->assertTrue($strategy->validate());
    }

    public function testConditionalMaskingStrategyFactoryMethods(): void
    {
        $baseStrategy = new RegexMaskingStrategy(['/test/' => '***MASKED***']);

        // Test forLevels
        $levelStrategy = ConditionalMaskingStrategy::forLevels($baseStrategy, ['Error', 'Critical']);
        $this->assertInstanceOf(ConditionalMaskingStrategy::class, $levelStrategy);

        $errorRecord = $this->createLogRecord('test', [], 'test', Level::Error);
        $infoRecord = $this->createLogRecord('test', [], 'test', Level::Info);
        $this->assertTrue($levelStrategy->shouldApply('test', 'message', $errorRecord));
        $this->assertFalse($levelStrategy->shouldApply('test', 'message', $infoRecord));

        // Test forChannels
        $channelStrategy = ConditionalMaskingStrategy::forChannels($baseStrategy, ['security', 'audit']);
        $securityRecord = $this->createLogRecord('test', [], 'security');
        $generalRecord = $this->createLogRecord('test', [], 'general');
        $this->assertTrue($channelStrategy->shouldApply('test', 'message', $securityRecord));
        $this->assertFalse($channelStrategy->shouldApply('test', 'message', $generalRecord));

        // Test forContext
        $contextStrategy = ConditionalMaskingStrategy::forContext($baseStrategy, ['sensitive' => true]);
        $sensitiveRecord = $this->createLogRecord('test', ['sensitive' => true]);
        $normalRecord = $this->createLogRecord('test', ['sensitive' => false]);
        $this->assertTrue($contextStrategy->shouldApply('test', 'message', $sensitiveRecord));
        $this->assertFalse($contextStrategy->shouldApply('test', 'message', $normalRecord));
    }

    public function testDataTypeMaskingStrategy(): void
    {
        $typeMasks = [
            'string' => '***STRING***',
            'integer' => '999',
            'boolean' => 'false',
        ];

        $strategy = new DataTypeMaskingStrategy($typeMasks);
        $logRecord = $this->createLogRecord();

        // Test name and priority
        $this->assertStringContainsString('Data Type Masking (3 types:', $strategy->getName());
        $this->assertSame(40, $strategy->getPriority());

        // Test shouldApply
        $this->assertTrue($strategy->shouldApply('string value', 'field', $logRecord));
        $this->assertTrue($strategy->shouldApply(123, 'field', $logRecord));
        $this->assertTrue($strategy->shouldApply(true, 'field', $logRecord));
        $this->assertFalse($strategy->shouldApply([], 'field', $logRecord)); // No mask for arrays

        // Test masking
        $this->assertEquals('***STRING***', $strategy->mask('original string', 'field', $logRecord));
        $this->assertEquals(999, $strategy->mask(123, 'field', $logRecord));
        $this->assertFalse($strategy->mask(true, 'field', $logRecord));

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
        $this->assertTrue($sensitiveStrategy->shouldApply('string', 'field', $logRecord));
        $this->assertFalse($sensitiveStrategy->shouldApply(123, 'field', $logRecord)); // Integers not considered sensitive
    }

    public function testAbstractMaskingStrategyUtilities(): void
    {
        $strategy = new class extends AbstractMaskingStrategy {
            public function mask(mixed $value, string $path, LogRecord $logRecord): mixed
            {
                return $this->valueToString($value);
            }

            public function shouldApply(mixed $value, string $path, LogRecord $logRecord): bool
            {
                return $this->pathMatches($path, 'user.*');
            }

            /**
             * @psalm-return 'Test Strategy'
             */
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
        $this->assertTrue($strategy->testPathMatches('user.email', 'user.*'));
        $this->assertTrue($strategy->testPathMatches('user.name', 'user.*'));
        $this->assertFalse($strategy->testPathMatches('system.log', 'user.*'));
        $this->assertTrue($strategy->testPathMatches('exact.match', 'exact.match'));

        // Test recordMatches
        $logRecord = $this->createLogRecord('Test', ['key' => 'value'], 'test', Level::Error);
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
        $result = $manager->maskValue('test1 test2', 'message', $logRecord);
        $this->assertEquals('masked1 test2', $result); // Only first strategy applied

        // Test hasApplicableStrategy
        $this->assertTrue($manager->hasApplicableStrategy('test1', 'message', $logRecord));
        $this->assertFalse($manager->hasApplicableStrategy('no match', 'message', $logRecord));

        // Test getApplicableStrategies
        $applicable = $manager->getApplicableStrategies('test1 test2', 'message', $logRecord);
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
        $manager->addStrategy(new RegexMaskingStrategy(['/test/' => 'masked'], [], [], 90));
        $manager->addStrategy(new DataTypeMaskingStrategy(['string' => 'masked'], [], [], 40));

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
        $validStrategy = new RegexMaskingStrategy(['/test/' => 'masked']);

        // Test adding valid strategy
        $manager->addStrategy($validStrategy);
        $errors = $manager->validateAllStrategies();
        $this->assertEmpty($errors);

        // Test validation with invalid strategy (empty patterns)
        $invalidStrategy = new class extends AbstractMaskingStrategy {
            public function mask(mixed $value, string $path, LogRecord $logRecord): mixed
            {
                return $value;
            }

            /**
             * @return false
             */
            public function shouldApply(mixed $value, string $path, LogRecord $logRecord): bool
            {
                return false;
            }

            /**
             * @psalm-return 'Invalid'
             */
            public function getName(): string
            {
                return 'Invalid';
            }

            /**
             * @return false
             */
            public function validate(): bool
            {
                return false;
            } // Always invalid
        };

        $this->expectException(GdprProcessorException::class);
        $manager->addStrategy($invalidStrategy);
    }

    public function testStrategyManagerCreateDefault(): void
    {
        $regexPatterns = ['/test/' => 'masked'];
        $fieldConfigs = ['field' => 'masked'];
        $typeMasks = ['string' => 'masked'];

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
        new RegexMaskingStrategy(['/[/' => 'invalid']); // Invalid pattern should throw exception
    }
}
