<?php

declare(strict_types=1);

namespace Tests\Strategies;

use Ivuorinen\MonologGdprFilter\Strategies\DataTypeMaskingStrategy;
use Ivuorinen\MonologGdprFilter\MaskConstants;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\TestHelpers;

#[CoversClass(DataTypeMaskingStrategy::class)]
final class DataTypeMaskingStrategyTest extends TestCase
{
    use TestHelpers;

    private LogRecord $logRecord;

    #[\Override]
    protected function setUp(): void
    {
        $this->logRecord = $this->createLogRecord();
    }

    #[Test]
    public function constructorAcceptsTypeMasksArray(): void
    {
        $strategy = new DataTypeMaskingStrategy([
            'string' => MaskConstants::MASK_GENERIC,
            'integer' => '0',
        ]);

        $this->assertSame(40, $strategy->getPriority());
    }

    #[Test]
    public function constructorAcceptsCustomPriority(): void
    {
        $strategy = new DataTypeMaskingStrategy(
            typeMasks: ['string' => MaskConstants::MASK_GENERIC],
            priority: 50
        );

        $this->assertSame(50, $strategy->getPriority());
    }

    #[Test]
    public function getNameReturnsDescriptiveName(): void
    {
        $strategy = new DataTypeMaskingStrategy([
            'string' => MaskConstants::MASK_GENERIC,
            'integer' => '0',
            'boolean' => 'false',
        ]);

        $name = $strategy->getName();
        $this->assertStringContainsString('Data Type Masking', $name);
        $this->assertStringContainsString('3 types', $name);
        $this->assertStringContainsString('string', $name);
        $this->assertStringContainsString('integer', $name);
        $this->assertStringContainsString('boolean', $name);
    }

    #[Test]
    public function shouldApplyReturnsTrueForMappedType(): void
    {
        $strategy = new DataTypeMaskingStrategy(['string' => MaskConstants::MASK_GENERIC]);

        $this->assertTrue($strategy->shouldApply('test string', 'field', $this->logRecord));
    }

    #[Test]
    public function shouldApplyReturnsFalseForUnmappedType(): void
    {
        $strategy = new DataTypeMaskingStrategy(['string' => MaskConstants::MASK_GENERIC]);

        $this->assertFalse($strategy->shouldApply(123, 'field', $this->logRecord));
    }

    #[Test]
    public function shouldApplyReturnsFalseForExcludedPath(): void
    {
        $strategy = new DataTypeMaskingStrategy(
            typeMasks: ['string' => MaskConstants::MASK_GENERIC],
            excludePaths: ['debug.*']
        );

        $this->assertFalse($strategy->shouldApply('test', 'debug.info', $this->logRecord));
    }

    #[Test]
    public function shouldApplyRespectsIncludePaths(): void
    {
        $strategy = new DataTypeMaskingStrategy(
            typeMasks: ['string' => MaskConstants::MASK_GENERIC],
            includePaths: ['user.*']
        );

        $this->assertTrue($strategy->shouldApply('test', 'user.name', $this->logRecord));
        $this->assertFalse($strategy->shouldApply('test', 'admin.name', $this->logRecord));
    }

    #[Test]
    public function maskAppliesStringMask(): void
    {
        $strategy = new DataTypeMaskingStrategy(['string' => 'REDACTED']);

        $result = $strategy->mask('sensitive data', 'field', $this->logRecord);

        $this->assertSame('REDACTED', $result);
    }

    #[Test]
    public function maskAppliesIntegerMask(): void
    {
        $strategy = new DataTypeMaskingStrategy(['integer' => '999']);

        $result = $strategy->mask(123, 'field', $this->logRecord);

        $this->assertSame(999, $result);
    }

    #[Test]
    public function maskAppliesIntegerMaskAsString(): void
    {
        $strategy = new DataTypeMaskingStrategy(['integer' => 'MASKED']);

        $result = $strategy->mask(123, 'field', $this->logRecord);

        $this->assertSame('MASKED', $result);
    }

