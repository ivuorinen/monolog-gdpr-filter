<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Monolog\LogRecord;
use Monolog\Level;
use Monolog\JsonSerializableDateTimeImmutable;

/**
 * Unit tests for GDPR processor.
 *
 * @api
 */
#[CoversClass(GdprProcessor::class)]
#[CoversMethod(GdprProcessor::class, '__invoke')]
#[CoversMethod(GdprProcessor::class, 'getDefaultPatterns')]
#[CoversMethod(GdprProcessor::class, 'maskMessage')]
#[CoversMethod(GdprProcessor::class, 'maskWithRegex')]
#[CoversMethod(GdprProcessor::class, 'recursiveMask')]
#[CoversMethod(GdprProcessor::class, 'regExpMessage')]
#[CoversMethod(GdprProcessor::class, 'removeField')]
#[CoversMethod(GdprProcessor::class, 'replaceWith')]
class GdprProcessorTest extends TestCase
{
    use TestHelpers;

    public function testMaskWithRegexField(): void
    {
        $patterns = GdprProcessor::getDefaultPatterns();
        $fieldPaths = [
            'user.email' => GdprProcessor::maskWithRegex(),
        ];
        $processor = new GdprProcessor($patterns, $fieldPaths);
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
        $patterns = GdprProcessor::getDefaultPatterns();
        $fieldPaths = [
            'user.ssn' => GdprProcessor::removeField(),
        ];
        $processor = new GdprProcessor($patterns, $fieldPaths);
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
        $patterns = GdprProcessor::getDefaultPatterns();
        $fieldPaths = [
            'user.card' => GdprProcessor::replaceWith('MASKED'),
        ];
        $processor = new GdprProcessor($patterns, $fieldPaths);
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
        $patterns = GdprProcessor::getDefaultPatterns();
        $fieldPaths = [
            'user.name' => GdprProcessor::maskWithRegex(),
        ];
        $customCallbacks = [
            'user.name' => fn($value): string => strtoupper((string) $value),
        ];
        $processor = new GdprProcessor($patterns, $fieldPaths, $customCallbacks);
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
        $patterns = GdprProcessor::getDefaultPatterns();
        $fieldPaths = [
            'user.email' => GdprProcessor::maskWithRegex(),
        ];
        $auditCalls = [];
        $auditLogger = function ($path, $original, $masked) use (&$auditCalls): void {
            $auditCalls[] = [$path, $original, $masked];
        };
        $processor = new GdprProcessor($patterns, $fieldPaths, [], $auditLogger);
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
        $this->assertSame(['user.email', 'john.doe@example.com', '***EMAIL***'], $auditCalls[0]);
    }

    public function testMaskMessage(): void
    {
        $patterns = [
            '/foo/' => 'bar',
            '/baz/' => 'qux',
        ];
        $processor = new GdprProcessor($patterns);
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
        $regex = GdprProcessor::maskWithRegex();
        $remove = GdprProcessor::removeField();
        $replace = GdprProcessor::replaceWith('MASKED');
        $this->assertSame('mask_regex', $regex->type);
        $this->assertSame('remove', $remove->type);
        $this->assertSame('replace', $replace->type);
        $this->assertSame('MASKED', $replace->replacement);
    }

    public function testRecursiveMasking(): void
    {
        $patterns = GdprProcessor::getDefaultPatterns();
        $processor = new GdprProcessor($patterns);
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
        $this->assertSame('***HETU***', $processed->context['user']['ssn']);
        $this->assertSame('***CC***', $processed->context['user']['card']);
    }

    public function testStringReplacementBackwardCompatibility(): void
    {
        $patterns = GdprProcessor::getDefaultPatterns();
        $fieldPaths = [
            'user.email' => '[MASKED]', // string, not FieldMaskConfig
        ];
        $processor = new GdprProcessor($patterns, $fieldPaths);
        $record = new LogRecord(
            datetime: new JsonSerializableDateTimeImmutable(true),
            channel: 'test',
            level: Level::Info,
            message: static::USER_REGISTERED,
            context: ['user' => ['email' => self::TEST_EMAIL]],
            extra: []
        );
        $processed = $processor($record);
        $this->assertSame('[MASKED]', $processed->context['user']['email']);
    }

    public function testNonStringValueInContext(): void
    {
        $patterns = GdprProcessor::getDefaultPatterns();
        $fieldPaths = [
            'user.id' => GdprProcessor::maskWithRegex(),
        ];
        $processor = new GdprProcessor($patterns, $fieldPaths);
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
        $patterns = GdprProcessor::getDefaultPatterns();
        $fieldPaths = [
            'user.missing' => GdprProcessor::maskWithRegex(),
        ];
        $processor = new GdprProcessor($patterns, $fieldPaths);
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
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid regex pattern: '/[invalid/'");

        new GdprProcessor([self::INVALID_REGEX => 'MASKED']);
    }

    public function testValidRegexPatternsAreAcceptedDuringConstruction(): void
    {
        // Test that valid regex patterns work correctly
        $validPatterns = [
            '/test/' => 'REPLACED',
            '/\d+/' => 'NUMBER',
            '/[a-z]+/' => 'LETTERS'
        ];

        $processor = new GdprProcessor($validPatterns);
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
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid regex pattern: '/(unclosed['");

        new GdprProcessor(['/(unclosed[' => 'REPLACED']);
    }

    public function testRegExpMessageReturnsOriginalIfResultIsEmptyString(): void
    {
        $patterns = [
            '/^foo$/' => '',
        ];
        $processor = new GdprProcessor($patterns);
        $result = $processor->regExpMessage('foo');
        $this->assertSame('foo', $result, 'Should return original message if preg_replace result is empty string');
    }

    public function testRegExpMessageReturnsOriginalIfResultIsStringZero(): void
    {
        $patterns = [
            '/^foo$/' => '0',
        ];
        $processor = new GdprProcessor($patterns);
        $result = $processor->regExpMessage('foo');
        $this->assertSame('foo', $result, 'Should return original message if preg_replace result is string "0"');
    }
}
