<?php

declare(strict_types=1);

namespace Tests;

use Ivuorinen\MonologGdprFilter\MaskConstants;
use Tests\TestConstants;
use DateTimeImmutable;
use stdClass;
use PHPUnit\Framework\TestCase;
use Tests\TestHelpers;
use Monolog\LogRecord;
use Monolog\Level;
use Ivuorinen\MonologGdprFilter\DataTypeMasker;

/**
 * Test data type-based masking functionality.
 *
 * @api
 */
class DataTypeMaskingTest extends TestCase
{
    use TestHelpers;

    public function testDefaultDataTypeMasks(): void
    {
        $masks = DataTypeMasker::getDefaultMasks();

        $this->assertIsArray($masks);
        $this->assertArrayHasKey('integer', $masks);
        $this->assertArrayHasKey('string', $masks);
        $this->assertArrayHasKey('boolean', $masks);
        $this->assertArrayHasKey('array', $masks);
        $this->assertArrayHasKey('object', $masks);
        $this->assertEquals(MaskConstants::MASK_INT, $masks['integer']);
        $this->assertEquals(MaskConstants::MASK_STRING, $masks['string']);
    }

    public function testIntegerMasking(): void
    {
        $processor = $this->createProcessor(
            [],
            [],
            [],
            null,
            100,
            ['integer' => MaskConstants::MASK_INT]
        );

        $logRecord = $this->createLogRecord(context: ['age' => 25, 'count' => 100]);

        $result = $processor($logRecord);

        $this->assertEquals(MaskConstants::MASK_INT, $result->context['age']);
        $this->assertEquals(MaskConstants::MASK_INT, $result->context['count']);
    }

    public function testFloatMasking(): void
    {
        $processor = $this->createProcessor(
            [],
            [],
            [],
            null,
            100,
            ['double' => MaskConstants::MASK_FLOAT]
        );

        $logRecord = $this->createLogRecord(context: ['price' => 99.99, 'rating' => 4.5]);

        $result = $processor($logRecord);

        $this->assertEquals(MaskConstants::MASK_FLOAT, $result->context['price']);
        $this->assertEquals(MaskConstants::MASK_FLOAT, $result->context['rating']);
    }

    public function testBooleanMasking(): void
    {
        $processor = $this->createProcessor(
            [],
            [],
            [],
            null,
            100,
            ['boolean' => MaskConstants::MASK_BOOL]
        );

        $logRecord = $this->createLogRecord(context: ['active' => true, 'deleted' => false]);

        $result = $processor($logRecord);

        $this->assertEquals(MaskConstants::MASK_BOOL, $result->context['active']);
        $this->assertEquals(MaskConstants::MASK_BOOL, $result->context['deleted']);
    }

    public function testNullMasking(): void
    {
        $processor = $this->createProcessor(
            [],
            [],
            [],
            null,
            100,
            ['NULL' => MaskConstants::MASK_NULL]
        );

        $logRecord = $this->createLogRecord(context: ['optional_field' => null, 'another_null' => null]);

        $result = $processor($logRecord);

        $this->assertEquals(MaskConstants::MASK_NULL, $result->context['optional_field']);
        $this->assertEquals(MaskConstants::MASK_NULL, $result->context['another_null']);
    }

    public function testObjectMasking(): void
    {
        $processor = $this->createProcessor(
            [],
            [],
            [],
            null,
            100,
            ['object' => MaskConstants::MASK_OBJECT]
        );

        $testObject = new stdClass();
        $testObject->name = 'test';

        $logRecord = $this->createLogRecord(context: ['user' => $testObject]);

        $result = $processor($logRecord);

        $this->assertIsObject($result->context['user']);
        $this->assertEquals(MaskConstants::MASK_OBJECT, $result->context['user']->masked);
        $this->assertEquals('stdClass', $result->context['user']->original_class);
    }

    public function testArrayMasking(): void
    {
        $processor = $this->createProcessor(
            [],
            [],
            [],
            null,
            100,
            ['array' => MaskConstants::MASK_ARRAY]
        );

        $logRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            TestConstants::MESSAGE_DEFAULT,
            ['tags' => ['php', 'gdpr'], 'metadata' => ['key' => 'value']]
        );

        $result = $processor($logRecord);

