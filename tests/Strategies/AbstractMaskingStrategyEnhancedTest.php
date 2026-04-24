<?php

declare(strict_types=1);

namespace Tests\Strategies;

use Ivuorinen\MonologGdprFilter\Exceptions\MaskingOperationFailedException;
use Ivuorinen\MonologGdprFilter\Strategies\AbstractMaskingStrategy;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\TestConstants;
use Tests\TestHelpers;

#[CoversClass(AbstractMaskingStrategy::class)]
final class AbstractMaskingStrategyEnhancedTest extends TestCase
{
    use TestHelpers;

    private AbstractMaskingStrategy $strategy;

    #[\Override]
    protected function setUp(): void
    {
        $this->strategy = new class extends AbstractMaskingStrategy {
            #[\Override]
            public function mask(mixed $value, string $path, LogRecord $logRecord): mixed
            {
                return $value;
            }

            #[\Override]
            /**
             * @return true
             */
            public function shouldApply(mixed $value, string $path, LogRecord $logRecord): bool
            {
                return true;
            }

            #[\Override]
            /**
             * @return string
             *
             * @psalm-return 'Test Strategy'
             */
            public function getName(): string
            {
                return TestConstants::STRATEGY_TEST;
            }

            // Expose valueToString for testing
            public function testValueToString(mixed $value): string
            {
                return $this->valueToString($value);
            }

            // Expose preserveValueType for testing
            public function testPreserveValueType(mixed $originalValue, string $maskedString): mixed
            {
                return $this->preserveValueType($originalValue, $maskedString);
            }
        };
    }

    public function testValueToStringThrowsForUnencodableArray(): void
    {
        // Create a resource that cannot be JSON encoded
        $resource = fopen('php://memory', 'r');

        $this->expectException(MaskingOperationFailedException::class);
        $this->expectExceptionMessage('Cannot convert value to string');

        try {
            // Array containing a resource should fail to encode
            $this->strategy->testValueToString(['key' => $resource]);
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    public function testPreserveValueTypeWithObjectReturningObject(): void
    {
        $originalObject = (object) ['original' => 'value'];
        $result = $this->strategy->testPreserveValueType($originalObject, '{"new":"data"}');

        // Should return object (not array) when original was object
        $this->assertIsObject($result);
        $this->assertEquals((object) ['new' => 'data'], $result);
    }

    public function testPreserveValueTypeWithArrayReturningArray(): void
    {
        $originalArray = ['original' => 'value'];
        $result = $this->strategy->testPreserveValueType($originalArray, '{"new":"data"}');

        // Should return array (not object) when original was array
        $this->assertIsArray($result);
        $this->assertSame(['new' => 'data'], $result);
    }

    public function testPreserveValueTypeWithInvalidJsonForObject(): void
    {
        $originalObject = (object) ['original' => 'value'];
        // Invalid JSON should fall back to string
        $result = $this->strategy->testPreserveValueType($originalObject, 'invalid-json');

        $this->assertIsString($result);
        $this->assertSame('invalid-json', $result);
    }

    public function testPreserveValueTypeWithInvalidJsonForArray(): void
    {
        $originalArray = ['original' => 'value'];
        // Invalid JSON should fall back to string
        $result = $this->strategy->testPreserveValueType($originalArray, 'invalid-json');

        $this->assertIsString($result);
        $this->assertSame('invalid-json', $result);
    }

    public function testPreserveValueTypeWithNonNumericStringForInteger(): void
    {
        // Original was integer but masked string is not numeric
        $result = $this->strategy->testPreserveValueType(123, 'not-a-number');

        // Should fall back to string
        $this->assertIsString($result);
        $this->assertSame('not-a-number', $result);
    }

    public function testPreserveValueTypeWithNonNumericStringForFloat(): void
    {
        // Original was float but masked string is not numeric
        $result = $this->strategy->testPreserveValueType(123.45, 'not-a-float');

        // Should fall back to string
        $this->assertIsString($result);
        $this->assertSame('not-a-float', $result);
    }
}
