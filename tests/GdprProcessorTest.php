<?php

declare(strict_types=1);

namespace Tests;

use Ivuorinen\MonologGdprFilter\RateLimitedAuditLogger;
use Ivuorinen\MonologGdprFilter\Exceptions\PatternValidationException;
use Ivuorinen\MonologGdprFilter\MaskingOrchestrator;
use Tests\TestConstants;
use Ivuorinen\MonologGdprFilter\Exceptions\InvalidRegexPatternException;
use Ivuorinen\MonologGdprFilter\DefaultPatterns;
use Ivuorinen\MonologGdprFilter\FieldMaskConfig;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\MaskConstants as Mask;
use Monolog\JsonSerializableDateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GDPR processor.
 *
 * @api
 */
#[CoversClass(GdprProcessor::class)]
#[CoversMethod(GdprProcessor::class, '__invoke')]
#[CoversMethod(DefaultPatterns::class, 'get')]
#[CoversMethod(GdprProcessor::class, 'maskMessage')]
#[CoversMethod(GdprProcessor::class, 'recursiveMask')]
#[CoversMethod(GdprProcessor::class, 'regExpMessage')]
class GdprProcessorTest extends TestCase
{
    use TestHelpers;

    public function testMaskWithRegexField(): void
    {
        $patterns = DefaultPatterns::get();
        $fieldPaths = [
            TestConstants::FIELD_USER_EMAIL => FieldMaskConfig::useProcessorPatterns(),
        ];
        $processor = $this->createProcessor($patterns, $fieldPaths);
        $record = new LogRecord(
            datetime: new JsonSerializableDateTimeImmutable(true),
            channel: 'test',
            level: Level::Info,
            message: static::USER_REGISTERED,
            context: ['user' => [TestConstants::CONTEXT_EMAIL => self::TEST_EMAIL]],
            extra: []
        );
        $processed = $processor($record);
        $this->assertSame(self::MASKED_EMAIL, $processed->context['user'][TestConstants::CONTEXT_EMAIL]);
    }

    public function testRemoveField(): void
    {
        $patterns = DefaultPatterns::get();
        $fieldPaths = [
            'user.ssn' => FieldMaskConfig::remove(),
        ];
        $processor = $this->createProcessor($patterns, $fieldPaths);
        $record = new LogRecord(
            datetime: new JsonSerializableDateTimeImmutable(true),
            channel: 'test',
            level: Level::Info,
            message: 'Sensitive info',
            context: ['user' => ['ssn' => '123456-789A', 'name' => TestConstants::NAME_FIRST]],
            extra: []
        );
        $processed = $processor($record);
        $this->assertArrayNotHasKey('ssn', $processed->context['user']);
        $this->assertSame(TestConstants::NAME_FIRST, $processed->context['user']['name']);
    }

    public function testReplaceWithField(): void
    {
        $patterns = DefaultPatterns::get();
        $fieldPaths = [
            'user.card' => FieldMaskConfig::replace('MASKED'),
        ];
        $processor = $this->createProcessor($patterns, $fieldPaths);
        $record = new LogRecord(
            datetime: new JsonSerializableDateTimeImmutable(true),
            channel: 'test',
            level: Level::Info,
            message: 'Payment processed',
            context: ['user' => ['card' => '1234123412341234']],
            extra: []
        );
        $processed = $processor($record);
        $this->assertSame('MASKED', $processed->context['user']['card']);
    }

    public function testCustomCallback(): void
    {
        $patterns = DefaultPatterns::get();
        $fieldPaths = [
            TestConstants::FIELD_USER_NAME => FieldMaskConfig::useProcessorPatterns(),
        ];
        $customCallbacks = [
            TestConstants::FIELD_USER_NAME => fn($value): string => strtoupper((string) $value),
        ];
        $processor = $this->createProcessor($patterns, $fieldPaths, $customCallbacks);
        $record = new LogRecord(
            datetime: new JsonSerializableDateTimeImmutable(true),
            channel: 'test',
            level: Level::Info,
            message: 'Name logged',
            context: ['user' => ['name' => 'john']],
            extra: []
        );
        $processed = $processor($record);
        $this->assertSame('JOHN', $processed->context['user']['name']);
    }

