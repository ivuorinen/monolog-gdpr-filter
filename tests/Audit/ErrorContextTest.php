<?php

declare(strict_types=1);

namespace Tests\Audit;

use Exception;
use InvalidArgumentException;
use Ivuorinen\MonologGdprFilter\Audit\ErrorContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for ErrorContext value object.
 *
 * @api
 */
final class ErrorContextTest extends TestCase
{
    public function testBasicConstruction(): void
    {
        $context = new ErrorContext(
            errorType: 'TestError',
            message: 'Something went wrong',
            code: 42,
            file: '/path/to/file.php',
            line: 123,
            metadata: ['key' => 'value']
        );

        $this->assertSame('TestError', $context->errorType);
        $this->assertSame('Something went wrong', $context->message);
        $this->assertSame(42, $context->code);
        $this->assertSame('/path/to/file.php', $context->file);
        $this->assertSame(123, $context->line);
        $this->assertSame(['key' => 'value'], $context->metadata);
    }

    public function testFromThrowable(): void
    {
        $exception = new RuntimeException('Test exception', 500);
        $context = ErrorContext::fromThrowable($exception);

        $this->assertSame(RuntimeException::class, $context->errorType);
        $this->assertSame('Test exception', $context->message);
        $this->assertSame(500, $context->code);
        $this->assertNull($context->file);
        $this->assertNull($context->line);
    }

    public function testFromThrowableWithSensitiveDetails(): void
    {
        $exception = new Exception('Error at /home/user/app');
        $context = ErrorContext::fromThrowable($exception, includeSensitive: true);

        $this->assertNotNull($context->file);
        $this->assertNotNull($context->line);
        $this->assertArrayHasKey('trace', $context->metadata);
    }

    public function testCreate(): void
    {
        $context = ErrorContext::create(
            'CustomError',
            'Error message',
            ['detail' => 'info']
        );

        $this->assertSame('CustomError', $context->errorType);
        $this->assertSame('Error message', $context->message);
        $this->assertSame(0, $context->code);
        $this->assertArrayHasKey('detail', $context->metadata);
    }

    public function testSanitizesPasswordsInMessage(): void
    {
        $message = 'Connection failed: password=secret123';
        $context = ErrorContext::create('DbError', $message);

        $this->assertStringNotContainsString('secret123', $context->message);
        $this->assertStringContainsString('[REDACTED]', $context->message);
    }

    public function testSanitizesApiKeysInMessage(): void
    {
        $message = 'Auth failed: api_key=sk_live_1234567890';
        $context = ErrorContext::create('ApiError', $message);

        $this->assertStringNotContainsString('sk_live_1234567890', $context->message);
        $this->assertStringContainsString('[REDACTED]', $context->message);
    }

    public function testSanitizesTokensInMessage(): void
    {
        $message = 'Auth failed with bearer abc123def456';
        $context = ErrorContext::create('AuthError', $message);

        $this->assertStringNotContainsString('abc123def456', $context->message);
        $this->assertStringContainsString('[REDACTED]', $context->message);
    }

    public function testSanitizesTokenValueInMessage(): void
    {
        $message = 'Invalid token=secret_value_here';
        $context = ErrorContext::create('AuthError', $message);

        $this->assertStringNotContainsString('secret_value_here', $context->message);
        $this->assertStringContainsString('[REDACTED]', $context->message);
    }

    public function testSanitizesConnectionStrings(): void
    {
        $message = 'Failed: redis://admin:password@localhost:6379';
        $context = ErrorContext::create('ConnError', $message);

        $this->assertStringNotContainsString('password', $context->message);
        $this->assertStringContainsString('[REDACTED]', $context->message);
    }

    public function testSanitizesUserCredentials(): void
    {
        $message = 'DB error: user=admin host=secret.internal.com';
        $context = ErrorContext::create('DbError', $message);

        $this->assertStringNotContainsString('admin', $context->message);
        $this->assertStringNotContainsString('secret.internal.com', $context->message);
        $this->assertStringContainsString('[REDACTED]', $context->message);
    }

    public function testSanitizesFilePaths(): void
    {
        $message = 'Cannot read /var/www/secret-app/config/credentials.php';
        $context = ErrorContext::create('FileError', $message);

        $this->assertStringNotContainsString('/var/www/secret-app', $context->message);
        $this->assertStringContainsString('[PATH_REDACTED]', $context->message);
    }

    public function testToArray(): void
    {
        $context = new ErrorContext(
            errorType: 'TestError',
            message: 'Test message',
            code: 100,
            file: '/test/file.php',
            line: 50,
            metadata: ['key' => 'value']
        );

        $array = $context->toArray();

        $this->assertArrayHasKey('error_type', $array);
        $this->assertArrayHasKey('message', $array);
        $this->assertArrayHasKey('code', $array);
        $this->assertArrayHasKey('file', $array);
        $this->assertArrayHasKey('line', $array);
        $this->assertArrayHasKey('metadata', $array);

        $this->assertSame('TestError', $array['error_type']);
        $this->assertSame('Test message', $array['message']);
        $this->assertSame(100, $array['code']);
    }

    public function testToArrayOmitsNullValues(): void
    {
        $context = ErrorContext::create('Error', 'Message');

        $array = $context->toArray();

        $this->assertArrayNotHasKey('file', $array);
        $this->assertArrayNotHasKey('line', $array);
    }

    public function testToArrayOmitsEmptyMetadata(): void
    {
        $context = ErrorContext::create('Error', 'Message', []);

        $array = $context->toArray();

        $this->assertArrayNotHasKey('metadata', $array);
    }

    public function testFromThrowableWithNestedException(): void
    {
        $inner = new InvalidArgumentException('Inner error');
        $outer = new RuntimeException('Outer error', 0, $inner);

        $context = ErrorContext::fromThrowable($outer);

        $this->assertSame(RuntimeException::class, $context->errorType);
        $this->assertStringContainsString('Outer error', $context->message);
    }
}
