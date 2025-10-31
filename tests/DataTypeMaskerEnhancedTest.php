<?php

declare(strict_types=1);

namespace Tests;

use Tests\TestConstants;
use Ivuorinen\MonologGdprFilter\DataTypeMasker;
use Ivuorinen\MonologGdprFilter\MaskConstants;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DataTypeMasker::class)]
final class DataTypeMaskerEnhancedTest extends TestCase
{
    public function testApplyMaskingWithEmptyMasks(): void
    {
        $masker = new DataTypeMasker([]);

        $result = $masker->applyMasking(42);

        // Should return unchanged
        $this->assertSame(42, $result);
    }

    public function testApplyMaskingWithUnmappedType(): void
    {
        $masker = new DataTypeMasker(['integer' => MaskConstants::MASK_INT]);

        $result = $masker->applyMasking('string value');

        // Type not in masks, should return unchanged
        $this->assertSame('string value', $result);
    }

    public function testApplyMaskingNullWithPreserve(): void
    {
        $masker = new DataTypeMasker(['NULL' => 'preserve']);

        $result = $masker->applyMasking(null);

        $this->assertNull($result);
    }

    public function testApplyMaskingBooleanWithTrueMask(): void
    {
        $masker = new DataTypeMasker(['boolean' => 'true']);

        $result = $masker->applyMasking(false);

        $this->assertTrue($result);
    }

    public function testApplyMaskingBooleanWithFalseMask(): void
    {
        $masker = new DataTypeMasker(['boolean' => 'false']);

        $result = $masker->applyMasking(true);

        $this->assertFalse($result);
    }

    public function testApplyMaskingArrayWithRecursiveMask(): void
    {
        $recursiveCallback = (fn(array $value): array => array_map(fn($v) => strtoupper((string) $v), $value));

        $masker = new DataTypeMasker(['array' => 'recursive']);

        $result = $masker->applyMasking(['test', 'data'], $recursiveCallback);

        $this->assertSame(['TEST', 'DATA'], $result);
    }

    public function testApplyToContextWithProcessedFields(): void
    {
        $masker = new DataTypeMasker(['integer' => MaskConstants::MASK_INT]);

        $context = [
            'processed' => 123,
            'unprocessed' => 456
        ];

        $result = $masker->applyToContext($context, ['processed']);

        $this->assertSame(123, $result['processed']); // Should remain unchanged
        $this->assertSame(MaskConstants::MASK_INT, $result['unprocessed']); // Should be masked
    }

    public function testApplyToContextWithNestedArrays(): void
    {
        $masker = new DataTypeMasker(['integer' => MaskConstants::MASK_INT]);

        $context = [
            'user' => [
                'id' => 123,
                'profile' => [
                    'age' => 30
                ]
            ]
        ];

        $result = $masker->applyToContext($context);

        $this->assertSame(MaskConstants::MASK_INT, $result['user']['id']);
        $this->assertSame(MaskConstants::MASK_INT, $result['user']['profile']['age']);
    }

    public function testApplyToContextWithAuditLogger(): void
    {
        $auditLog = [];
        $auditLogger = function (string $path, mixed $original, mixed $masked) use (&$auditLog): void {
            $auditLog[] = ['path' => $path, 'original' => $original, TestConstants::DATA_MASKED => $masked];
        };

        $masker = new DataTypeMasker(['integer' => MaskConstants::MASK_INT], $auditLogger);

        $context = ['count' => 100];
        $masker->applyToContext($context);

        $this->assertCount(1, $auditLog);
        $this->assertSame('count', $auditLog[0]['path']);
        $this->assertSame(100, $auditLog[0]['original']);
        $this->assertSame(MaskConstants::MASK_INT, $auditLog[0][TestConstants::DATA_MASKED]);
    }

    public function testApplyToContextWithNestedPathAuditLogger(): void
    {
        $auditLog = [];
        $auditLogger = function (string $path, mixed $original, mixed $masked) use (&$auditLog): void {
            $auditLog[] = ['path' => $path, 'original' => $original, TestConstants::DATA_MASKED => $masked];
        };

        $masker = new DataTypeMasker(['integer' => MaskConstants::MASK_INT], $auditLogger);

        $context = [
            'user' => [
                'id' => 123
            ]
        ];
        $masker->applyToContext($context);

        $this->assertCount(1, $auditLog);
        $this->assertSame('user.id', $auditLog[0]['path']);
    }

