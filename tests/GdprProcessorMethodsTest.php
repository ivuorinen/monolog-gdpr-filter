<?php

declare(strict_types=1);

namespace Tests;

use Tests\TestConstants;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\ContextProcessor;
use Ivuorinen\MonologGdprFilter\FieldMaskConfig;
use PHPUnit\Framework\TestCase;

/**
 * GDPR Processor Methods Test
 *
 * @api
 */
#[CoversClass(className: GdprProcessor::class)]
#[CoversClass(className: ContextProcessor::class)]
#[CoversMethod(className: GdprProcessor::class, methodName: '__invoke')]
#[CoversMethod(className: ContextProcessor::class, methodName: 'maskValue')]
#[CoversMethod(className: ContextProcessor::class, methodName: 'logAudit')]
class GdprProcessorMethodsTest extends TestCase
{
    use TestHelpers;

    public function testMaskFieldPathsSetsMaskedValueAndRemovesField(): void
    {
        $patterns = [
            '/john.doe/' => 'bar',
        ];
        $fieldPaths = [
            TestConstants::FIELD_USER_EMAIL => FieldMaskConfig::useProcessorPatterns(),
            'user.ssn' => FieldMaskConfig::remove(),
            'user.card' => FieldMaskConfig::replace('MASKED'),
        ];
        $context = [
            'user' => [
                TestConstants::CONTEXT_EMAIL => self::TEST_EMAIL,
                'ssn' => self::TEST_HETU,
                'card' => self::TEST_CC,
            ],
        ];

        $processor = new GdprProcessor($patterns, $fieldPaths);
        $record = $this->createLogRecord(context: $context);
        $result = $processor($record);

        $this->assertSame('bar@example.com', $result->context['user'][TestConstants::CONTEXT_EMAIL]);
        $this->assertSame('MASKED', $result->context['user']['card']);
        $this->assertArrayNotHasKey('ssn', $result->context['user']);
    }

    public function testMaskValueWithCustomCallback(): void
    {
        $patterns = [];
        $fieldPaths = [
            TestConstants::FIELD_USER_NAME => FieldMaskConfig::useProcessorPatterns(),
        ];
        $customCallbacks = [
            TestConstants::FIELD_USER_NAME => fn($value): string => strtoupper((string) $value),
        ];
        $context = ['user' => ['name' => 'john']];

        $processor = new GdprProcessor($patterns, $fieldPaths, $customCallbacks);
        $record = $this->createLogRecord(context: $context);
        $result = $processor($record);

        $this->assertSame('JOHN', $result->context['user']['name']);
    }

    public function testMaskValueWithRemove(): void
    {
        $patterns = [];
        $fieldPaths = [
            'user.ssn' => FieldMaskConfig::remove(),
        ];
        $context = ['user' => ['ssn' => self::TEST_HETU]];

        $processor = new GdprProcessor($patterns, $fieldPaths);
        $record = $this->createLogRecord(context: $context);
        $result = $processor($record);

        $this->assertArrayNotHasKey('ssn', $result->context['user']);
    }

    public function testMaskValueWithReplace(): void
    {
        $patterns = [];
        $fieldPaths = [
            'user.card' => FieldMaskConfig::replace('MASKED'),
        ];
        $context = ['user' => ['card' => self::TEST_CC]];

        $processor = new GdprProcessor($patterns, $fieldPaths);
        $record = $this->createLogRecord(context: $context);
        $result = $processor($record);

        $this->assertSame('MASKED', $result->context['user']['card']);
    }

    public function testLogAuditIsCalled(): void
    {
        $patterns = [];
        $fieldPaths = [TestConstants::FIELD_USER_EMAIL => FieldMaskConfig::replace('MASKED')];
        $calls = [];
        $auditLogger = function ($path, $original, $masked) use (&$calls): void {
            $calls[] = [$path, $original, $masked];
        };
        $context = ['user' => [TestConstants::CONTEXT_EMAIL => self::TEST_EMAIL]];

        $processor = new GdprProcessor($patterns, $fieldPaths, [], $auditLogger);
        $record = $this->createLogRecord(context: $context);
        $processor($record);

        $this->assertNotEmpty($calls);
        $this->assertSame([TestConstants::FIELD_USER_EMAIL, self::TEST_EMAIL, 'MASKED'], $calls[0]);
    }

    public function testMaskValueWithDefaultCase(): void
    {
        $patterns = [];
        $fieldPaths = [
            'user.unknown' => new FieldMaskConfig('999'), // unknown type
        ];
        $context = ['user' => ['unknown' => 'foo']];

        $processor = new GdprProcessor($patterns, $fieldPaths);
        $record = $this->createLogRecord(context: $context);
        $result = $processor($record);

        $this->assertSame('999', $result->context['user']['unknown']);
    }

    public function testMaskValueWithStringConfigBackwardCompatibility(): void
    {
        $patterns = [];
        $fieldPaths = [
            'user.simple' => 'MASKED',
        ];
        $context = ['user' => ['simple' => 'foo']];

        $processor = new GdprProcessor($patterns, $fieldPaths);
        $record = $this->createLogRecord(context: $context);
        $result = $processor($record);

        $this->assertSame('MASKED', $result->context['user']['simple']);
    }
}