    #[Test]
    public function maskAppliesDoubleMask(): void
    {
        $strategy = new DataTypeMaskingStrategy(['double' => '99.99']);

        $result = $strategy->mask(123.45, 'field', $this->logRecord);

        $this->assertSame(99.99, $result);
    }

    #[Test]
    public function maskAppliesBooleanMaskTrue(): void
    {
        $strategy = new DataTypeMaskingStrategy(['boolean' => 'true']);

        $result = $strategy->mask(false, 'field', $this->logRecord);

        $this->assertTrue($result);
    }

    #[Test]
    public function maskAppliesBooleanMaskFalse(): void
    {
        $strategy = new DataTypeMaskingStrategy(['boolean' => 'false']);

        $result = $strategy->mask(true, 'field', $this->logRecord);

        $this->assertFalse($result);
    }

    #[Test]
    public function maskAppliesArrayMaskEmptyArray(): void
    {
        $strategy = new DataTypeMaskingStrategy(['array' => '[]']);

        $result = $strategy->mask(['key' => 'value'], 'field', $this->logRecord);

        $this->assertSame([], $result);
    }

    #[Test]
    public function maskAppliesArrayMaskJsonArray(): void
    {
        $strategy = new DataTypeMaskingStrategy(['array' => '["masked"]']);

        $result = $strategy->mask(['original'], 'field', $this->logRecord);

        $this->assertSame(['masked'], $result);
    }

    #[Test]
    public function maskAppliesArrayMaskCommaDelimited(): void
    {
        $strategy = new DataTypeMaskingStrategy(['array' => '[a,b,c]']);

        $result = $strategy->mask(['x', 'y', 'z'], 'field', $this->logRecord);

        $this->assertSame(['a', 'b', 'c'], $result);
    }

    #[Test]
    public function maskAppliesObjectMaskEmptyObject(): void
    {
        $strategy = new DataTypeMaskingStrategy(['object' => '{}']);

        $obj = (object) ['key' => 'value'];
        $result = $strategy->mask($obj, 'field', $this->logRecord);

        $this->assertIsObject($result);
        $this->assertEquals((object) [], $result);
    }

    #[Test]
    public function maskAppliesObjectMaskJsonObject(): void
    {
        $strategy = new DataTypeMaskingStrategy(['object' => '{"masked":"data"}']);

        $obj = (object) ['original' => 'value'];
        $result = $strategy->mask($obj, 'field', $this->logRecord);

        $this->assertIsObject($result);
        $this->assertEquals((object) ['masked' => 'data'], $result);
    }

    #[Test]
    public function maskAppliesObjectMaskFallback(): void
    {
        $strategy = new DataTypeMaskingStrategy(['object' => 'MASKED']);

        $obj = (object) ['key' => 'value'];
        $result = $strategy->mask($obj, 'field', $this->logRecord);

        $this->assertIsObject($result);
        $this->assertEquals((object) ['masked' => 'MASKED'], $result);
    }

    #[Test]
    public function maskAppliesNullMaskAsNull(): void
    {
        $strategy = new DataTypeMaskingStrategy(['NULL' => '']);

        $result = $strategy->mask(null, 'field', $this->logRecord);

        $this->assertNull($result);
    }

    #[Test]
    public function maskAppliesNullMaskAsString(): void
    {
        $strategy = new DataTypeMaskingStrategy(['NULL' => 'null']);

        $result = $strategy->mask(null, 'field', $this->logRecord);

        $this->assertSame('null', $result);
    }

    #[Test]
    public function validateReturnsTrueForValidConfiguration(): void
    {
        $strategy = new DataTypeMaskingStrategy([
            'string' => MaskConstants::MASK_GENERIC,
            'integer' => '0',
            'double' => '0.0',
            'boolean' => 'false',
            'array' => '[]',
            'object' => '{}',
            'NULL' => '',
        ]);

        $this->assertTrue($strategy->validate());
    }

    #[Test]
    public function validateReturnsFalseForEmptyTypeMasks(): void
    {
        $strategy = new DataTypeMaskingStrategy([]);

        $this->assertFalse($strategy->validate());
    }

