<?php

declare(strict_types=1);

namespace Tests\Strategies;

use Ivuorinen\MonologGdprFilter\Exceptions\MaskingOperationFailedException;
use Ivuorinen\MonologGdprFilter\MaskConstants;
use Ivuorinen\MonologGdprFilter\Exceptions\RuleExecutionException;
use Ivuorinen\MonologGdprFilter\Strategies\CallbackMaskingStrategy;
use PHPUnit\Framework\TestCase;
use Tests\TestConstants;
use Tests\TestHelpers;

/**
 * Tests for CallbackMaskingStrategy.
 *
 * @api
 */
final class CallbackMaskingStrategyTest extends TestCase
{
    use TestHelpers;

    public function testBasicConstruction(): void
    {
        $callback = fn(mixed $value): string => TestConstants::MASK_MASKED_BRACKETS;
        $strategy = new CallbackMaskingStrategy(TestConstants::FIELD_USER_EMAIL, $callback);

        $this->assertSame(TestConstants::FIELD_USER_EMAIL, $strategy->getFieldPath());
        $this->assertTrue($strategy->isExactMatch());
        $this->assertSame(50, $strategy->getPriority());
    }

    public function testMaskWithSimpleCallback(): void
    {
        $callback = fn(mixed $value): string => TestConstants::MASK_MASKED_BRACKETS;
        $strategy = new CallbackMaskingStrategy(TestConstants::FIELD_USER_EMAIL, $callback);
        $record = $this->createLogRecord();

        $result = $strategy->mask(TestConstants::EMAIL_JOHN, TestConstants::FIELD_USER_EMAIL, $record);

        $this->assertSame(TestConstants::MASK_MASKED_BRACKETS, $result);
    }

    public function testMaskWithTransformingCallback(): void
    {
        $callback = fn(mixed $value): string => strtoupper((string) $value);
        $strategy = new CallbackMaskingStrategy(TestConstants::FIELD_USER_NAME, $callback);
        $record = $this->createLogRecord();

        $result = $strategy->mask('john', TestConstants::FIELD_USER_NAME, $record);

        $this->assertSame('JOHN', $result);
    }

    public function testMaskThrowsOnCallbackException(): void
    {
        $callback = function (): never {
            throw new RuleExecutionException('Callback failed');
        };
        $strategy = new CallbackMaskingStrategy('user.data', $callback);
        $record = $this->createLogRecord();

        $this->expectException(MaskingOperationFailedException::class);
        $this->expectExceptionMessage('Callback threw exception');

        $strategy->mask('value', 'user.data', $record);
    }

    public function testShouldApplyWithExactMatch(): void
    {
        $callback = fn(mixed $value): string => TestConstants::MASK_MASKED_BRACKETS;
        $strategy = new CallbackMaskingStrategy(
            TestConstants::FIELD_USER_EMAIL,
            $callback,
            exactMatch: true
        );
        $record = $this->createLogRecord();

        $this->assertTrue($strategy->shouldApply('value', TestConstants::FIELD_USER_EMAIL, $record));
        $this->assertFalse($strategy->shouldApply('value', TestConstants::FIELD_USER_NAME, $record));
        $this->assertFalse($strategy->shouldApply('value', 'user.email.work', $record));
    }

    public function testShouldApplyWithWildcardMatch(): void
    {
        $callback = fn(mixed $value): string => TestConstants::MASK_MASKED_BRACKETS;
        $strategy = new CallbackMaskingStrategy(
            TestConstants::PATH_USER_WILDCARD,
            $callback,
            exactMatch: false
        );
        $record = $this->createLogRecord();

        $this->assertTrue($strategy->shouldApply('value', TestConstants::FIELD_USER_EMAIL, $record));
        $this->assertTrue($strategy->shouldApply('value', TestConstants::FIELD_USER_NAME, $record));
        $this->assertFalse($strategy->shouldApply('value', 'admin.email', $record));
    }

    public function testGetName(): void
    {
        $callback = fn(mixed $value): string => TestConstants::MASK_MASKED_BRACKETS;
        $strategy = new CallbackMaskingStrategy(TestConstants::FIELD_USER_EMAIL, $callback);

        $name = $strategy->getName();

        $this->assertStringContainsString('Callback Masking', $name);
        $this->assertStringContainsString(TestConstants::FIELD_USER_EMAIL, $name);
    }

    public function testValidate(): void
    {
        $callback = fn(mixed $value): string => TestConstants::MASK_MASKED_BRACKETS;
        $strategy = new CallbackMaskingStrategy(TestConstants::FIELD_USER_EMAIL, $callback);

        $this->assertTrue($strategy->validate());
    }