    public function testApplyToContextSkipsProcessedNestedFields(): void
    {
        $masker = new DataTypeMasker(['integer' => MaskConstants::MASK_INT]);

        $context = [
            'level1' => [
                'level2' => 123
            ]
        ];

        $result = $masker->applyToContext($context, ['level1.level2']);

        $this->assertSame(123, $result['level1']['level2']);
    }

    public function testProcessFieldValueWithNonArray(): void
    {
        $masker = new DataTypeMasker(['string' => MaskConstants::MASK_STRING]);

        $result = $masker->applyToContext(['field' => 'value']);

        $this->assertSame(MaskConstants::MASK_STRING, $result['field']);
    }

    public function testApplyToContextWithEmptyCurrentPath(): void
    {
        $masker = new DataTypeMasker(['integer' => MaskConstants::MASK_INT]);

        $context = ['id' => 123];
        $result = $masker->applyToContext($context, [], '');

        $this->assertSame(MaskConstants::MASK_INT, $result['id']);
    }

    public function testApplyMaskingIntegerWithNumericMask(): void
    {
        $masker = new DataTypeMasker(['integer' => '999']);

        $result = $masker->applyMasking(42);

        $this->assertSame(999, $result);
    }

    public function testApplyMaskingFloatWithNumericMask(): void
    {
        $masker = new DataTypeMasker(['double' => '3.14']);

        $result = $masker->applyMasking(1.5);

        $this->assertSame(3.14, $result);
    }

    public function testApplyMaskingObjectCreatesStandardObject(): void
    {
        $masker = new DataTypeMasker(['object' => MaskConstants::MASK_OBJECT]);

        $input = new \stdClass();
        $input->property = 'value';

        $result = $masker->applyMasking($input);

        $this->assertIsObject($result);
        $this->assertObjectHasProperty(TestConstants::DATA_MASKED, $result);
        $this->assertObjectHasProperty('original_class', $result);
        $this->assertSame(MaskConstants::MASK_OBJECT, $result->masked);
        $this->assertSame('stdClass', $result->original_class);
    }

    public function testApplyToContextDoesNotLogWhenValueUnchanged(): void
    {
        $auditLog = [];
        $auditLogger = function (string $path, mixed $original, mixed $masked) use (&$auditLog): void {
            $auditLog[] = ['path' => $path, 'original' => $original, TestConstants::DATA_MASKED => $masked];
        };

        $masker = new DataTypeMasker([], $auditLogger);

        $context = ['field' => 'value'];
        $masker->applyToContext($context);

        // Should not log because no masking was applied
        $this->assertCount(0, $auditLog);
    }

    public function testApplyToContextWithCurrentPath(): void
    {
        $auditLog = [];
        $auditLogger = function (string $path) use (&$auditLog): void {
            $auditLog[] = $path;
        };

        $masker = new DataTypeMasker(['integer' => MaskConstants::MASK_INT], $auditLogger);

        $context = ['id' => 123];
        $masker->applyToContext($context, [], 'user');

        $this->assertCount(1, $auditLog);
        $this->assertSame('user.id', $auditLog[0]);
    }

    public function testGetDefaultMasksReturnsCorrectStructure(): void
    {
        $masks = DataTypeMasker::getDefaultMasks();

        $this->assertArrayHasKey('integer', $masks);
        $this->assertArrayHasKey('double', $masks);
        $this->assertArrayHasKey('string', $masks);
        $this->assertArrayHasKey('boolean', $masks);
        $this->assertArrayHasKey('NULL', $masks);
        $this->assertArrayHasKey('array', $masks);
        $this->assertArrayHasKey('object', $masks);
        $this->assertArrayHasKey('resource', $masks);
        $this->assertSame(MaskConstants::MASK_INT, $masks['integer']);
    }
}
