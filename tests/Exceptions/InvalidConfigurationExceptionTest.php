<?php

declare(strict_types=1);

namespace Tests\Exceptions;

use Ivuorinen\MonologGdprFilter\Exceptions\InvalidConfigurationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test InvalidConfigurationException factory methods.
 *
 * @api
 */
#[CoversClass(InvalidConfigurationException::class)]
class InvalidConfigurationExceptionTest extends TestCase
{
    #[Test]
    public function forFieldPathCreatesException(): void
    {
        $exception = InvalidConfigurationException::forFieldPath(
            'user.invalid',
            'Field path is malformed'
        );

        $this->assertInstanceOf(InvalidConfigurationException::class, $exception);
        $this->assertStringContainsString("Invalid field path 'user.invalid'", $exception->getMessage());
        $this->assertStringContainsString('Field path is malformed', $exception->getMessage());
    }

    #[Test]
    public function forDataTypeMaskCreatesException(): void
    {
        $exception = InvalidConfigurationException::forDataTypeMask(
            'unknown_type',
            'mask_value',
            'Type is not supported'
        );

        $this->assertInstanceOf(InvalidConfigurationException::class, $exception);
        $this->assertStringContainsString("Invalid data type mask for 'unknown_type'", $exception->getMessage());
        $this->assertStringContainsString('Type is not supported', $exception->getMessage());
    }

    #[Test]
    public function forConditionalRuleCreatesException(): void
    {
        $exception = InvalidConfigurationException::forConditionalRule(
            'invalid_rule',
            'Rule callback is not callable'
        );

        $this->assertInstanceOf(InvalidConfigurationException::class, $exception);
        $this->assertStringContainsString("Invalid conditional rule 'invalid_rule'", $exception->getMessage());
        $this->assertStringContainsString('Rule callback is not callable', $exception->getMessage());
    }

    #[Test]
    public function forParameterCreatesException(): void
    {
        $exception = InvalidConfigurationException::forParameter(
            'max_depth',
            10000,
            'Value exceeds maximum allowed'
        );

        $this->assertInstanceOf(InvalidConfigurationException::class, $exception);
        $this->assertStringContainsString("Invalid configuration parameter 'max_depth'", $exception->getMessage());
        $this->assertStringContainsString('Value exceeds maximum allowed', $exception->getMessage());
    }

    #[Test]
    public function emptyValueCreatesException(): void
    {
        $exception = InvalidConfigurationException::emptyValue('pattern');

        $this->assertInstanceOf(InvalidConfigurationException::class, $exception);
        $this->assertStringContainsString('Pattern cannot be empty', $exception->getMessage());
    }

    #[Test]
    public function exceedsMaxLengthCreatesException(): void
    {
        $exception = InvalidConfigurationException::exceedsMaxLength(
            'field_path',
            500,
            255
        );

        $this->assertInstanceOf(InvalidConfigurationException::class, $exception);
        $this->assertStringContainsString('Field_path length (500) exceeds maximum', $exception->getMessage());
        $this->assertStringContainsString('255', $exception->getMessage());
    }

    #[Test]
    public function invalidTypeCreatesException(): void
    {
        $exception = InvalidConfigurationException::invalidType(
            'callback',
            'callable',
            'string'
        );

        $this->assertInstanceOf(InvalidConfigurationException::class, $exception);
        $this->assertStringContainsString('Callback must be of type callable', $exception->getMessage());
        $this->assertStringContainsString('got string', $exception->getMessage());
    }

    #[Test]
    public function exceptionsIncludeContextInformation(): void
    {
        $exception = InvalidConfigurationException::forParameter(
            'test_param',
            ['key' => 'value'],
            'Test reason'
        );

        // Verify context is included
        $message = $exception->getMessage();
        $this->assertStringContainsString('Context:', $message);
        $this->assertStringContainsString('parameter', $message);
        $this->assertStringContainsString('value', $message);
        $this->assertStringContainsString('reason', $message);
    }

    #[Test]
    public function withContextAddsContextToMessage(): void
    {
        $exception = InvalidConfigurationException::withContext(
            'Base error message',
            ['custom_key' => 'custom_value', 'another_key' => 123]
        );

        $message = $exception->getMessage();
        $this->assertStringContainsString('Base error message', $message);
        $this->assertStringContainsString('Context:', $message);
        $this->assertStringContainsString('custom_key', $message);
        $this->assertStringContainsString('custom_value', $message);
        $this->assertStringContainsString('another_key', $message);
        $this->assertStringContainsString('123', $message);
    }

    #[Test]
    public function withContextHandlesEmptyContext(): void
    {
        $exception = InvalidConfigurationException::withContext('Error message', []);

        $this->assertSame('Error message', $exception->getMessage());
    }
}
