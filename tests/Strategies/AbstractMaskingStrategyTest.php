<?php

declare(strict_types=1);

namespace Tests\Strategies;

use Ivuorinen\MonologGdprFilter\Exceptions\MaskingOperationFailedException;
use Ivuorinen\MonologGdprFilter\Strategies\AbstractMaskingStrategy;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\TestConstants;
use Tests\TestHelpers;

#[CoversClass(AbstractMaskingStrategy::class)]
final class AbstractMaskingStrategyTest extends TestCase
{
    use TestHelpers;

    private AbstractMaskingStrategy $strategy;

    #[\Override]
    protected function setUp(): void
    {
        // Create an anonymous class extending AbstractMaskingStrategy for testing
        $this->strategy = new class (priority: 75, configuration: ['test' => 'value']) extends AbstractMaskingStrategy
        {
            #[\Override]
            public function mask(mixed $value, string $path, LogRecord $logRecord): mixed
            {
                return $value;
            }

            /**
             * @return true
             */
            #[\Override]
            /**
             * @return true
             */
            public function shouldApply(mixed $value, string $path, LogRecord $logRecord): bool
            {
                return true;
            }

            /**
             * @return string
             *
             * @psalm-return 'Test Strategy'
             */
            #[\Override]
            /**
             * @return string
             *
             * @psalm-return 'Test Strategy'
             */
            public function getName(): string
            {
                return 'Test Strategy';
            }

            /**
             * @return true
             */
            public function supports(LogRecord $logRecord): bool
            {
                return true;
            }

            public function apply(LogRecord $logRecord): LogRecord
            {
                return $logRecord;
            }

            // Expose protected methods for testing
            public function testValueToString(mixed $value): string
            {
                return $this->valueToString($value);
            }

            public function testPathMatches(string $path, string $pattern): bool
            {
                return $this->pathMatches($path, $pattern);
            }

            public function testRecordMatches(LogRecord $logRecord, array $conditions): bool
            {
                return $this->recordMatches($logRecord, $conditions);
            }

            public function testGenerateValuePreview(mixed $value, int $maxLength = 100): string
            {
                return $this->generateValuePreview($value, $maxLength);
            }

            public function testPreserveValueType(mixed $originalValue, string $maskedString): mixed
            {
                return $this->preserveValueType($originalValue, $maskedString);
            }
        };
    }

    #[Test]
    public function getPriorityReturnsConfiguredPriority(): void
    {
        $this->assertSame(75, $this->strategy->getPriority());
    }

    #[Test]
    public function getConfigurationReturnsConfiguredArray(): void
    {
        $this->assertSame(['test' => 'value'], $this->strategy->getConfiguration());
    }

    #[Test]
    public function validateReturnsTrue(): void
    {
        $this->assertTrue($this->strategy->validate());
    }

    #[Test]
    public function valueToStringConvertsStringAsIs(): void
    {
        $result = $this->strategy->testValueToString('test string');
        $this->assertSame('test string', $result);
    }

    #[Test]
    public function valueToStringConvertsInteger(): void
    {
        $result = $this->strategy->testValueToString(123);
        $this->assertSame('123', $result);
    }

    #[Test]
    public function valueToStringConvertsFloat(): void
    {
        $result = $this->strategy->testValueToString(123.45);
        $this->assertSame('123.45', $result);
    }

    #[Test]
    public function valueToStringConvertsBooleanTrue(): void
    {
        $result = $this->strategy->testValueToString(true);
        $this->assertSame('1', $result);
    }

    #[Test]
    public function valueToStringConvertsBooleanFalse(): void
    {
        $result = $this->strategy->testValueToString(false);
        $this->assertSame('', $result);
    }

    #[Test]
    public function valueToStringConvertsArray(): void
    {
        $result = $this->strategy->testValueToString(['key' => 'value']);
        $this->assertSame('{"key":"value"}', $result);
    }

    #[Test]
    public function valueToStringConvertsObject(): void
    {
        $obj = (object) ['prop' => 'value'];
        $result = $this->strategy->testValueToString($obj);
        $this->assertSame('{"prop":"value"}', $result);
    }

    #[Test]
    public function valueToStringConvertsNullToEmptyString(): void
    {
        $result = $this->strategy->testValueToString(null);
        $this->assertSame('', $result);
    }

    #[Test]
    public function valueToStringThrowsForResource(): void
    {
        $resource = fopen('php://memory', 'r');
        $this->assertIsResource($resource, 'Failed to open php://memory');

        $this->expectException(MaskingOperationFailedException::class);
        $this->expectExceptionMessage('resource');

        try {
            $this->strategy->testValueToString($resource);
        } finally {
            fclose($resource);
        }
    }

    #[Test]
    public function pathMatchesReturnsTrueForExactMatch(): void
    {
        $this->assertTrue($this->strategy->testPathMatches(TestConstants::FIELD_USER_EMAIL, TestConstants::FIELD_USER_EMAIL));
    }

    #[Test]
    public function pathMatchesReturnsFalseForNonMatch(): void
    {
        $this->assertFalse($this->strategy->testPathMatches(TestConstants::FIELD_USER_EMAIL, TestConstants::FIELD_USER_PASSWORD));
    }

    #[Test]
    public function pathMatchesSupportsWildcardAtEnd(): void
    {
        $this->assertTrue($this->strategy->testPathMatches(TestConstants::FIELD_USER_EMAIL, TestConstants::PATH_USER_WILDCARD));
        $this->assertTrue($this->strategy->testPathMatches(TestConstants::FIELD_USER_PASSWORD, TestConstants::PATH_USER_WILDCARD));
    }