    public function testAuditLoggerIsCalled(): void
    {
        $patterns = DefaultPatterns::get();
        $fieldPaths = [
            TestConstants::FIELD_USER_EMAIL => FieldMaskConfig::useProcessorPatterns(),
        ];
        $auditCalls = [];
        $auditLogger = function ($path, $original, $masked) use (&$auditCalls): void {
            $auditCalls[] = [$path, $original, $masked];
        };
        $processor = $this->createProcessor($patterns, $fieldPaths, [], $auditLogger);
        $record = new LogRecord(
            datetime: new JsonSerializableDateTimeImmutable(true),
            channel: 'test',
            level: Level::Info,
            message: static::USER_REGISTERED,
            context: ['user' => [TestConstants::CONTEXT_EMAIL => static::TEST_EMAIL]],
            extra: []
        );
        $processor($record);
        $this->assertNotEmpty($auditCalls);
        $expected = [TestConstants::FIELD_USER_EMAIL, TestConstants::EMAIL_JOHN_DOE, Mask::MASK_EMAIL];
        $this->assertSame($expected, $auditCalls[0]);
    }

    public function testMaskMessage(): void
    {
        $patterns = [
            '/foo/' => 'bar',
            '/baz/' => 'qux',
        ];
        $processor = $this->createProcessor($patterns);
        $masked = $processor->maskMessage('foo and baz');
        $this->assertSame('bar and qux', $masked);
    }

    public function testRecursiveMask(): void
    {
        $patterns = [
            TestConstants::PATTERN_SECRET => self::MASKED_SECRET,
        ];
        $processor = new class ($patterns) extends GdprProcessor {
            public function callRecursiveMask(mixed $data): array|string
            {
                return $this->recursiveMask($data);
            }
        };
        $data = [
            'a' => 'secret',
            'b' => ['c' => 'secret'],
            'd' => 123,
        ];
        $masked = $processor->callRecursiveMask($data);
        $this->assertSame([
            'a' => self::MASKED_SECRET,
            'b' => ['c' => self::MASKED_SECRET],
            'd' => 123,
        ], $masked);
    }

    public function testStaticHelpers(): void
    {
        $regex = FieldMaskConfig::useProcessorPatterns();
        $remove = FieldMaskConfig::remove();
        $replace = FieldMaskConfig::replace('MASKED');
        $this->assertSame('mask_regex', $regex->type);
        $this->assertSame('remove', $remove->type);
        $this->assertSame('replace', $replace->type);
        $this->assertSame('MASKED', $replace->replacement);
    }

    public function testRecursiveMasking(): void
    {
        $patterns = DefaultPatterns::get();
        $processor = $this->createProcessor($patterns);
        $record = new LogRecord(
            datetime: new JsonSerializableDateTimeImmutable(true),
            channel: 'test',
            level: Level::Info,
            message: 'Sensitive info',
            context: [
                'user' => [
                    TestConstants::CONTEXT_EMAIL => self::TEST_EMAIL,
                    'ssn' => self::TEST_HETU,
                    'card' => self::TEST_CC,
                ],
                'other' => 'plain',
            ],
            extra: []
        );
        $processed = $processor($record);
        $this->assertSame(self::MASKED_EMAIL, $processed->context['user'][TestConstants::CONTEXT_EMAIL]);
        $this->assertSame(Mask::MASK_HETU, $processed->context['user']['ssn']);
        $this->assertSame(Mask::MASK_CC, $processed->context['user']['card']);
    }

    public function testStringReplacementBackwardCompatibility(): void
    {
        $patterns = DefaultPatterns::get();
        $fieldPaths = [
            TestConstants::FIELD_USER_EMAIL => Mask::MASK_BRACKETS, // string, not FieldMaskConfig
        ];
        $processor = $this->createProcessor($patterns, $fieldPaths);
        $record = new LogRecord(
            datetime: new JsonSerializableDateTimeImmutable(true),
            channel: 'test',
            level: Level::Info,
            message: static::USER_REGISTERED,
            context: ['user' => [TestConstants::CONTEXT_EMAIL => self::TEST_EMAIL]],
            extra: []
        );
        $processed = $processor($record);
        $this->assertSame(Mask::MASK_BRACKETS, $processed->context['user'][TestConstants::CONTEXT_EMAIL]);
    }

