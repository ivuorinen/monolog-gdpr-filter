<?php

declare(strict_types=1);

namespace Tests\Strategies;

use Ivuorinen\MonologGdprFilter\Strategies\DataTypeMaskingStrategy;
use Ivuorinen\MonologGdprFilter\MaskConstants;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\TestConstants;
use Tests\TestHelpers;

#[CoversClass(DataTypeMaskingStrategy::class)]
final class DataTypeMaskingStrategyComprehensiveTest extends TestCase
{
    use TestHelpers;

    public function testMaskWithNullValue(): void
    {
        $strategy = new DataTypeMaskingStrategy(['NULL' => '']);

        $record = $this->createLogRecord('Test');

        $result = $strategy->mask(null, 'field', $record);

        $this->assertNull($result);
    }

    public function testMaskWithNullValueAndNonEmptyMask(): void
    {
        $strategy = new DataTypeMaskingStrategy(['NULL' => 'null_value']);

        $record = $this->createLogRecord('Test');

        $result = $strategy->mask(null, 'field', $record);

        $this->assertSame('null_value', $result);
    }

    public function testMaskWithIntegerValue(): void
    {
        $strategy = new DataTypeMaskingStrategy(['integer' => '999']);

        $record = $this->createLogRecord('Test');

        $result = $strategy->mask(123, 'field', $record);

        $this->assertSame(999, $result);
        $this->assertIsInt($result);
    }

    public function testMaskWithIntegerValueNonNumericMask(): void
    {
        $strategy = new DataTypeMaskingStrategy(['integer' => 'MASKED']);

        $record = $this->createLogRecord('Test');

        $result = $strategy->mask(123, 'field', $record);

        // Non-numeric mask returns string
        $this->assertSame('MASKED', $result);
    }

    public function testMaskWithDoubleValue(): void
    {
        $strategy = new DataTypeMaskingStrategy(['double' => '99.99']);

        $record = $this->createLogRecord('Test');

        $result = $strategy->mask(3.14, 'field', $record);

        $this->assertSame(99.99, $result);
        $this->assertIsFloat($result);
    }

    public function testMaskWithDoubleValueNonNumericMask(): void
    {
        $strategy = new DataTypeMaskingStrategy(['double' => 'MASKED']);

        $record = $this->createLogRecord('Test');

        $result = $strategy->mask(3.14, 'field', $record);

        $this->assertSame('MASKED', $result);
    }

    public function testMaskWithBooleanValue(): void
    {
        $strategy = new DataTypeMaskingStrategy(['boolean' => 'false']);

        $record = $this->createLogRecord('Test');

        $result = $strategy->mask(true, 'field', $record);

        $this->assertFalse($result);
        $this->assertIsBool($result);
    }

    public function testMaskWithArrayValueEmptyMask(): void
    {
        $strategy = new DataTypeMaskingStrategy(['array' => '[]']);

        $record = $this->createLogRecord('Test');

        $result = $strategy->mask(['key' => 'value'], 'field', $record);

        $this->assertSame([], $result);
        $this->assertIsArray($result);
    }

    public function testMaskWithArrayValueJsonMask(): void
    {
        $strategy = new DataTypeMaskingStrategy(['array' => '["masked1","masked2"]']);

        $record = $this->createLogRecord('Test');

        $result = $strategy->mask(['original'], 'field', $record);

        $this->assertSame(['masked1', 'masked2'], $result);
    }

    public function testMaskWithArrayValueCommaSeparatedMask(): void
    {
        $strategy = new DataTypeMaskingStrategy(['array' => '[a,b,c]']);

        $record = $this->createLogRecord('Test');

        $result = $strategy->mask(['x', 'y'], 'field', $record);

        $this->assertSame(['a', 'b', 'c'], $result);
    }

    public function testMaskWithObjectValueEmptyMask(): void
    {
        $strategy = new DataTypeMaskingStrategy(['object' => '{}']);

        $record = $this->createLogRecord('Test');

        $obj = (object)['key' => 'value'];
        $result = $strategy->mask($obj, 'field', $record);

        $this->assertEquals((object)[], $result);
        $this->assertIsObject($result);
    }

    public function testMaskWithObjectValueJsonMask(): void
    {
        $strategy = new DataTypeMaskingStrategy(['object' => '{"masked":"data"}']);

        $record = $this->createLogRecord('Test');

        $obj = (object)['original' => 'value'];
        $result = $strategy->mask($obj, 'field', $record);

        $expected = (object)['masked' => 'data'];
        $this->assertEquals($expected, $result);
    }