        $this->assertEquals([MaskConstants::MASK_ARRAY], $result->context['tags']);
        $this->assertEquals([MaskConstants::MASK_ARRAY], $result->context['metadata']);
    }

    public function testRecursiveArrayMasking(): void
    {
        $processor = $this->createProcessor(
            [],
            [],
            [],
            null,
            100,
            ['array' => 'recursive', 'integer' => MaskConstants::MASK_INT]
        );

        $logRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            TestConstants::MESSAGE_DEFAULT,
            ['nested' => ['level1' => ['level2' => ['count' => 42]]]]
        );

        $result = $processor($logRecord);

        // The array should be processed recursively, and the integer should be masked
        $this->assertEquals(MaskConstants::MASK_INT, $result->context['nested']['level1']['level2']['count']);
    }

    public function testMixedDataTypes(): void
    {
        $processor = $this->createProcessor(
            [],
            [],
            [],
            null,
            100,
            [
                'integer' => MaskConstants::MASK_INT,
                'string' => MaskConstants::MASK_STRING,
                'boolean' => MaskConstants::MASK_BOOL,
                'NULL' => MaskConstants::MASK_NULL,
            ]
        );

        $logRecord = $this->createLogRecord(context: [
            'age' => 30,
            'name' => 'John Doe',
            'active' => true,
            'deleted_at' => null,
            'score' => 98.5, // This won't be masked (no 'double' rule)
        ]);

        $result = $processor($logRecord);

        $this->assertEquals(MaskConstants::MASK_INT, $result->context['age']);
        $this->assertEquals(MaskConstants::MASK_STRING, $result->context['name']);
        $this->assertEquals(MaskConstants::MASK_BOOL, $result->context['active']);
        $this->assertEquals(MaskConstants::MASK_NULL, $result->context['deleted_at']);
        $this->assertEqualsWithDelta(98.5, $result->context['score'], PHP_FLOAT_EPSILON); // Should remain unchanged
    }

    public function testNumericMaskValues(): void
    {
        $processor = $this->createProcessor(
            [],
            [],
            [],
            null,
            100,
            [
                'integer' => '0',
                'double' => '0.0',
            ]
        );

        $logRecord = $this->createLogRecord(context: ['age' => 25, 'salary' => 50000.50]);

        $result = $processor($logRecord);

        $this->assertEquals(0, $result->context['age']);
        $this->assertEqualsWithDelta(0.0, $result->context['salary'], PHP_FLOAT_EPSILON);
    }

    public function testPreserveBooleanValues(): void
    {
        $processor = $this->createProcessor(
            [],
            [],
            [],
            null,
            100,
            ['boolean' => 'preserve']
        );

        $logRecord = $this->createLogRecord(context: ['active' => true, 'deleted' => false]);

        $result = $processor($logRecord);

        $this->assertTrue($result->context['active']);
        $this->assertFalse($result->context['deleted']);
    }

    public function testNoDataTypeMasking(): void
    {
        // Test with empty data type masks
        $processor = $this->createProcessor([], [], [], null, 100, []);

        $logRecord = $this->createLogRecord(context: [
            'age' => 30,
            'name' => TestConstants::NAME_FULL,
            'active' => true,
            'deleted_at' => null,
        ]);

        $result = $processor($logRecord);

        // All values should remain unchanged
        $this->assertEquals(30, $result->context['age']);
        $this->assertEquals(TestConstants::NAME_FULL, $result->context['name']);
        $this->assertTrue($result->context['active']);
        $this->assertNull($result->context['deleted_at']);
    }

    public function testDataTypeMaskingWithStringRegex(): void
    {
        // Test that string masking and regex masking work together
        $processor = $this->createProcessor(
            ['/test@example\.com/' => MaskConstants::MASK_EMAIL],
            [],
            [],
            null,
            100,
            ['integer' => MaskConstants::MASK_INT]
        );

        $logRecord = $this->createLogRecord(context: [
            'email' => TestConstants::EMAIL_TEST,
            'user_id' => 12345,
        ]);

        $result = $processor($logRecord);

        $this->assertEquals(MaskConstants::MASK_EMAIL, $result->context['email']);
        $this->assertEquals(MaskConstants::MASK_INT, $result->context['user_id']);
    }
}
