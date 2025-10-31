<?php

declare(strict_types=1);

namespace Tests\Strategies;

use PHPUnit\Framework\TestCase;
use Tests\TestConstants;
use Tests\TestHelpers;
use Ivuorinen\MonologGdprFilter\Strategies\DataTypeMaskingStrategy;
use Ivuorinen\MonologGdprFilter\MaskConstants;

/**
 * Enhanced tests for DataTypeMaskingStrategy to improve coverage.
 */
final class DataTypeMaskingStrategyEnhancedTest extends TestCase
{
    use TestHelpers;

    public function testParseArrayMaskWithJsonFormat(): void
    {
        $strategy = new DataTypeMaskingStrategy([
            'array' => '["masked1", "masked2"]',
        ]);

        $logRecord = $this->createLogRecord();
        $result = $strategy->mask(['original', 'data'], 'test.path', $logRecord);

        $this->assertIsArray($result);
        $this->assertEquals(['masked1', 'masked2'], $result);
    }

    public function testParseArrayMaskWithCommaSeparated(): void
    {
        $strategy = new DataTypeMaskingStrategy([
            'array' => 'val1,val2,val3',
        ]);

        $logRecord = $this->createLogRecord();
        $result = $strategy->mask(['a', 'b', 'c'], 'test.path', $logRecord);

        $this->assertIsArray($result);
        $this->assertEquals(['val1', 'val2', 'val3'], $result);
    }

