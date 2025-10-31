<?php

declare(strict_types=1);

namespace Tests\Exceptions;

use Ivuorinen\MonologGdprFilter\Exceptions\AuditLoggingException;
use Ivuorinen\MonologGdprFilter\MaskConstants;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\TestConstants;

#[CoversClass(AuditLoggingException::class)]
final class AuditLoggingExceptionComprehensiveTest extends TestCase
{
    public function testCallbackFailedCreatesException(): void
    {
        $exception = AuditLoggingException::callbackFailed(
            TestConstants::FIELD_USER_EMAIL,
            TestConstants::EMAIL_TEST,
            MaskConstants::MASK_EMAIL_PATTERN,
            'Callback threw exception'
        );

        $this->assertInstanceOf(AuditLoggingException::class, $exception);
        $message = $exception->getMessage();
        $this->assertStringContainsString(TestConstants::FIELD_USER_EMAIL, $message);
        $this->assertStringContainsString('Callback threw exception', $message);
        $this->assertStringContainsString('callback_failure', $message);
    }

    public function testCallbackFailedWithPreviousException(): void
    {
        $previous = new \RuntimeException('Original error');

        $exception = AuditLoggingException::callbackFailed(
            'field',
            'value',
            TestConstants::DATA_MASKED,
            'Error',
            $previous
        );

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testCallbackFailedWithArrayValues(): void
    {
        $original = ['key1' => 'value1', 'key2' => 'value2'];
        $masked = ['key1' => 'MASKED'];

        $exception = AuditLoggingException::callbackFailed(
            'data',
            $original,
            $masked,
            'Processing failed'
        );

        $message = $exception->getMessage();
        $this->assertStringContainsString('data', $message);
        $this->assertStringContainsString('array', $message);
        $this->assertStringContainsString('Processing failed', $message);
    }

    public function testCallbackFailedWithLongString(): void
    {
        $longString = str_repeat('a', 150);

        $exception = AuditLoggingException::callbackFailed(
            'field',
            $longString,
            TestConstants::DATA_MASKED,
            'error'
        );

        $message = $exception->getMessage();
        // Should contain truncated preview with '...'
        $this->assertStringContainsString('...', $message);
    }

    public function testCallbackFailedWithObject(): void
    {
        $object = (object)['property' => 'value'];

        $exception = AuditLoggingException::callbackFailed(
            'field',
            $object,
            TestConstants::DATA_MASKED,
            'error'
        );

        $message = $exception->getMessage();
        $this->assertStringContainsString('object', $message);
    }

    public function testSerializationFailedCreatesException(): void
    {
        $value = ['data' => 'test'];

        $exception = AuditLoggingException::serializationFailed(
            'user.data',
            $value,
            'JSON encoding failed'
        );

        $this->assertInstanceOf(AuditLoggingException::class, $exception);
        $message = $exception->getMessage();
        $this->assertStringContainsString('user.data', $message);
        $this->assertStringContainsString('JSON encoding failed', $message);
        $this->assertStringContainsString('serialization_failure', $message);
    }

    public function testSerializationFailedWithPrevious(): void
    {
        $previous = new \Exception('Encoding error');

        $exception = AuditLoggingException::serializationFailed(
            'path',
            'value',
            'Failed',
            $previous
        );

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testRateLimitingFailedCreatesException(): void
    {
        $exception = AuditLoggingException::rateLimitingFailed(
            'audit_log',
            150,
            100,
            'Rate limit exceeded'
        );

        $this->assertInstanceOf(AuditLoggingException::class, $exception);
        $message = $exception->getMessage();
        $this->assertStringContainsString('audit_log', $message);
        $this->assertStringContainsString('Rate limit exceeded', $message);
        $this->assertStringContainsString('rate_limiting_failure', $message);
        $this->assertStringContainsString('150', $message);
        $this->assertStringContainsString('100', $message);
    }

    public function testRateLimitingFailedWithPrevious(): void
    {
        $previous = new \RuntimeException('Limiter error');

        $exception = AuditLoggingException::rateLimitingFailed(
            'operation',
            10,
            5,
            'Exceeded',
            $previous
        );

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testInvalidConfigurationCreatesException(): void
    {
        $config = ['profile' => 'invalid', 'max_requests' => -1];

        $exception = AuditLoggingException::invalidConfiguration(
            'Profile not found',
            $config
        );

        $this->assertInstanceOf(AuditLoggingException::class, $exception);
        $message = $exception->getMessage();
        $this->assertStringContainsString('Profile not found', $message);
        $this->assertStringContainsString('configuration_error', $message);
        $this->assertStringContainsString('invalid', $message);
    }

    public function testInvalidConfigurationWithPrevious(): void
    {
        $previous = new \InvalidArgumentException('Bad config');

        $exception = AuditLoggingException::invalidConfiguration(
            'Issue',
            [],
            $previous
        );

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testLoggerCreationFailedCreatesException(): void
    {
        $exception = AuditLoggingException::loggerCreationFailed(
            'RateLimitedLogger',
            'Invalid callback provided'
        );

        $this->assertInstanceOf(AuditLoggingException::class, $exception);
        $message = $exception->getMessage();
        $this->assertStringContainsString('RateLimitedLogger', $message);
        $this->assertStringContainsString('Invalid callback provided', $message);
        $this->assertStringContainsString('logger_creation_failure', $message);
    }

    public function testLoggerCreationFailedWithPrevious(): void
    {
        $previous = new \TypeError('Wrong type');

        $exception = AuditLoggingException::loggerCreationFailed(
            'Logger',
            'Failed',
            $previous
        );

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testAllExceptionTypesHaveCorrectCode(): void
    {
        $callback = AuditLoggingException::callbackFailed('p', 'o', 'm', 'r');
        $serialization = AuditLoggingException::serializationFailed('p', 'v', 'r');
        $rateLimit = AuditLoggingException::rateLimitingFailed('t', 1, 2, 'r');
        $config = AuditLoggingException::invalidConfiguration('i', []);
        $creation = AuditLoggingException::loggerCreationFailed('t', 'r');

        // All should have code 0 as specified in the method calls
        $this->assertSame(0, $callback->getCode());
        $this->assertSame(0, $serialization->getCode());
        $this->assertSame(0, $rateLimit->getCode());
        $this->assertSame(0, $config->getCode());
        $this->assertSame(0, $creation->getCode());
    }

    public function testValuePreviewWithResource(): void
    {
        // Create a resource which cannot be JSON encoded
        $resource = fopen('php://memory', 'r');
        $this->assertIsResource($resource);

        $exception = AuditLoggingException::callbackFailed(
            'field',
            $resource,
            TestConstants::DATA_MASKED,
            'error'
        );

        if (is_resource($resource)) {
            fclose($resource);
        }

        $message = $exception->getMessage();
        // Resource should be converted to string representation
        $this->assertStringContainsString('Resource', $message);
    }

    public function testValuePreviewWithInteger(): void
    {
        $exception = AuditLoggingException::callbackFailed(
            'field',
            12345,
            99999,
            'error'
        );

        $message = $exception->getMessage();
        $this->assertStringContainsString(TestConstants::DATA_NUMBER_STRING, $message);
        $this->assertStringContainsString('99999', $message);
    }

    public function testValuePreviewWithFloat(): void
    {
        $exception = AuditLoggingException::callbackFailed(
            'field',
            3.14159,
            0.0,
            'error'
        );

        $message = $exception->getMessage();
        $this->assertStringContainsString('3.14159', $message);
    }

    public function testValuePreviewWithBoolean(): void
    {
        $exception = AuditLoggingException::callbackFailed(
            'field',
            true,
            false,
            'error'
        );

        $message = $exception->getMessage();
        $this->assertStringContainsString('boolean', $message);
    }

    public function testValuePreviewWithNull(): void
    {
        $exception = AuditLoggingException::callbackFailed(
            'field',
            null,
            null,
            'error'
        );

        $message = $exception->getMessage();
        $this->assertStringContainsString('NULL', $message);
    }

    public function testValuePreviewWithLargeArray(): void
    {
        $largeArray = array_fill(0, 100, 'value');

        $exception = AuditLoggingException::callbackFailed(
            'field',
            $largeArray,
            TestConstants::DATA_MASKED,
            'error'
        );

        $message = $exception->getMessage();
        // Large JSON should be truncated
        $this->assertStringContainsString('...', $message);
    }
}
