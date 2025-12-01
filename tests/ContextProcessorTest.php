<?php

declare(strict_types=1);

namespace Tests;

use Adbar\Dot;
use Ivuorinen\MonologGdprFilter\ContextProcessor;
use Ivuorinen\MonologGdprFilter\Exceptions\RuleExecutionException;
use Ivuorinen\MonologGdprFilter\FieldMaskConfig;
use Ivuorinen\MonologGdprFilter\MaskConstants;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\TestConstants;

/**
 * @psalm-suppress InternalClass - Testing internal ContextProcessor class
 * @psalm-suppress InternalMethod - Testing internal methods
 */
#[CoversClass(ContextProcessor::class)]
final class ContextProcessorTest extends TestCase
{
    public function testMaskFieldPathsWithRegexMask(): void
    {
        $regexProcessor = fn(string $val): string => str_replace('test', MaskConstants::MASK_GENERIC, $val);
        $emailConfig = FieldMaskConfig::regexMask(
            TestConstants::PATTERN_TEST,
            MaskConstants::MASK_GENERIC
        );
        $processor = new ContextProcessor(
            [TestConstants::CONTEXT_EMAIL => $emailConfig],
            [],
            null,
            $regexProcessor
        );

        $accessor = new Dot([TestConstants::CONTEXT_EMAIL => TestConstants::EMAIL_TEST]);
        $processed = $processor->maskFieldPaths($accessor);

        $this->assertSame([TestConstants::CONTEXT_EMAIL], $processed);
        $this->assertSame('***@example.com', $accessor->get(TestConstants::CONTEXT_EMAIL));
    }

    public function testMaskFieldPathsWithRemove(): void
    {
        $regexProcessor = fn(string $val): string => $val;
        $processor = new ContextProcessor(
            ['secret' => FieldMaskConfig::remove()],
            [],
            null,
            $regexProcessor
        );

        $accessor = new Dot(['secret' => 'confidential', 'public' => 'data']);
        $processed = $processor->maskFieldPaths($accessor);

        $this->assertSame(['secret'], $processed);
        $this->assertFalse($accessor->has('secret'));
        $this->assertTrue($accessor->has('public'));
    }

    public function testMaskFieldPathsWithReplace(): void
    {
        $regexProcessor = fn(string $val): string => $val;
        $processor = new ContextProcessor(
            [TestConstants::CONTEXT_PASSWORD => FieldMaskConfig::replace('[REDACTED]')],
            [],
            null,
            $regexProcessor
        );

        $accessor = new Dot([TestConstants::CONTEXT_PASSWORD => 'secret123']);
        $processed = $processor->maskFieldPaths($accessor);

        $this->assertSame([TestConstants::CONTEXT_PASSWORD], $processed);
        $this->assertSame('[REDACTED]', $accessor->get(TestConstants::CONTEXT_PASSWORD));
    }

    public function testMaskFieldPathsSkipsNonExistentPaths(): void
    {
        $regexProcessor = fn(string $val): string => $val;
        $processor = new ContextProcessor(
            ['nonexistent' => FieldMaskConfig::replace(MaskConstants::MASK_GENERIC)],
            [],
            null,
            $regexProcessor
        );

        $accessor = new Dot(['other' => 'value']);
        $processed = $processor->maskFieldPaths($accessor);

        $this->assertSame([], $processed);
        $this->assertSame('value', $accessor->get('other'));
    }

    public function testMaskFieldPathsWithAuditLogger(): void
    {
        $auditLog = [];
        $auditLogger = function (string $path, mixed $original, mixed $masked) use (&$auditLog): void {
            $auditLog[] = ['path' => $path, 'original' => $original, TestConstants::DATA_MASKED => $masked];
        };

        $regexProcessor = fn(string $val): string => $val;
        $processor = new ContextProcessor(
            ['field' => FieldMaskConfig::replace(MaskConstants::MASK_GENERIC)],
            [],
            $auditLogger,
            $regexProcessor
        );

        $accessor = new Dot(['field' => 'value']);
        $processor->maskFieldPaths($accessor);

        $this->assertCount(1, $auditLog);
        $this->assertSame('field', $auditLog[0]['path']);
        $this->assertSame('value', $auditLog[0]['original']);
        $this->assertSame(MaskConstants::MASK_GENERIC, $auditLog[0][TestConstants::DATA_MASKED]);
    }