    public function testGetConfiguration(): void
    {
        $callback = fn(mixed $value): string => TestConstants::MASK_MASKED_BRACKETS;
        $strategy = new CallbackMaskingStrategy(
            TestConstants::FIELD_USER_EMAIL,
            $callback,
            75,
            false
        );

        $config = $strategy->getConfiguration();

        $this->assertArrayHasKey('field_path', $config);
        $this->assertArrayHasKey('exact_match', $config);
        $this->assertArrayHasKey('priority', $config);
        $this->assertSame(TestConstants::FIELD_USER_EMAIL, $config['field_path']);
        $this->assertFalse($config['exact_match']);
        $this->assertSame(75, $config['priority']);
    }

    public function testForPathsFactoryMethod(): void
    {
        $callback = fn(mixed $value): string => TestConstants::MASK_MASKED_BRACKETS;
        $paths = [TestConstants::FIELD_USER_EMAIL, 'admin.email', 'contact.email'];

        $strategies = CallbackMaskingStrategy::forPaths($paths, $callback);

        $this->assertCount(3, $strategies);

        foreach ($strategies as $index => $strategy) {
            $this->assertInstanceOf(CallbackMaskingStrategy::class, $strategy);
            $this->assertSame($paths[$index], $strategy->getFieldPath());
        }
    }

    public function testConstantFactoryMethod(): void
    {
        $strategy = CallbackMaskingStrategy::constant('user.ssn', MaskConstants::MASK_SSN_PATTERN);
        $record = $this->createLogRecord();

        $result = $strategy->mask(TestConstants::SSN_US, 'user.ssn', $record);

        $this->assertSame(MaskConstants::MASK_SSN_PATTERN, $result);
    }

    public function testHashFactoryMethod(): void
    {
        $strategy = CallbackMaskingStrategy::hash(TestConstants::FIELD_USER_PASSWORD, 'sha256', 8);
        $record = $this->createLogRecord();

        $result = $strategy->mask('secret123', TestConstants::FIELD_USER_PASSWORD, $record);

        $this->assertIsString($result);
        $this->assertSame(11, strlen($result));
        $this->assertStringEndsWith('...', $result);
    }

    public function testHashWithNoTruncation(): void
    {
        $strategy = CallbackMaskingStrategy::hash(TestConstants::FIELD_USER_PASSWORD, 'md5', 0);
        $record = $this->createLogRecord();

        $result = $strategy->mask('test', TestConstants::FIELD_USER_PASSWORD, $record);

        $this->assertSame(32, strlen((string) $result));
    }

    public function testPartialFactoryMethod(): void
    {
        $strategy = CallbackMaskingStrategy::partial(TestConstants::FIELD_USER_EMAIL, 2, 4);
        $record = $this->createLogRecord();

        $result = $strategy->mask(TestConstants::EMAIL_JOHN, TestConstants::FIELD_USER_EMAIL, $record);

        $this->assertStringStartsWith('jo', $result);
        $this->assertStringEndsWith('.com', $result);
        $this->assertStringContainsString(MaskConstants::MASK_GENERIC, $result);
    }

    public function testPartialWithShortString(): void
    {
        $strategy = CallbackMaskingStrategy::partial('user.code', 2, 2);
        $record = $this->createLogRecord();

        $result = $strategy->mask('abc', 'user.code', $record);

        $this->assertSame(MaskConstants::MASK_GENERIC, $result);
    }

    public function testPartialWithCustomMaskChar(): void
    {
        $strategy = CallbackMaskingStrategy::partial('user.card', 4, 4, '#');
        $record = $this->createLogRecord();

        $result = $strategy->mask('1234567890123456', 'user.card', $record);

        $this->assertStringStartsWith('1234', $result);
        $this->assertStringEndsWith('3456', $result);
        $this->assertStringContainsString('########', $result);
    }

    public function testCallbackReceivesOriginalValue(): void
    {
        $receivedValue = null;
        $callback = function (mixed $value) use (&$receivedValue): string {
            $receivedValue = $value;
            return TestConstants::MASK_MASKED_BRACKETS;
        };

        $strategy = new CallbackMaskingStrategy('user.data', $callback);
        $record = $this->createLogRecord();

        $strategy->mask(['key' => 'value'], 'user.data', $record);

        $this->assertSame($receivedValue, ['key' => 'value']);
    }

    public function testCallbackCanReturnNonString(): void
    {
        $callback = fn(mixed $value): array => [TestConstants::DATA_MASKED => true];
        $strategy = new CallbackMaskingStrategy('user.data', $callback);
        $record = $this->createLogRecord();

        $result = $strategy->mask(['key' => 'value'], 'user.data', $record);

        $this->assertSame([TestConstants::DATA_MASKED => true], $result);
    }

    public function testCustomPriority(): void
    {
        $callback = fn(mixed $value): string => TestConstants::MASK_MASKED_BRACKETS;
        $strategy = new CallbackMaskingStrategy(TestConstants::FIELD_USER_EMAIL, $callback, 100);

        $this->assertSame(100, $strategy->getPriority());
    }
}
