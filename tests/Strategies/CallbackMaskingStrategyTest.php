<?php

declare(strict_types=1);

namespace Tests\Strategies;

use Ivuorinen\MonologGdprFilter\Exceptions\MaskingOperationFailedException;
use Ivuorinen\MonologGdprFilter\Strategies\CallbackMaskingStrategy;
use PHPUnit\Framework\TestCase;
use RuntimeException;
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
        $callback = fn(mixed $value): string => '[MASKED]';
        $strategy = new CallbackMaskingStrategy('user.email', $callback);

        $this->assertSame('user.email', $strategy->getFieldPath());
        $this->assertTrue($strategy->isExactMatch());
        $this->assertSame(50, $strategy->getPriority());
    }

    public function testMaskWithSimpleCallback(): void
    {
        $callback = fn(mixed $value): string => '[MASKED]';
        $strategy = new CallbackMaskingStrategy('user.email', $callback);
        $record = $this->createLogRecord();

        $result = $strategy->mask('john@example.com', 'user.email', $record);

        $this->assertSame('[MASKED]', $result);
    }

    public function testMaskWithTransformingCallback(): void
    {
        $callback = fn(mixed $value): string => strtoupper((string) $value);
        $strategy = new CallbackMaskingStrategy('user.name', $callback);
        $record = $this->createLogRecord();

        $result = $strategy->mask('john', 'user.name', $record);

        $this->assertSame('JOHN', $result);
    }

    public function testMaskThrowsOnCallbackException(): void
    {
        $callback = function (mixed $value): never {
            throw new RuntimeException('Callback failed');
        };
        $strategy = new CallbackMaskingStrategy('user.data', $callback);
        $record = $this->createLogRecord();

        $this->expectException(MaskingOperationFailedException::class);
        $this->expectExceptionMessage('Callback threw exception');

        $strategy->mask('value', 'user.data', $record);
    }

    public function testShouldApplyWithExactMatch(): void
    {
        $callback = fn(mixed $value): string => '[MASKED]';
        $strategy = new CallbackMaskingStrategy(
            'user.email',
            $callback,
            exactMatch: true
        );
        $record = $this->createLogRecord();

        $this->assertTrue($strategy->shouldApply('value', 'user.email', $record));
        $this->assertFalse($strategy->shouldApply('value', 'user.name', $record));
        $this->assertFalse($strategy->shouldApply('value', 'user.email.work', $record));
    }

    public function testShouldApplyWithWildcardMatch(): void
    {
        $callback = fn(mixed $value): string => '[MASKED]';
        $strategy = new CallbackMaskingStrategy(
            'user.*',
            $callback,
            exactMatch: false
        );
        $record = $this->createLogRecord();

        $this->assertTrue($strategy->shouldApply('value', 'user.email', $record));
        $this->assertTrue($strategy->shouldApply('value', 'user.name', $record));
        $this->assertFalse($strategy->shouldApply('value', 'admin.email', $record));
    }

    public function testGetName(): void
    {
        $callback = fn(mixed $value): string => '[MASKED]';
        $strategy = new CallbackMaskingStrategy('user.email', $callback);

        $name = $strategy->getName();

        $this->assertStringContainsString('Callback Masking', $name);
        $this->assertStringContainsString('user.email', $name);
    }

    public function testValidate(): void
    {
        $callback = fn(mixed $value): string => '[MASKED]';
        $strategy = new CallbackMaskingStrategy('user.email', $callback);

        $this->assertTrue($strategy->validate());
    }

    public function testGetConfiguration(): void
    {
        $callback = fn(mixed $value): string => '[MASKED]';
        $strategy = new CallbackMaskingStrategy(
            'user.email',
            $callback,
            75,
            false
        );

        $config = $strategy->getConfiguration();

        $this->assertArrayHasKey('field_path', $config);
        $this->assertArrayHasKey('exact_match', $config);
        $this->assertArrayHasKey('priority', $config);
        $this->assertSame('user.email', $config['field_path']);
        $this->assertFalse($config['exact_match']);
        $this->assertSame(75, $config['priority']);
    }

    public function testForPathsFactoryMethod(): void
    {
        $callback = fn(mixed $value): string => '[MASKED]';
        $paths = ['user.email', 'admin.email', 'contact.email'];

        $strategies = CallbackMaskingStrategy::forPaths($paths, $callback);

        $this->assertCount(3, $strategies);

        foreach ($strategies as $index => $strategy) {
            $this->assertInstanceOf(CallbackMaskingStrategy::class, $strategy);
            $this->assertSame($paths[$index], $strategy->getFieldPath());
        }
    }

    public function testConstantFactoryMethod(): void
    {
        $strategy = CallbackMaskingStrategy::constant('user.ssn', '***-**-****');
        $record = $this->createLogRecord();

        $result = $strategy->mask('123-45-6789', 'user.ssn', $record);

        $this->assertSame('***-**-****', $result);
    }

    public function testHashFactoryMethod(): void
    {
        $strategy = CallbackMaskingStrategy::hash('user.password', 'sha256', 8);
        $record = $this->createLogRecord();

        $result = $strategy->mask('secret123', 'user.password', $record);

        $this->assertIsString($result);
        $this->assertSame(11, strlen($result));
        $this->assertStringEndsWith('...', $result);
    }

    public function testHashWithNoTruncation(): void
    {
        $strategy = CallbackMaskingStrategy::hash('user.password', 'md5', 0);
        $record = $this->createLogRecord();

        $result = $strategy->mask('test', 'user.password', $record);

        $this->assertSame(32, strlen($result));
    }

    public function testPartialFactoryMethod(): void
    {
        $strategy = CallbackMaskingStrategy::partial('user.email', 2, 4);
        $record = $this->createLogRecord();

        $result = $strategy->mask('john@example.com', 'user.email', $record);

        $this->assertStringStartsWith('jo', $result);
        $this->assertStringEndsWith('.com', $result);
        $this->assertStringContainsString('***', $result);
    }

    public function testPartialWithShortString(): void
    {
        $strategy = CallbackMaskingStrategy::partial('user.code', 2, 2);
        $record = $this->createLogRecord();

        $result = $strategy->mask('abc', 'user.code', $record);

        $this->assertSame('***', $result);
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
            return '[MASKED]';
        };

        $strategy = new CallbackMaskingStrategy('user.data', $callback);
        $record = $this->createLogRecord();

        $strategy->mask(['key' => 'value'], 'user.data', $record);

        $this->assertSame(['key' => 'value'], $receivedValue);
    }

    public function testCallbackCanReturnNonString(): void
    {
        $callback = fn(mixed $value): array => ['masked' => true];
        $strategy = new CallbackMaskingStrategy('user.data', $callback);
        $record = $this->createLogRecord();

        $result = $strategy->mask(['key' => 'value'], 'user.data', $record);

        $this->assertSame(['masked' => true], $result);
    }

    public function testCustomPriority(): void
    {
        $callback = fn(mixed $value): string => '[MASKED]';
        $strategy = new CallbackMaskingStrategy('user.email', $callback, 100);

        $this->assertSame(100, $strategy->getPriority());
    }
}