    public function testMaskFieldPathsWithRemoveLogsAudit(): void
    {
        $auditLog = [];
        $auditLogger = function (string $path, mixed $original, mixed $masked) use (&$auditLog): void {
            $auditLog[] = ['path' => $path, 'original' => $original, TestConstants::DATA_MASKED => $masked];
        };

        $regexProcessor = fn(string $val): string => $val;
        $processor = new ContextProcessor(
            ['secret' => FieldMaskConfig::remove()],
            [],
            $auditLogger,
            $regexProcessor
        );

        $accessor = new Dot(['secret' => 'data']);
        $processor->maskFieldPaths($accessor);

        $this->assertCount(1, $auditLog);
        $this->assertSame('secret', $auditLog[0]['path']);
        $this->assertNull($auditLog[0][TestConstants::DATA_MASKED]);
    }

    public function testProcessCustomCallbacksSuccess(): void
    {
        $regexProcessor = fn(string $val): string => $val;
        $callback = fn(mixed $val): string => strtoupper((string) $val);

        $processor = new ContextProcessor(
            [],
            ['name' => $callback],
            null,
            $regexProcessor
        );

        $accessor = new Dot(['name' => 'john']);
        $processed = $processor->processCustomCallbacks($accessor);

        $this->assertSame(['name'], $processed);
        $this->assertSame('JOHN', $accessor->get('name'));
    }

    public function testProcessCustomCallbacksSkipsNonExistent(): void
    {
        $regexProcessor = fn(string $val): string => $val;
        $callback = fn(mixed $val): string => TestConstants::DATA_MASKED;

        $processor = new ContextProcessor(
            [],
            ['missing' => $callback],
            null,
            $regexProcessor
        );

        $accessor = new Dot(['other' => 'value']);
        $processed = $processor->processCustomCallbacks($accessor);

        $this->assertSame([], $processed);
    }

    public function testProcessCustomCallbacksWithException(): void
    {
        $auditLog = [];
        $auditLogger = function (string $path, mixed $original, mixed $masked) use (&$auditLog): void {
            $auditLog[] = ['path' => $path, 'original' => $original, TestConstants::DATA_MASKED => $masked];
        };

        $regexProcessor = fn(string $val): string => $val;
        $callback = function (): never {
            throw new RuleExecutionException('Callback error');
        };

        $processor = new ContextProcessor(
            [],
            ['field' => $callback],
            $auditLogger,
            $regexProcessor
        );

        $accessor = new Dot(['field' => 'value']);
        $processed = $processor->processCustomCallbacks($accessor);

        $this->assertSame(['field'], $processed);
        // Field value should remain unchanged after exception
        $this->assertSame('value', $accessor->get('field'));
        // Should log the error
        $this->assertCount(1, $auditLog);
        $this->assertStringContainsString('_callback_error', $auditLog[0]['path']);
    }

    public function testProcessCustomCallbacksWithAuditLog(): void
    {
        $auditLog = [];
        $auditLogger = function (string $path, mixed $original, mixed $masked) use (&$auditLog): void {
            $auditLog[] = ['path' => $path, 'original' => $original, TestConstants::DATA_MASKED => $masked];
        };

        $regexProcessor = fn(string $val): string => $val;
        $callback = fn(mixed $val): string => TestConstants::DATA_MASKED;

        $processor = new ContextProcessor(
            [],
            ['field' => $callback],
            $auditLogger,
            $regexProcessor
        );

        $accessor = new Dot(['field' => 'original']);
        $processor->processCustomCallbacks($accessor);

        $this->assertCount(1, $auditLog);
        $this->assertSame('field', $auditLog[0]['path']);
        $this->assertSame('original', $auditLog[0]['original']);
        $this->assertSame(TestConstants::DATA_MASKED, $auditLog[0][TestConstants::DATA_MASKED]);
    }

