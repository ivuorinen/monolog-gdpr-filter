<?php

declare(strict_types=1);

namespace Tests;

use DateTimeImmutable;
use stdClass;
use PHPUnit\Framework\TestCase;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Monolog\LogRecord;
use Monolog\Level;

/**
 * Test data type-based masking functionality.
 */
class DataTypeMaskingTest extends TestCase
{
    public function testDefaultDataTypeMasks(): void
    {
        $masks = GdprProcessor::getDefaultDataTypeMasks();

        $this->assertIsArray($masks);
        $this->assertArrayHasKey('integer', $masks);
        $this->assertArrayHasKey('string', $masks);
        $this->assertArrayHasKey('boolean', $masks);
        $this->assertArrayHasKey('array', $masks);
        $this->assertArrayHasKey('object', $masks);
        $this->assertEquals('***INT***', $masks['integer']);
        $this->assertEquals('***STRING***', $masks['string']);
    }

    public function testIntegerMasking(): void
    {
        $processor = new GdprProcessor(
            [],
            [],
            [],
            null,
            100,
            ['integer' => '***INT***']
        );

        $logRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            'Test message',
            ['age' => 25, 'count' => 100]
        );

        $result = $processor($logRecord);

        $this->assertEquals('***INT***', $result->context['age']);
        $this->assertEquals('***INT***', $result->context['count']);
    }

    public function testFloatMasking(): void
    {
        $processor = new GdprProcessor(
            [],
            [],
            [],
            null,
            100,
            ['double' => '***FLOAT***']
        );

        $logRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            'Test message',
            ['price' => 99.99, 'rating' => 4.5]
        );

        $result = $processor($logRecord);

        $this->assertEquals('***FLOAT***', $result->context['price']);
        $this->assertEquals('***FLOAT***', $result->context['rating']);
    }

    public function testBooleanMasking(): void
    {
        $processor = new GdprProcessor(
            [],
            [],
            [],
            null,
            100,
            ['boolean' => '***BOOL***']
        );

        $logRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            'Test message',
            ['active' => true, 'deleted' => false]
        );

        $result = $processor($logRecord);

        $this->assertEquals('***BOOL***', $result->context['active']);
        $this->assertEquals('***BOOL***', $result->context['deleted']);
    }

    public function testNullMasking(): void
    {
        $processor = new GdprProcessor(
            [],
            [],
            [],
            null,
            100,
            ['NULL' => '***NULL***']
        );

        $logRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            'Test message',
            ['optional_field' => null, 'another_null' => null]
        );

        $result = $processor($logRecord);

        $this->assertEquals('***NULL***', $result->context['optional_field']);
        $this->assertEquals('***NULL***', $result->context['another_null']);
    }

    public function testObjectMasking(): void
    {
        $processor = new GdprProcessor(
            [],
            [],
            [],
            null,
            100,
            ['object' => '***OBJECT***']
        );

        $testObject = new stdClass();
        $testObject->name = 'test';

        $logRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            'Test message',
            ['user' => $testObject]
        );

        $result = $processor($logRecord);

        $this->assertIsObject($result->context['user']);
        $this->assertEquals('***OBJECT***', $result->context['user']->masked);
        $this->assertEquals('stdClass', $result->context['user']->original_class);
    }

    public function testArrayMasking(): void
    {
        $processor = new GdprProcessor(
            [],
            [],
            [],
            null,
            100,
            ['array' => '***ARRAY***']
        );

        $logRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            'Test message',
            ['tags' => ['php', 'gdpr'], 'metadata' => ['key' => 'value']]
        );

        $result = $processor($logRecord);

        $this->assertEquals(['***ARRAY***'], $result->context['tags']);
        $this->assertEquals(['***ARRAY***'], $result->context['metadata']);
    }

    public function testRecursiveArrayMasking(): void
    {
        $processor = new GdprProcessor(
            [],
            [],
            [],
            null,
            100,
            ['array' => 'recursive', 'integer' => '***INT***']
        );

        $logRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            'Test message',
            ['nested' => ['level1' => ['level2' => ['count' => 42]]]]
        );

        $result = $processor($logRecord);

        // The array should be processed recursively, and the integer should be masked
        $this->assertEquals('***INT***', $result->context['nested']['level1']['level2']['count']);
    }

    public function testMixedDataTypes(): void
    {
        $processor = new GdprProcessor(
            [],
            [],
            [],
            null,
            100,
            [
                'integer' => '***INT***',
                'string' => '***STRING***',
                'boolean' => '***BOOL***',
                'NULL' => '***NULL***',
            ]
        );

        $logRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            'Test message',
            [
                'age' => 30,
                'name' => 'John Doe',
                'active' => true,
                'deleted_at' => null,
                'score' => 98.5, // This won't be masked (no 'double' rule)
            ]
        );

        $result = $processor($logRecord);

        $this->assertEquals('***INT***', $result->context['age']);
        $this->assertEquals('***STRING***', $result->context['name']);
        $this->assertEquals('***BOOL***', $result->context['active']);
        $this->assertEquals('***NULL***', $result->context['deleted_at']);
        $this->assertEqualsWithDelta(98.5, $result->context['score'], PHP_FLOAT_EPSILON); // Should remain unchanged
    }

    public function testNumericMaskValues(): void
    {
        $processor = new GdprProcessor(
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

        $logRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            'Test message',
            ['age' => 25, 'salary' => 50000.50]
        );

        $result = $processor($logRecord);

        $this->assertEquals(0, $result->context['age']);
        $this->assertEqualsWithDelta(0.0, $result->context['salary'], PHP_FLOAT_EPSILON);
    }

    public function testPreserveBooleanValues(): void
    {
        $processor = new GdprProcessor(
            [],
            [],
            [],
            null,
            100,
            ['boolean' => 'preserve']
        );

        $logRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            'Test message',
            ['active' => true, 'deleted' => false]
        );

        $result = $processor($logRecord);

        $this->assertTrue($result->context['active']);
        $this->assertFalse($result->context['deleted']);
    }

    public function testNoDataTypeMasking(): void
    {
        // Test with empty data type masks
        $processor = new GdprProcessor([], [], [], null, 100, []);

        $logRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            'Test message',
            [
                'age' => 30,
                'name' => 'John Doe',
                'active' => true,
                'deleted_at' => null,
            ]
        );

        $result = $processor($logRecord);

        // All values should remain unchanged
        $this->assertEquals(30, $result->context['age']);
        $this->assertEquals('John Doe', $result->context['name']);
        $this->assertTrue($result->context['active']);
        $this->assertNull($result->context['deleted_at']);
    }

    public function testDataTypeMaskingWithStringRegex(): void
    {
        // Test that string masking and regex masking work together
        $processor = new GdprProcessor(
            ['/test@example\.com/' => '***EMAIL***'],
            [],
            [],
            null,
            100,
            ['integer' => '***INT***']
        );

        $logRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            'Test message',
            [
                'email' => 'test@example.com',
                'user_id' => 12345,
            ]
        );

        $result = $processor($logRecord);

        $this->assertEquals('***EMAIL***', $result->context['email']);
        $this->assertEquals('***INT***', $result->context['user_id']);
    }
}