    public function testParseArrayMaskWithEmptyArray(): void
    {
        $strategy = new DataTypeMaskingStrategy([
            'array' => '[]',
        ]);

        $logRecord = $this->createLogRecord();
        $result = $strategy->mask(['data'], 'test.path', $logRecord);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testParseArrayMaskWithSimpleString(): void
    {
        $strategy = new DataTypeMaskingStrategy([
            'array' => MaskConstants::MASK_ARRAY,
        ]);

        $logRecord = $this->createLogRecord();
        $result = $strategy->mask(['data'], 'test.path', $logRecord);

        // Simple strings get split on commas, so it becomes an array
        $this->assertIsArray($result);
        $this->assertEquals([MaskConstants::MASK_ARRAY], $result);
    }

    public function testParseObjectMaskWithJsonFormat(): void
    {
        $strategy = new DataTypeMaskingStrategy([
            'object' => '{"key": "value", "num": 123}',
        ]);

        $obj = (object)['original' => 'data'];
        $logRecord = $this->createLogRecord();
        $result = $strategy->mask($obj, 'test.path', $logRecord);

        $this->assertIsObject($result);
        $this->assertEquals('value', $result->key);
        $this->assertEquals(123, $result->num);
    }

    public function testParseObjectMaskWithEmptyObject(): void
    {
        $strategy = new DataTypeMaskingStrategy([
            'object' => '{}',
        ]);

        $obj = (object)['data' => 'value'];
        $logRecord = $this->createLogRecord();
        $result = $strategy->mask($obj, 'test.path', $logRecord);

        $this->assertIsObject($result);
        $this->assertEquals((object)[], $result);
    }

    public function testParseObjectMaskWithSimpleString(): void
    {
        $strategy = new DataTypeMaskingStrategy([
            'object' => MaskConstants::MASK_OBJECT,
        ]);

        $obj = (object)['data' => 'value'];
        $logRecord = $this->createLogRecord();
        $result = $strategy->mask($obj, 'test.path', $logRecord);

        // Simple strings get converted to object with TestConstants::DATA_MASKED property
        $this->assertIsObject($result);
        $this->assertEquals(MaskConstants::MASK_OBJECT, $result->masked);
    }

    public function testApplyTypeMaskForInteger(): void
    {
        $strategy = new DataTypeMaskingStrategy([
            'integer' => '999',
        ]);

        $logRecord = $this->createLogRecord();
        $result = $strategy->mask(12345, 'test.path', $logRecord);

        $this->assertIsInt($result);
        $this->assertEquals(999, $result);
    }

    public function testApplyTypeMaskForFloat(): void
    {
        $strategy = new DataTypeMaskingStrategy([
            'double' => '99.99',
        ]);

        $logRecord = $this->createLogRecord();
        $result = $strategy->mask(123.456, 'test.path', $logRecord);

        $this->assertIsFloat($result);
        $this->assertEquals(99.99, $result);
    }

    public function testApplyTypeMaskForFloatWithInvalidMask(): void
    {
        $strategy = new DataTypeMaskingStrategy([
            'double' => 'not-a-number',
        ]);

        $logRecord = $this->createLogRecord();
        $result = $strategy->mask(123.456, 'test.path', $logRecord);

        // Falls back to string when numeric conversion fails
        $this->assertEquals('not-a-number', $result);
    }

    public function testApplyTypeMaskForBoolean(): void
    {
        $strategy = new DataTypeMaskingStrategy([
            'boolean' => 'false',
        ]);

        $logRecord = $this->createLogRecord();
        $result = $strategy->mask(true, 'test.path', $logRecord);

        $this->assertIsBool($result);
        $this->assertFalse($result);
    }

    public function testApplyTypeMaskForBooleanWithTrueString(): void
    {
        $strategy = new DataTypeMaskingStrategy([
            'boolean' => 'true',
        ]);

        $logRecord = $this->createLogRecord();
        $result = $strategy->mask(false, 'test.path', $logRecord);

        $this->assertIsBool($result);
        $this->assertTrue($result);
    }

    public function testApplyTypeMaskForNull(): void
    {
        $strategy = new DataTypeMaskingStrategy([
            'NULL' => '',
        ]);

        $logRecord = $this->createLogRecord();
        $result = $strategy->mask(null, 'test.path', $logRecord);

        $this->assertNull($result);
    }

    public function testApplyTypeMaskForNullWithNonEmptyMask(): void
    {
        $strategy = new DataTypeMaskingStrategy([
            'NULL' => 'null_value',
        ]);

        $logRecord = $this->createLogRecord();
        $result = $strategy->mask(null, 'test.path', $logRecord);

        $this->assertEquals('null_value', $result);
    }

    public function testApplyTypeMaskForString(): void
    {
        $strategy = new DataTypeMaskingStrategy([
            'string' => MaskConstants::MASK_MASKED,
        ]);

        $logRecord = $this->createLogRecord();
        $result = $strategy->mask('sensitive data', 'test.path', $logRecord);

        $this->assertIsString($result);
        $this->assertEquals(MaskConstants::MASK_MASKED, $result);
    }

    public function testIncludePathsFiltering(): void
    {
        $strategy = new DataTypeMaskingStrategy(
            ['string' => MaskConstants::MASK_MASKED],
            [TestConstants::PATH_USER_WILDCARD, 'account.details']
        );

        $logRecord = $this->createLogRecord();

        // Should apply to included paths
        $this->assertTrue($strategy->shouldApply('test', TestConstants::FIELD_USER_EMAIL, $logRecord));
        $this->assertTrue($strategy->shouldApply('test', TestConstants::FIELD_USER_NAME, $logRecord));
        $this->assertTrue($strategy->shouldApply('test', 'account.details', $logRecord));

        // Should not apply to non-included paths
        $this->assertFalse($strategy->shouldApply('test', TestConstants::FIELD_SYSTEM_LOG, $logRecord));
        $this->assertFalse($strategy->shouldApply('test', 'other.field', $logRecord));
    }

    public function testExcludePathsPrecedence(): void
    {
        $strategy = new DataTypeMaskingStrategy(
            ['string' => MaskConstants::MASK_MASKED],
            [TestConstants::PATH_USER_WILDCARD],
            [TestConstants::FIELD_USER_PUBLIC, 'user.id']
        );

        $logRecord = $this->createLogRecord();

        // Should apply to included paths not in exclude list
        $this->assertTrue($strategy->shouldApply('test', TestConstants::FIELD_USER_EMAIL, $logRecord));

        // Should not apply to excluded paths
        $this->assertFalse($strategy->shouldApply('test', TestConstants::FIELD_USER_PUBLIC, $logRecord));
        $this->assertFalse($strategy->shouldApply('test', 'user.id', $logRecord));
    }

    public function testWildcardPathMatching(): void
    {
        $strategy = new DataTypeMaskingStrategy(
            ['string' => MaskConstants::MASK_MASKED],
            ['*.email', 'data.*.sensitive']
        );

        $logRecord = $this->createLogRecord();

        // Test wildcard matching
        $this->assertTrue($strategy->shouldApply('test', TestConstants::FIELD_USER_EMAIL, $logRecord));
        $this->assertTrue($strategy->shouldApply('test', 'admin.email', $logRecord));
        $this->assertTrue($strategy->shouldApply('test', 'data.user.sensitive', $logRecord));
        $this->assertTrue($strategy->shouldApply('test', 'data.admin.sensitive', $logRecord));
    }

    public function testShouldApplyWithNoIncludePaths(): void
    {
        $strategy = new DataTypeMaskingStrategy([
            'string' => MaskConstants::MASK_MASKED,
        ]);

        $logRecord = $this->createLogRecord();

        // With no include paths, should apply to all string values
        $this->assertTrue($strategy->shouldApply('test', 'any.path', $logRecord));
        $this->assertTrue($strategy->shouldApply('test', 'other.path', $logRecord));
    }

    public function testShouldNotApplyWhenTypeNotConfigured(): void
    {
        $strategy = new DataTypeMaskingStrategy([
            'string' => MaskConstants::MASK_MASKED,
        ]);

        $logRecord = $this->createLogRecord();

        // Should not apply to types not in typeMasks
        $this->assertFalse($strategy->shouldApply(123, 'test.path', $logRecord));
        $this->assertFalse($strategy->shouldApply(true, 'test.path', $logRecord));
        $this->assertFalse($strategy->shouldApply([], 'test.path', $logRecord));
    }

    public function testGetName(): void
    {
        $strategy = new DataTypeMaskingStrategy([
            'string' => MaskConstants::MASK_STRING,
            'integer' => MaskConstants::MASK_INT,
            'boolean' => MaskConstants::MASK_BOOL,
        ]);

        $name = $strategy->getName();
        $this->assertStringContainsString('Data Type Masking', $name);
        $this->assertStringContainsString('3 types', $name);
    }

    public function testGetConfiguration(): void
    {
        $typeMasks = ['string' => MaskConstants::MASK_MASKED];
        $includePaths = [TestConstants::PATH_USER_WILDCARD];
        $excludePaths = [TestConstants::FIELD_USER_PUBLIC];

        $strategy = new DataTypeMaskingStrategy($typeMasks, $includePaths, $excludePaths);

        $config = $strategy->getConfiguration();
        $this->assertArrayHasKey('type_masks', $config);
        $this->assertArrayHasKey('include_paths', $config);
        $this->assertArrayHasKey('exclude_paths', $config);
        $this->assertEquals($typeMasks, $config['type_masks']);
        $this->assertEquals($includePaths, $config['include_paths']);
        $this->assertEquals($excludePaths, $config['exclude_paths']);
    }

    public function testValidateReturnsTrue(): void
    {
        $strategy = new DataTypeMaskingStrategy(['string' => MaskConstants::MASK_MASKED]);
        $this->assertTrue($strategy->validate());
    }
}