    #[Test]
    public function validateReturnsFalseForInvalidType(): void
    {
        $strategy = new DataTypeMaskingStrategy([
            'invalid_type' => MaskConstants::MASK_GENERIC,
        ]);

        $this->assertFalse($strategy->validate());
    }

    #[Test]
    public function validateReturnsFalseForNonStringMask(): void
    {
        $strategy = new DataTypeMaskingStrategy([
            'string' => 123,
        ]);

        $this->assertFalse($strategy->validate());
    }

    #[Test]
    public function createDefaultCreatesStrategyWithDefaults(): void
    {
        $strategy = DataTypeMaskingStrategy::createDefault();

        $config = $strategy->getConfiguration();
        $this->assertArrayHasKey('type_masks', $config);
        $this->assertArrayHasKey('string', $config['type_masks']);
        $this->assertArrayHasKey('integer', $config['type_masks']);
        $this->assertArrayHasKey('double', $config['type_masks']);
        $this->assertArrayHasKey('boolean', $config['type_masks']);
        $this->assertArrayHasKey('array', $config['type_masks']);
        $this->assertArrayHasKey('object', $config['type_masks']);
        $this->assertArrayHasKey('NULL', $config['type_masks']);
    }

    #[Test]
    public function createDefaultAcceptsCustomMasks(): void
    {
        $strategy = DataTypeMaskingStrategy::createDefault(['string' => 'CUSTOM']);

        $config = $strategy->getConfiguration();
        $this->assertSame('CUSTOM', $config['type_masks']['string']);
    }

    #[Test]
    public function createDefaultAcceptsCustomPriority(): void
    {
        $strategy = DataTypeMaskingStrategy::createDefault([], priority: 99);

        $this->assertSame(99, $strategy->getPriority());
    }

    #[Test]
    public function createSensitiveOnlyCreatesStrategyForSensitiveTypes(): void
    {
        $strategy = DataTypeMaskingStrategy::createSensitiveOnly();

        $config = $strategy->getConfiguration();
        $this->assertArrayHasKey('type_masks', $config);
        $this->assertArrayHasKey('string', $config['type_masks']);
        $this->assertArrayHasKey('array', $config['type_masks']);
        $this->assertArrayHasKey('object', $config['type_masks']);
        $this->assertArrayNotHasKey('integer', $config['type_masks']);
        $this->assertArrayNotHasKey('double', $config['type_masks']);
    }

    #[Test]
    public function createSensitiveOnlyAcceptsCustomMasks(): void
    {
        $strategy = DataTypeMaskingStrategy::createSensitiveOnly(['integer' => '0']);

        $config = $strategy->getConfiguration();
        $this->assertArrayHasKey('integer', $config['type_masks']);
    }

    #[Test]
    public function createSensitiveOnlyAcceptsCustomPriority(): void
    {
        $strategy = DataTypeMaskingStrategy::createSensitiveOnly([], priority: 88);

        $this->assertSame(88, $strategy->getPriority());
    }

    #[Test]
    public function getConfigurationReturnsFullConfiguration(): void
    {
        $typeMasks = ['string' => MaskConstants::MASK_GENERIC];
        $includePaths = ['user.*'];
        $excludePaths = ['debug.*'];

        $strategy = new DataTypeMaskingStrategy(
            typeMasks: $typeMasks,
            includePaths: $includePaths,
            excludePaths: $excludePaths
        );

        $config = $strategy->getConfiguration();

        $this->assertSame($typeMasks, $config['type_masks']);
        $this->assertSame($includePaths, $config['include_paths']);
        $this->assertSame($excludePaths, $config['exclude_paths']);
    }

    #[Test]
    public function maskReturnsOriginalValueWhenNoMaskDefined(): void
    {
        $strategy = new DataTypeMaskingStrategy(['string' => MaskConstants::MASK_GENERIC]);

        $result = $strategy->mask(123, 'field', $this->logRecord);

        $this->assertSame(123, $result);
    }
}
