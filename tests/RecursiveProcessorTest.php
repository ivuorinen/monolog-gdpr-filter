<?php

declare(strict_types=1);

namespace Tests;

use Ivuorinen\MonologGdprFilter\DataTypeMasker;
use Ivuorinen\MonologGdprFilter\MaskConstants;
use Ivuorinen\MonologGdprFilter\RecursiveProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\TestConstants;

#[CoversClass(RecursiveProcessor::class)]
final class RecursiveProcessorTest extends TestCase
{
    public function testRecursiveMaskWithString(): void
    {
        $regexProcessor = fn(string $val): string => str_replace('secret', MaskConstants::MASK_GENERIC, $val);
        $dataTypeMasker = new DataTypeMasker([]);
        $processor = new RecursiveProcessor($regexProcessor, $dataTypeMasker, null, 10);

        $result = $processor->recursiveMask('This is secret data');

        $this->assertSame('This is *** data', $result);
    }

    public function testRecursiveMaskWithArray(): void
    {
        $regexProcessor = fn(string $val): string => str_replace('secret', MaskConstants::MASK_GENERIC, $val);
        $dataTypeMasker = new DataTypeMasker([]);
        $processor = new RecursiveProcessor($regexProcessor, $dataTypeMasker, null, 10);

        $result = $processor->recursiveMask(['key' => 'secret value']);

        $this->assertSame(['key' => '*** value'], $result);
    }

    public function testProcessArrayDataWithMaxDepthReached(): void
    {
        $regexProcessor = fn(string $val): string => $val;
        $dataTypeMasker = new DataTypeMasker([]);

        $auditLog = [];
        $auditLogger = function (string $path, mixed $original, mixed $masked) use (&$auditLog): void {
            $auditLog[] = ['path' => $path, 'original' => $original, TestConstants::DATA_MASKED => $masked];
        };

        $processor = new RecursiveProcessor($regexProcessor, $dataTypeMasker, $auditLogger, 2);

        $data = ['level1' => ['level2' => ['level3' => 'value']]];
        $result = $processor->processArrayData($data, 2);

        // Should return unmodified data at max depth
        $this->assertSame($data, $result);
        // Should log the depth limit
        $this->assertCount(1, $auditLog);
        $this->assertSame('max_depth_reached', $auditLog[0]['path']);
    }

    public function testProcessArrayDataWithEmptyArray(): void
    {
        $regexProcessor = fn(string $val): string => $val;
        $dataTypeMasker = new DataTypeMasker([]);
        $processor = new RecursiveProcessor($regexProcessor, $dataTypeMasker, null, 10);

        $result = $processor->processArrayData([], 0);

        $this->assertSame([], $result);
    }

    public function testProcessLargeArray(): void
    {
        $regexProcessor = fn(string $val): string => str_replace('test', MaskConstants::MASK_GENERIC, $val);
        $dataTypeMasker = new DataTypeMasker([]);
        $processor = new RecursiveProcessor($regexProcessor, $dataTypeMasker, null, 10);

        // Create an array with more than 1000 items
        $data = [];
        for ($i = 0; $i < 1500; $i++) {
            $data['key' . $i] = 'test value ' . $i;
        }

        $result = $processor->processLargeArray($data, 0, 1000);

        $this->assertCount(1500, $result);
        $this->assertSame('*** value 0', $result['key0']);
        $this->assertSame('*** value 1499', $result['key1499']);
    }

    public function testProcessLargeArrayTriggersGarbageCollection(): void
    {
        $regexProcessor = fn(string $val): string => $val;
        $dataTypeMasker = new DataTypeMasker([]);
        $processor = new RecursiveProcessor($regexProcessor, $dataTypeMasker, null, 10);

        // Create an array with more than 10000 items to trigger gc
        $data = [];
        for ($i = 0; $i < 11000; $i++) {
            $data['key' . $i] = 'value' . $i;
        }

        $result = $processor->processLargeArray($data, 0, 1000);

        $this->assertCount(11000, $result);
    }

    public function testProcessStandardArray(): void
    {
        $regexProcessor = fn(string $val): string => str_replace('secret', MaskConstants::MASK_GENERIC, $val);
        $dataTypeMasker = new DataTypeMasker([]);
        $processor = new RecursiveProcessor($regexProcessor, $dataTypeMasker, null, 10);

        $data = ['field1' => 'secret data', 'field2' => TestConstants::DATA_PUBLIC];
        $result = $processor->processStandardArray($data, 0);

        $this->assertSame('*** data', $result['field1']);
        $this->assertSame(TestConstants::DATA_PUBLIC, $result['field2']);
    }

    public function testProcessValueWithString(): void
    {
        $regexProcessor = fn(string $val): string => str_replace('test', MaskConstants::MASK_GENERIC, $val);
        $dataTypeMasker = new DataTypeMasker([]);
        $processor = new RecursiveProcessor($regexProcessor, $dataTypeMasker, null, 10);

        $result = $processor->processValue('test string', 0);

        $this->assertSame('*** string', $result);
    }