    #[Test]
    public function pathMatchesSupportsWildcardAtStart(): void
    {
        $this->assertTrue($this->strategy->testPathMatches(TestConstants::FIELD_USER_EMAIL, '*.email'));
        $this->assertTrue($this->strategy->testPathMatches('admin.email', '*.email'));
    }

    #[Test]
    public function pathMatchesSupportsWildcardInMiddle(): void
    {
        $this->assertTrue($this->strategy->testPathMatches('user.profile.email', 'user.*.email'));
    }

    #[Test]
    public function pathMatchesSupportsMultipleWildcards(): void
    {
        $this->assertTrue($this->strategy->testPathMatches('user.profile.contact.email', '*.*.*.email'));
    }

    #[Test]
    public function recordMatchesReturnsTrueWhenAllConditionsMet(): void
    {
        $logRecord = $this->createLogRecord(
            TestConstants::MESSAGE_TEST_LOWERCASE,
            [TestConstants::CONTEXT_USER_ID => 123],
            Level::Error,
            'test-channel'
        );

        $conditions = [
            'level' => 'Error',
            'channel' => 'test-channel',
            'message' => TestConstants::MESSAGE_TEST_LOWERCASE,
            TestConstants::CONTEXT_USER_ID => 123,
        ];

        $this->assertTrue($this->strategy->testRecordMatches($logRecord, $conditions));
    }

    #[Test]
    public function recordMatchesReturnsFalseWhenLevelDoesNotMatch(): void
    {
        $logRecord = $this->createLogRecord(
            TestConstants::MESSAGE_TEST_LOWERCASE,
            [],
            Level::Error,
            'test-channel'
        );

        $this->assertFalse($this->strategy->testRecordMatches($logRecord, ['level' => 'Warning']));
    }

    #[Test]
    public function recordMatchesReturnsFalseWhenChannelDoesNotMatch(): void
    {
        $logRecord = $this->createLogRecord(
            TestConstants::MESSAGE_TEST_LOWERCASE,
            [],
            Level::Error,
            'test-channel'
        );

        $this->assertFalse($this->strategy->testRecordMatches($logRecord, ['channel' => 'other-channel']));
    }

    #[Test]
    public function recordMatchesReturnsFalseWhenContextFieldMissing(): void
    {
        $logRecord = $this->createLogRecord(
            TestConstants::MESSAGE_TEST_LOWERCASE,
            [],
            Level::Error,
            'test-channel'
        );

        $this->assertFalse($this->strategy->testRecordMatches($logRecord, [TestConstants::CONTEXT_USER_ID => 123]));
    }

    #[Test]
    public function generateValuePreviewReturnsFullStringWhenShort(): void
    {
        $preview = $this->strategy->testGenerateValuePreview('short string');
        $this->assertSame('short string', $preview);
    }

    #[Test]
    public function generateValuePreviewTruncatesLongString(): void
    {
        $longString = str_repeat('a', 150);
        $preview = $this->strategy->testGenerateValuePreview($longString, 100);

        $this->assertSame(103, strlen($preview)); // 100 + '...'
        $this->assertStringEndsWith('...', $preview);
    }

    #[Test]
    public function generateValuePreviewHandlesNonStringValues(): void
    {
        $preview = $this->strategy->testGenerateValuePreview(['key' => 'value']);
        $this->assertSame('{"key":"value"}', $preview);
    }

    #[Test]
    public function generateValuePreviewHandlesResourceType(): void
    {
        $resource = fopen('php://memory', 'r');
        $this->assertIsResource($resource, 'Failed to open php://memory');

        try {
            $preview = $this->strategy->testGenerateValuePreview($resource);
            $this->assertSame('[resource]', $preview);
        } finally {
            fclose($resource);
        }
    }

    #[Test]
    public function preserveValueTypeReturnsStringForStringInput(): void
    {
        $result = $this->strategy->testPreserveValueType('original', TestConstants::DATA_MASKED);
        $this->assertSame(TestConstants::DATA_MASKED, $result);
        $this->assertIsString($result);
    }

    #[Test]
    public function preserveValueTypeConvertsBackToIntegerWhenPossible(): void
    {
        $result = $this->strategy->testPreserveValueType(123, '456');
        $this->assertSame(456, $result);
        $this->assertIsInt($result);
    }

    #[Test]
    public function preserveValueTypeConvertsBackToFloatWhenPossible(): void
    {
        $result = $this->strategy->testPreserveValueType(123.45, '678.90');
        $this->assertSame(678.90, $result);
        $this->assertIsFloat($result);
    }

    #[Test]
    public function preserveValueTypeConvertsBackToBooleanTrue(): void
    {
        $result = $this->strategy->testPreserveValueType(true, 'true');
        $this->assertTrue($result);
        $this->assertIsBool($result);
    }

    #[Test]
    public function preserveValueTypeConvertsBackToBooleanFalse(): void
    {
        $result = $this->strategy->testPreserveValueType(false, 'false');
        $this->assertFalse($result);
        $this->assertIsBool($result);
    }

    #[Test]
    public function preserveValueTypeConvertsBackToArray(): void
    {
        $result = $this->strategy->testPreserveValueType(['original' => 'value'], '{"masked":"data"}');
        $this->assertSame(['masked' => 'data'], $result);
        $this->assertIsArray($result);
    }

    #[Test]
    public function preserveValueTypeConvertsBackToObject(): void
    {
        $original = (object) ['original' => 'value'];
        $result = $this->strategy->testPreserveValueType($original, '{"masked":"data"}');

        $this->assertIsObject($result);
        $this->assertEquals((object) ['masked' => 'data'], $result);
    }

    #[Test]
    public function preserveValueTypeReturnsStringWhenTypeConversionFails(): void
    {
        $result = $this->strategy->testPreserveValueType(123, 'not-a-number');
        $this->assertSame('not-a-number', $result);
        $this->assertIsString($result);
    }
}