    public function testNonStringValueInContext(): void
    {
        $patterns = DefaultPatterns::get();
        $fieldPaths = [
            'user.id' => FieldMaskConfig::useProcessorPatterns(),
        ];
        $processor = $this->createProcessor($patterns, $fieldPaths);
        $record = new LogRecord(
            datetime: new JsonSerializableDateTimeImmutable(true),
            channel: 'test',
            level: Level::Info,
            message: 'User registered',
            context: ['user' => ['id' => 12345]],
            extra: []
        );
        $processed = $processor($record);
        $this->assertSame(TestConstants::DATA_NUMBER_STRING, $processed->context['user']['id']);
    }

    public function testMissingFieldInContext(): void
    {
        $patterns = DefaultPatterns::get();
        $fieldPaths = [
            'user.missing' => FieldMaskConfig::useProcessorPatterns(),
        ];
        $processor = $this->createProcessor($patterns, $fieldPaths);
        $record = new LogRecord(
            datetime: new JsonSerializableDateTimeImmutable(true),
            channel: 'test',
            level: Level::Info,
            message: static::USER_REGISTERED,
            context: ['user' => [TestConstants::CONTEXT_EMAIL => self::TEST_EMAIL]],
            extra: []
        );
        $processed = $processor($record);
        $this->assertArrayNotHasKey('missing', $processed->context['user']);
    }

    public function testInvalidRegexPatternThrowsExceptionOnConstruction(): void
    {
        // Test that invalid regex patterns are caught during construction
        $this->expectException(InvalidRegexPatternException::class);

        $this->expectExceptionMessage("Invalid regex pattern '/[invalid/'");

        $this->createProcessor([self::INVALID_REGEX => 'MASKED']);
    }

    public function testValidRegexPatternsAreAcceptedDuringConstruction(): void
    {
        // Test that valid regex patterns work correctly
        $validPatterns = [
            TestConstants::PATTERN_TEST => 'REPLACED',
            TestConstants::PATTERN_DIGITS => 'NUMBER',
            '/[a-z]+/' => 'LETTERS'
        ];

        $processor = $this->createProcessor($validPatterns);
        $this->assertInstanceOf(GdprProcessor::class, $processor);

        // Test that the patterns actually work
        $result = $processor->maskMessage('test 123 abc');
        $this->assertStringContainsString('REPLACED', $result);
        $this->assertStringContainsString('NUMBER', $result);
        $this->assertStringContainsString('LETTERS', $result);
    }

    public function testIncompleteRegexPatternThrowsExceptionOnConstruction(): void
    {
        // Test that incomplete regex patterns are caught during construction
        $this->expectException(InvalidRegexPatternException::class);

        $this->expectExceptionMessage("Invalid regex pattern '/(unclosed['");

        $this->createProcessor(['/(unclosed[' => 'REPLACED']);
    }

    public function testRegExpMessageReturnsOriginalIfResultIsEmptyString(): void
    {
        $patterns = [
            '/^foo$/' => '',
        ];
        $processor = $this->createProcessor($patterns);
        $result = $processor->regExpMessage('foo');
        $this->assertSame('foo', $result, 'Should return original message if preg_replace result is empty string');
    }

    public function testRegExpMessageReturnsOriginalIfResultIsStringZero(): void
    {
        $patterns = [
            '/^foo$/' => '0',
        ];
        $processor = $this->createProcessor($patterns);
        $result = $processor->regExpMessage('foo');
        $this->assertSame('foo', $result, 'Should return original message if preg_replace result is string "0"');
    }

    public function testCreateRateLimitedAuditLoggerReturnsRateLimitedLogger(): void
    {
        $auditLog = [];
        $auditLogger = function (string $path, mixed $original, mixed $masked) use (&$auditLog): void {
            $auditLog[] = ['path' => $path, 'original' => $original, TestConstants::DATA_MASKED => $masked];
        };

        /** @psalm-suppress DeprecatedMethod */
        $rateLimitedLogger = GdprProcessor::createRateLimitedAuditLogger($auditLogger, 'testing');

        $this->assertInstanceOf(RateLimitedAuditLogger::class, $rateLimitedLogger);
    }

