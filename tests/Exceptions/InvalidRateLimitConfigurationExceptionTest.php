<?php

declare(strict_types=1);

namespace Tests\Exceptions;

use Ivuorinen\MonologGdprFilter\Exceptions\InvalidRateLimitConfigurationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test InvalidRateLimitConfigurationException factory methods.
 *
 * @api
 */
#[CoversClass(InvalidRateLimitConfigurationException::class)]
class InvalidRateLimitConfigurationExceptionTest extends TestCase
{
    #[Test]
    public function invalidMaxRequestsCreatesException(): void
    {
        $exception = InvalidRateLimitConfigurationException::invalidMaxRequests(0);

        $this->assertInstanceOf(InvalidRateLimitConfigurationException::class, $exception);
        $this->assertStringContainsString('Maximum requests must be a positive integer', $exception->getMessage());
        $this->assertStringContainsString('max_requests', $exception->getMessage());
    }

    #[Test]
    public function invalidTimeWindowCreatesException(): void
    {
        $exception = InvalidRateLimitConfigurationException::invalidTimeWindow(-5);

        $this->assertInstanceOf(InvalidRateLimitConfigurationException::class, $exception);
        $this->assertStringContainsString('Time window must be a positive integer', $exception->getMessage());
        $this->assertStringContainsString('time_window', $exception->getMessage());
    }

    #[Test]
    public function invalidCleanupIntervalCreatesException(): void
    {
        $exception = InvalidRateLimitConfigurationException::invalidCleanupInterval(0);

        $this->assertInstanceOf(InvalidRateLimitConfigurationException::class, $exception);
        $this->assertStringContainsString('Cleanup interval must be a positive integer', $exception->getMessage());
        $this->assertStringContainsString('cleanup_interval', $exception->getMessage());
    }

    #[Test]
    public function timeWindowTooShortCreatesException(): void
    {
        $exception = InvalidRateLimitConfigurationException::timeWindowTooShort(5, 10);

        $this->assertInstanceOf(InvalidRateLimitConfigurationException::class, $exception);
        $this->assertStringContainsString('Time window (5 seconds) is too short', $exception->getMessage());
        $this->assertStringContainsString('minimum is 10 seconds', $exception->getMessage());
    }

    #[Test]
    public function cleanupIntervalTooShortCreatesException(): void
    {
        $exception = InvalidRateLimitConfigurationException::cleanupIntervalTooShort(30, 60);

        $this->assertInstanceOf(InvalidRateLimitConfigurationException::class, $exception);
        $this->assertStringContainsString('Cleanup interval (30 seconds) is too short', $exception->getMessage());
        $this->assertStringContainsString('minimum is 60 seconds', $exception->getMessage());
    }

    #[Test]
    public function emptyKeyCreatesException(): void
    {
        $exception = InvalidRateLimitConfigurationException::emptyKey();

        $this->assertInstanceOf(InvalidRateLimitConfigurationException::class, $exception);
        $this->assertStringContainsString('Rate limiting key cannot be empty', $exception->getMessage());
        $this->assertStringContainsString('key', $exception->getMessage());
    }

    #[Test]
    public function keyTooLongCreatesException(): void
    {
        $longKey = str_repeat('a', 300);
        $exception = InvalidRateLimitConfigurationException::keyTooLong($longKey, 250);

        $this->assertInstanceOf(InvalidRateLimitConfigurationException::class, $exception);
        $this->assertStringContainsString('Rate limiting key length (300) exceeds maximum', $exception->getMessage());
        $this->assertStringContainsString('250 characters', $exception->getMessage());
    }

    #[Test]
    public function invalidKeyFormatCreatesException(): void
    {
        $exception = InvalidRateLimitConfigurationException::invalidKeyFormat(
            'Key contains invalid characters'
        );

        $this->assertInstanceOf(InvalidRateLimitConfigurationException::class, $exception);
        $this->assertStringContainsString('Key contains invalid characters', $exception->getMessage());
        $this->assertStringContainsString('key', $exception->getMessage());
    }

    #[Test]
    public function forParameterCreatesException(): void
    {
        $exception = InvalidRateLimitConfigurationException::forParameter(
            'custom_param',
            'invalid_value',
            'Must meet specific criteria'
        );

        $this->assertInstanceOf(InvalidRateLimitConfigurationException::class, $exception);
        $this->assertStringContainsString('Invalid rate limit parameter', $exception->getMessage());
        $this->assertStringContainsString('custom_param', $exception->getMessage());
        $this->assertStringContainsString('Must meet specific criteria', $exception->getMessage());
    }

    #[Test]
    public function exceptionsIncludeContextInformation(): void
    {
        $exception = InvalidRateLimitConfigurationException::invalidMaxRequests(1000000);

        // Verify context is included
        $message = $exception->getMessage();
        $this->assertStringContainsString('Context:', $message);
        $this->assertStringContainsString('parameter', $message);
        $this->assertStringContainsString('value', $message);
    }
}
