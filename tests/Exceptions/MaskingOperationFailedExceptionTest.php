<?php

declare(strict_types=1);

namespace Tests\Exceptions;

use Ivuorinen\MonologGdprFilter\Exceptions\MaskingOperationFailedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MaskingOperationFailedException::class)]
final class MaskingOperationFailedExceptionTest extends TestCase
{
    public function testJsonMaskingFailedWithJsonError(): void
    {
        $exception = MaskingOperationFailedException::jsonMaskingFailed(
            '{"invalid": json}',
            'Malformed JSON',
            JSON_ERROR_SYNTAX
        );

        $message = $exception->getMessage();
        $this->assertStringContainsString('JSON masking failed', $message);
        $this->assertStringContainsString('Malformed JSON', $message);
        $this->assertStringContainsString('JSON Error:', $message);
        $this->assertStringContainsString('json_masking', $message);
    }

    public function testJsonMaskingFailedWithoutJsonError(): void
    {
        $exception = MaskingOperationFailedException::jsonMaskingFailed(
            '{"valid": "json"}',
            'Processing failed',
            0 // No JSON error
        );

        $message = $exception->getMessage();
        $this->assertStringContainsString('JSON masking failed', $message);
        $this->assertStringContainsString('Processing failed', $message);
        $this->assertStringNotContainsString('JSON Error:', $message);
    }

    public function testJsonMaskingFailedWithLongString(): void
    {
        $longJson = str_repeat('{"key": "value"},', 100);

        $exception = MaskingOperationFailedException::jsonMaskingFailed(
            $longJson,
            'Too large',
            0
        );

        $message = $exception->getMessage();
        // Should be truncated
        $this->assertStringContainsString('...', $message);
        $this->assertStringContainsString('json_length:', $message);
    }

    public function testRegexMaskingFailedWithLongInput(): void
    {
        $longInput = str_repeat('test ', 50);

        $exception = MaskingOperationFailedException::regexMaskingFailed(
            '/pattern/',
            $longInput,
            'PCRE error'
        );

        $message = $exception->getMessage();
        $this->assertStringContainsString('Regex masking failed', $message);
        $this->assertStringContainsString('/pattern/', $message);
        $this->assertStringContainsString('...', $message); // Truncated preview
    }

    public function testFieldPathMaskingFailedWithPrevious(): void
    {
        $previous = new \RuntimeException('Inner error');

        $exception = MaskingOperationFailedException::fieldPathMaskingFailed(
            'user.data',
            ['complex' => 'value'],
            'Failed',
            $previous
        );

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testCustomCallbackFailedWithAllTypes(): void
    {
        // Test with resource
        $resource = fopen('php://memory', 'r');

        $exception = MaskingOperationFailedException::customCallbackFailed(
            'field',
            $resource,
            'Callback error'
        );

        fclose($resource);

        $message = $exception->getMessage();
        $this->assertStringContainsString('Custom callback masking failed', $message);
        $this->assertStringContainsString('resource', $message);
    }

    public function testDataTypeMaskingFailedShowsTypes(): void
    {
        $exception = MaskingOperationFailedException::dataTypeMaskingFailed(
            'string',
            12345, // Integer value when string expected
            'Type mismatch'
        );

        $message = $exception->getMessage();
        $this->assertStringContainsString('Data type masking failed', $message);
        $this->assertStringContainsString('string', $message);
        $this->assertStringContainsString('integer', $message); // actual_type
    }

    public function testDataTypeMaskingFailedWithObjectValue(): void
    {
        $obj = (object) ['key' => 'value'];

        $exception = MaskingOperationFailedException::dataTypeMaskingFailed(
            'string',
            $obj,
            'Cannot convert object'
        );

        $message = $exception->getMessage();
        $this->assertStringContainsString('Data type masking failed', $message);
        $this->assertStringContainsString('object', $message);
        $this->assertStringContainsString('key', $message); // JSON preview
    }
}