    public function testCreateArrayAuditLoggerReturnsCallable(): void
    {
        $logStorage = [];

        /** @psalm-suppress DeprecatedMethod */
        $logger = GdprProcessor::createArrayAuditLogger($logStorage, false);

        // Logger is a Closure which is callable
        $this->assertInstanceOf(\Closure::class, $logger);
    }

    public function testCreateArrayAuditLoggerWithRateLimiting(): void
    {
        $logStorage = [];

        /** @psalm-suppress DeprecatedMethod */
        $logger = GdprProcessor::createArrayAuditLogger($logStorage, true);

        $this->assertInstanceOf(RateLimitedAuditLogger::class, $logger);
    }

    public function testValidatePatternsArraySucceedsWithValidPatterns(): void
    {
        $patterns = [
            TestConstants::PATTERN_EMAIL_FULL => Mask::MASK_EMAIL,
            TestConstants::PATTERN_SSN_FORMAT => Mask::MASK_SSN,
        ];

        // Should not throw
        GdprProcessor::validatePatternsArray($patterns);
        $this->assertTrue(true);
    }

    public function testValidatePatternsArrayThrowsForInvalidPattern(): void
    {
        $this->expectException(PatternValidationException::class);

        $patterns = [
            '/[invalid/' => 'MASKED',
        ];

        GdprProcessor::validatePatternsArray($patterns);
    }

    public function testGetOrchestratorReturnsOrchestrator(): void
    {
        $processor = $this->createProcessor([TestConstants::PATTERN_TEST => Mask::MASK_GENERIC]);

        $orchestrator = $processor->getOrchestrator();

        $this->assertInstanceOf(MaskingOrchestrator::class, $orchestrator);
    }

    public function testSetAuditLoggerUpdatesLogger(): void
    {
        $auditLog = [];
        $auditLogger = function (string $path, mixed $original, mixed $masked) use (&$auditLog): void {
            $auditLog[] = ['path' => $path, 'original' => $original, TestConstants::DATA_MASKED => $masked];
        };

        $processor = $this->createProcessor(
            [TestConstants::PATTERN_SECRET => Mask::MASK_MASKED],
            [TestConstants::FIELD_USER_PASSWORD => FieldMaskConfig::replace(Mask::MASK_MASKED)]
        );

        // Initially no audit logger
        $processor->setAuditLogger($auditLogger);

        $record = $this->createLogRecord(
            context: ['user' => [TestConstants::CONTEXT_PASSWORD => TestConstants::PASSWORD]]
        );
        $processor($record);

        $this->assertNotEmpty($auditLog);
    }

    public function testSetAuditLoggerToNull(): void
    {
        $auditLog = [];
        $auditLogger = function (string $path, mixed $original, mixed $masked) use (&$auditLog): void {
            $auditLog[] = ['path' => $path, 'original' => $original, TestConstants::DATA_MASKED => $masked];
        };

        $processor = $this->createProcessor(
            [TestConstants::PATTERN_SECRET => Mask::MASK_MASKED],
            [TestConstants::FIELD_USER_PASSWORD => FieldMaskConfig::replace(Mask::MASK_MASKED)],
            [],
            $auditLogger
        );

        // Set to null
        $processor->setAuditLogger(null);

        $record = $this->createLogRecord(
            context: ['user' => [TestConstants::CONTEXT_PASSWORD => TestConstants::PASSWORD]]
        );
        $processor($record);

        // Audit log should be empty because logger was set to null
        $this->assertEmpty($auditLog);
    }

    public function testMaskMessageHandlesEmptyPatterns(): void
    {
        $processor = $this->createProcessor([]);

        $result = $processor->maskMessage('test message with nothing to mask');

        $this->assertSame('test message with nothing to mask', $result);
    }

    public function testMaskMessageAppliesAllPatterns(): void
    {
        $processor = $this->createProcessor([
            '/foo/' => 'bar',
            '/baz/' => 'qux',
        ]);

        $result = $processor->maskMessage('foo and baz');

        $this->assertSame('bar and qux', $result);
    }
}
