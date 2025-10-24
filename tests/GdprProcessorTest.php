<?php

declare(strict_types=1);

namespace Tests;

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
            'user.email' => FieldMaskConfig::useProcessorPatterns(),
        ];
        $processor = $this->createProcessor($patterns, $fieldPaths);
        $record = new LogRecord(
            datetime: new JsonSerializableDateTimeImmutable(true),
            channel: 'test',
            level: Level::Info,
            message: static::USER_REGISTERED,
            context: ['user' => ['email' => self::TEST_EMAIL]],
            extra: []
        );
        $processed = $processor($record);
        $this->assertSame(self::MASKED_EMAIL, $processed->context['user']['email']);
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
            context: ['user' => ['ssn' => '123456-789A', 'name' => 'John']],
            extra: []
        );
        $processed = $processor($record);
        $this->assertArrayNotHasKey('ssn', $processed->context['user']);
        $this->assertSame('John', $processed->context['user']['name']);
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
            'user.name' => FieldMaskConfig::useProcessorPatterns(),
        ];
        $customCallbacks = [
            'user.name' => fn($value): string => strtoupper((string) $value),
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
            'user.email' => FieldMaskConfig::useProcessorPatterns(),
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
            context: ['user' => ['email' => static::TEST_EMAIL]],
            extra: []
        );
        $processor($record);
        $this->assertNotEmpty($auditCalls);
        $this->assertSame(['user.email', 'john.doe@example.com', Mask::MASK_EMAIL], $auditCalls[0]);
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
            '/secret/' => self::MASKED_SECRET,
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
                    'email' => self::TEST_EMAIL,
                    'ssn' => self::TEST_HETU,
                    'card' => self::TEST_CC,
                ],
                'other' => 'plain',
            ],
            extra: []
        );
        $processed = $processor($record);
        $this->assertSame(self::MASKED_EMAIL, $processed->context['user']['email']);
        $this->assertSame(Mask::MASK_HETU, $processed->context['user']['ssn']);
        $this->assertSame(Mask::MASK_CC, $processed->context['user']['card']);
    }

    public function testStringReplacementBackwardCompatibility(): void
    {
        $patterns = DefaultPatterns::get();
        $fieldPaths = [
            'user.email' => Mask::MASK_BRACKETS, // string, not FieldMaskConfig
        ];
        $processor = $this->createProcessor($patterns, $fieldPaths);
        $record = new LogRecord(
            datetime: new JsonSerializableDateTimeImmutable(true),
            channel: 'test',
            level: Level::Info,
            message: static::USER_REGISTERED,
            context: ['user' => ['email' => self::TEST_EMAIL]],
            extra: []
        );
        $processed = $processor($record);
        $this->assertSame(Mask::MASK_BRACKETS, $processed->context['user']['email']);
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
        $this->assertSame('12345', $processed->context['user']['id']);
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
            context: ['user' => ['email' => self::TEST_EMAIL]],
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
            '/test/' => 'REPLACED',
            '/\d+/' => 'NUMBER',
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
}