    public function testProcessValueWithArray(): void
    {
        $regexProcessor = fn(string $val): string => str_replace('secret', MaskConstants::MASK_GENERIC, $val);
        $dataTypeMasker = new DataTypeMasker([]);
        $processor = new RecursiveProcessor($regexProcessor, $dataTypeMasker, null, 10);

        $result = $processor->processValue(['nested' => 'secret value'], 0);

        $this->assertIsArray($result);
        $this->assertSame('*** value', $result['nested']);
    }

    public function testProcessValueWithOtherType(): void
    {
        $regexProcessor = fn(string $val): string => $val;
        $dataTypeMasker = new DataTypeMasker(['integer' => MaskConstants::MASK_INT]);
        $processor = new RecursiveProcessor($regexProcessor, $dataTypeMasker, null, 10);

        $result = $processor->processValue(42, 0);

        $this->assertSame(MaskConstants::MASK_INT, $result);
    }

    public function testProcessStringValueWithRegexMatch(): void
    {
        $regexProcessor = fn(string $val): string => str_replace(TestConstants::CONTEXT_PASSWORD, '[REDACTED]', $val);
        $dataTypeMasker = new DataTypeMasker([]);
        $processor = new RecursiveProcessor($regexProcessor, $dataTypeMasker, null, 10);

        $result = $processor->processStringValue('password: secret');

        $this->assertSame('[REDACTED]: secret', $result);
    }

    public function testProcessStringValueWithoutRegexMatch(): void
    {
        $regexProcessor = fn(string $val): string => $val; // No change
        $dataTypeMasker = new DataTypeMasker(['string' => MaskConstants::MASK_STRING]);
        $processor = new RecursiveProcessor($regexProcessor, $dataTypeMasker, null, 10);

        $result = $processor->processStringValue('normal text');

        // Should apply data type masking when regex doesn't match
        $this->assertSame(MaskConstants::MASK_STRING, $result);
    }

    public function testProcessArrayValueWithDataTypeMasking(): void
    {
        $regexProcessor = fn(string $val): string => $val;
        $dataTypeMasker = new DataTypeMasker(['array' => MaskConstants::MASK_ARRAY]);
        $processor = new RecursiveProcessor($regexProcessor, $dataTypeMasker, null, 10);

        $result = $processor->processArrayValue(['key' => 'value'], 0);

        // When data type masking is applied, it returns an array with the masked value
        $this->assertIsArray($result);
        $this->assertSame(MaskConstants::MASK_ARRAY, $result[0]);
    }

    public function testProcessArrayValueWithoutDataTypeMasking(): void
    {
        $regexProcessor = fn(string $val): string => str_replace('secret', MaskConstants::MASK_GENERIC, $val);
        $dataTypeMasker = new DataTypeMasker([]);
        $processor = new RecursiveProcessor($regexProcessor, $dataTypeMasker, null, 10);

        $result = $processor->processArrayValue(['key' => 'secret data'], 0);

        $this->assertIsArray($result);
        $this->assertSame('*** data', $result['key']);
    }

    public function testSetAuditLogger(): void
    {
        $regexProcessor = fn(string $val): string => $val;
        $dataTypeMasker = new DataTypeMasker([]);
        $processor = new RecursiveProcessor($regexProcessor, $dataTypeMasker, null, 10);

        $auditLog = [];
        $auditLogger = function (string $path, mixed $original, mixed $masked) use (&$auditLog): void {
            $auditLog[] = ['path' => $path, 'original' => $original, TestConstants::DATA_MASKED => $masked];
        };

        $processor->setAuditLogger($auditLogger);

        // Trigger max depth to use audit logger
        $processor->processArrayData(['data'], 10);

        $this->assertCount(1, $auditLog);
    }

    public function testProcessArrayDataWithMaxDepthWithoutAuditLogger(): void
    {
        $regexProcessor = fn(string $val): string => $val;
        $dataTypeMasker = new DataTypeMasker([]);
        $processor = new RecursiveProcessor($regexProcessor, $dataTypeMasker, null, 2);

        $data = ['test'];
        $result = $processor->processArrayData($data, 2);

        // Should return data without throwing
        $this->assertSame($data, $result);
    }

    public function testProcessArrayDataChoosesLargeArrayPath(): void
    {
        $regexProcessor = fn(string $val): string => $val;
        $dataTypeMasker = new DataTypeMasker([]);
        $processor = new RecursiveProcessor($regexProcessor, $dataTypeMasker, null, 10);

        // Create array with more than 1000 items
        $data = array_fill(0, 1001, 'value');

        $result = $processor->processArrayData($data, 0);

        $this->assertCount(1001, $result);
    }

    public function testProcessArrayDataChoosesStandardArrayPath(): void
    {
        $regexProcessor = fn(string $val): string => $val;
        $dataTypeMasker = new DataTypeMasker([]);
        $processor = new RecursiveProcessor($regexProcessor, $dataTypeMasker, null, 10);

        // Create array with exactly 1000 items (not > 1000)
        $data = array_fill(0, 1000, 'value');

        $result = $processor->processArrayData($data, 0);

        $this->assertCount(1000, $result);
    }
}