    public function testMaskWithObjectValueSimpleMask(): void
    {
        $strategy = new DataTypeMaskingStrategy(['object' => 'MASKED']);

        $record = $this->createLogRecord('Test');

        $obj = (object)['key' => 'value'];
        $result = $strategy->mask($obj, 'field', $record);

        $expected = (object)[TestConstants::DATA_MASKED => 'MASKED'];
        $this->assertEquals($expected, $result);
    }

    public function testMaskWithStringValue(): void
    {
        $strategy = new DataTypeMaskingStrategy(['string' => MaskConstants::MASK_MASKED]);

        $record = $this->createLogRecord('Test');

        $result = $strategy->mask('original text', 'field', $record);

        $this->assertSame(MaskConstants::MASK_MASKED, $result);
    }

    public function testMaskWithNoMaskForType(): void
    {
        $strategy = new DataTypeMaskingStrategy(['string' => 'MASKED']);

        $record = $this->createLogRecord('Test');

        // No mask for integer type
        $result = $strategy->mask(123, 'field', $record);

        // Should return original value when no mask exists
        $this->assertSame(123, $result);
    }

    public function testShouldApplyWithIncludePaths(): void
    {
        $strategy = new DataTypeMaskingStrategy(
            typeMasks: ['string' => 'MASKED'],
            includePaths: [TestConstants::PATH_USER_WILDCARD, 'account.name']
        );

        $record = $this->createLogRecord('Test');

        // Included paths should apply
        $this->assertTrue($strategy->shouldApply('test', TestConstants::FIELD_USER_EMAIL, $record));
        $this->assertTrue($strategy->shouldApply('test', 'account.name', $record));

        // Non-included paths should not apply
        $this->assertFalse($strategy->shouldApply('test', TestConstants::FIELD_SYSTEM_LOG, $record));
    }

    public function testShouldApplyWithExcludePaths(): void
    {
        $strategy = new DataTypeMaskingStrategy(
            typeMasks: ['string' => 'MASKED'],
            excludePaths: ['internal.*', 'debug.log']
        );

        $record = $this->createLogRecord('Test');

        // Excluded paths should not apply
        $this->assertFalse($strategy->shouldApply('test', 'internal.field', $record));
        $this->assertFalse($strategy->shouldApply('test', 'debug.log', $record));

        // Non-excluded paths should apply
        $this->assertTrue($strategy->shouldApply('test', TestConstants::FIELD_USER_NAME, $record));
    }

    public function testShouldApplyReturnsFalseWhenNoMaskForType(): void
    {
        $strategy = new DataTypeMaskingStrategy(['string' => 'MASKED']);

        $record = $this->createLogRecord('Test');

        // No mask for integer type
        $this->assertFalse($strategy->shouldApply(123, 'field', $record));
    }

    public function testGetName(): void
    {
        $strategy = new DataTypeMaskingStrategy([
            'string' => 'MASKED',
            'integer' => '999',
            'boolean' => 'false',
        ]);

        $name = $strategy->getName();

        $this->assertStringContainsString('Data Type Masking', $name);
        $this->assertStringContainsString('3 types', $name);
        $this->assertStringContainsString('string', $name);
        $this->assertStringContainsString('integer', $name);
        $this->assertStringContainsString('boolean', $name);
    }

    public function testValidateReturnsFalseForEmptyTypeMasks(): void
    {
        $strategy = new DataTypeMaskingStrategy([]);

        $this->assertFalse($strategy->validate());
    }

    public function testValidateReturnsFalseForInvalidType(): void
    {
        $strategy = new DataTypeMaskingStrategy(['invalid_type' => 'MASKED']);

        $this->assertFalse($strategy->validate());
    }

    public function testValidateReturnsFalseForNonStringMask(): void
    {
        $strategy = new DataTypeMaskingStrategy(['string' => 123]);

        $this->assertFalse($strategy->validate());
    }

    public function testValidateReturnsTrueForValidConfiguration(): void
    {
        $strategy = new DataTypeMaskingStrategy([
            'string' => 'MASKED',
            'integer' => '999',
            'double' => '99.99',
            'boolean' => 'false',
            'array' => '[]',
            'object' => '{}',
            'NULL' => '',
        ]);

        $this->assertTrue($strategy->validate());
    }

    public function testCreateDefaultFactory(): void
    {
        $strategy = DataTypeMaskingStrategy::createDefault();

        $record = $this->createLogRecord('Test');

        // Test default masks
        $this->assertSame(MaskConstants::MASK_STRING, $strategy->mask('text', 'field', $record));
        $this->assertSame(999, $strategy->mask(123, 'field', $record));
        $this->assertSame(99.99, $strategy->mask(3.14, 'field', $record));
        $this->assertFalse($strategy->mask(true, 'field', $record));
        $this->assertSame([], $strategy->mask(['x'], 'field', $record));
        $this->assertEquals((object)[], $strategy->mask((object)['x' => 'y'], 'field', $record));
        $this->assertNull($strategy->mask(null, 'field', $record));
    }