    public function testMaskValueWithCallback(): void
    {
        $regexProcessor = fn(string $val): string => $val;
        $callback = fn(mixed $val): string => 'callback_result';

        $processor = new ContextProcessor(
            [],
            ['path' => $callback],
            null,
            $regexProcessor
        );

        $result = $processor->maskValue('path', 'value', null);

        $this->assertSame('callback_result', $result[TestConstants::DATA_MASKED]);
        $this->assertFalse($result['remove']);
    }

    public function testMaskValueWithStringConfig(): void
    {
        $regexProcessor = fn(string $val): string => $val;
        $processor = new ContextProcessor([], [], null, $regexProcessor);

        $result = $processor->maskValue('path', 'value', 'replacement');

        $this->assertSame('replacement', $result[TestConstants::DATA_MASKED]);
        $this->assertFalse($result['remove']);
    }

    public function testMaskValueWithUnknownFieldMaskConfigType(): void
    {
        $regexProcessor = fn(string $val): string => $val;
        $processor = new ContextProcessor([], [], null, $regexProcessor);

        // Create a config with an unknown type by using reflection
        $reflection = new \ReflectionClass(FieldMaskConfig::class);
        $config = $reflection->newInstanceWithoutConstructor();
        $typeProp = $reflection->getProperty('type');
        $typeProp->setValue($config, 'unknown_type');

        $result = $processor->maskValue('path', 'value', $config);

        $this->assertSame('unknown_type', $result[TestConstants::DATA_MASKED]);
        $this->assertFalse($result['remove']);
    }

    public function testLogAuditDoesNothingWhenNoLogger(): void
    {
        $regexProcessor = fn(string $val): string => $val;
        $processor = new ContextProcessor([], [], null, $regexProcessor);

        // Should not throw
        $processor->logAudit('path', 'original', TestConstants::DATA_MASKED);
        $this->assertTrue(true);
    }

    public function testLogAuditDoesNothingWhenValuesUnchanged(): void
    {
        $auditLog = [];
        $auditLogger = function (string $path, mixed $original, mixed $masked) use (&$auditLog): void {
            $auditLog[] = ['path' => $path, 'original' => $original, TestConstants::DATA_MASKED => $masked];
        };

        $regexProcessor = fn(string $val): string => $val;
        $processor = new ContextProcessor([], [], $auditLogger, $regexProcessor);

        $processor->logAudit('path', 'same', 'same');
        $this->assertCount(0, $auditLog);
    }

    public function testSetAuditLogger(): void
    {
        $auditLog = [];
        $auditLogger = function (string $path, mixed $original, mixed $masked) use (&$auditLog): void {
            $auditLog[] = ['path' => $path, 'original' => $original, TestConstants::DATA_MASKED => $masked];
        };

        $regexProcessor = fn(string $val): string => $val;
        $processor = new ContextProcessor([], [], null, $regexProcessor);

        $processor->setAuditLogger($auditLogger);
        $processor->logAudit('path', 'original', TestConstants::DATA_MASKED);

        $this->assertCount(1, $auditLog);
    }

    public function testProcessCustomCallbacksDoesNotLogWhenValueUnchanged(): void
    {
        $auditLog = [];
        $auditLogger = function (string $path, mixed $original, mixed $masked) use (&$auditLog): void {
            $auditLog[] = ['path' => $path, 'original' => $original, TestConstants::DATA_MASKED => $masked];
        };

        $regexProcessor = fn(string $val): string => $val;
        $callback = fn(mixed $val): mixed => $val; // Returns same value

        $processor = new ContextProcessor(
            [],
            ['field' => $callback],
            $auditLogger,
            $regexProcessor
        );

        $accessor = new Dot(['field' => 'value']);
        $processor->processCustomCallbacks($accessor);

        // Should not log when value unchanged
        $this->assertCount(0, $auditLog);
    }
}