    public function testCreateDefaultFactoryWithCustomMasks(): void
    {
        $strategy = DataTypeMaskingStrategy::createDefault([
            'string' => 'CUSTOM',
            'integer' => '0',
        ]);

        $record = $this->createLogRecord('Test');

        // Custom masks should override defaults
        $this->assertSame('CUSTOM', $strategy->mask('text', 'field', $record));
        $this->assertSame(0, $strategy->mask(123, 'field', $record));

        // Default masks should still work for non-overridden types
        $this->assertSame(99.99, $strategy->mask(3.14, 'field', $record));
    }

    public function testCreateDefaultFactoryWithCustomPriority(): void
    {
        $strategy = DataTypeMaskingStrategy::createDefault([], priority: 50);

        $this->assertSame(50, $strategy->getPriority());
    }

    public function testCreateSensitiveOnlyFactory(): void
    {
        $strategy = DataTypeMaskingStrategy::createSensitiveOnly();

        $record = $this->createLogRecord('Test');

        // Should mask sensitive types
        $this->assertSame(MaskConstants::MASK_MASKED, $strategy->mask('text', 'field', $record));
        $this->assertSame([], $strategy->mask(['x'], 'field', $record));
        $this->assertEquals((object)[], $strategy->mask((object)['x' => 'y'], 'field', $record));

        // Should not mask non-sensitive types (no mask defined)
        $this->assertSame(123, $strategy->mask(123, 'field', $record));
        $this->assertSame(3.14, $strategy->mask(3.14, 'field', $record));
    }

    public function testCreateSensitiveOnlyFactoryWithCustomMasks(): void
    {
        $strategy = DataTypeMaskingStrategy::createSensitiveOnly([
            'integer' => '0', // Add integer masking
        ]);

        $record = $this->createLogRecord('Test');

        // Custom mask should be added
        $this->assertSame(0, $strategy->mask(123, 'field', $record));

        // Default sensitive masks should still work
        $this->assertSame(MaskConstants::MASK_MASKED, $strategy->mask('text', 'field', $record));
    }

    public function testGetConfiguration(): void
    {
        $typeMasks = ['string' => 'MASKED', 'integer' => '999'];
        $includePaths = [TestConstants::PATH_USER_WILDCARD];
        $excludePaths = ['internal.*'];

        $strategy = new DataTypeMaskingStrategy($typeMasks, $includePaths, $excludePaths);

        $config = $strategy->getConfiguration();

        $this->assertArrayHasKey('type_masks', $config);
        $this->assertArrayHasKey('include_paths', $config);
        $this->assertArrayHasKey('exclude_paths', $config);
        $this->assertSame($typeMasks, $config['type_masks']);
        $this->assertSame($includePaths, $config['include_paths']);
        $this->assertSame($excludePaths, $config['exclude_paths']);
    }

    public function testParseArrayMaskWithEmptyString(): void
    {
        $strategy = new DataTypeMaskingStrategy(['array' => '']);

        $record = $this->createLogRecord('Test');

        $result = $strategy->mask(['original'], 'field', $record);

        $this->assertSame([], $result);
    }

    public function testParseObjectMaskWithEmptyString(): void
    {
        $strategy = new DataTypeMaskingStrategy(['object' => '']);

        $record = $this->createLogRecord('Test');

        $obj = (object)['key' => 'value'];
        $result = $strategy->mask($obj, 'field', $record);

        $this->assertEquals((object)[], $result);
    }

    public function testGetValueTypeReturnsCorrectTypes(): void
    {
        $strategy = new DataTypeMaskingStrategy([
            'string' => 'S',
            'integer' => 'I',
            'double' => 'D',
            'boolean' => '1',  // Boolean uses filter_var, so '1' becomes true
            'array' => '["MASKED"]',  // JSON array
            'object' => '{"masked":"value"}',  // JSON object
            'NULL' => 'N',
        ]);

        $record = $this->createLogRecord('Test');

        $this->assertSame('S', $strategy->mask('text', 'f', $record));
        $this->assertSame('I', $strategy->mask(123, 'f', $record));
        $this->assertSame('D', $strategy->mask(3.14, 'f', $record));
        $this->assertTrue($strategy->mask(true, 'f', $record)); // Boolean conversion
        $this->assertSame(['MASKED'], $strategy->mask([], 'f', $record));
        $this->assertEquals((object)['masked' => 'value'], $strategy->mask((object)[], 'f', $record));
        $this->assertSame('N', $strategy->mask(null, 'f', $record));
    }
}
