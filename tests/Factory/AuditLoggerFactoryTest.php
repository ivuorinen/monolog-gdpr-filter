<?php

declare(strict_types=1);

namespace Tests\Factory;

use Closure;
use Ivuorinen\MonologGdprFilter\Factory\AuditLoggerFactory;
use Ivuorinen\MonologGdprFilter\RateLimitedAuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditLoggerFactory::class)]
final class AuditLoggerFactoryTest extends TestCase
{
    public function testCreateReturnsFactoryInstance(): void
    {
        $factory = AuditLoggerFactory::create();

        $this->assertInstanceOf(AuditLoggerFactory::class, $factory);
    }

    public function testCreateRateLimitedReturnsRateLimitedLogger(): void
    {
        $factory = AuditLoggerFactory::create();
        $auditLogger = fn(string $path, mixed $original, mixed $masked): mixed => null;

        $result = $factory->createRateLimited($auditLogger);

        $this->assertInstanceOf(RateLimitedAuditLogger::class, $result);
    }

    public function testCreateRateLimitedWithProfile(): void
    {
        $factory = AuditLoggerFactory::create();
        $auditLogger = fn(string $path, mixed $original, mixed $masked): mixed => null;

        $result = $factory->createRateLimited($auditLogger, 'strict');

        $this->assertInstanceOf(RateLimitedAuditLogger::class, $result);
    }

    public function testCreateArrayLoggerReturnsClosureByDefault(): void
    {
        $factory = AuditLoggerFactory::create();
        $storage = [];

        $result = $factory->createArrayLogger($storage);

        $this->assertInstanceOf(Closure::class, $result);
    }

    public function testCreateArrayLoggerWithRateLimiting(): void
    {
        $factory = AuditLoggerFactory::create();
        $storage = [];

        $result = $factory->createArrayLogger($storage, true);

        $this->assertInstanceOf(RateLimitedAuditLogger::class, $result);
    }

    public function testCreateArrayLoggerStoresLogs(): void
    {
        $factory = AuditLoggerFactory::create();
        $storage = [];

        $logger = $factory->createArrayLogger($storage);
        $logger('test.path', 'original', 'masked');

        $this->assertCount(1, $storage);
        $this->assertSame('test.path', $storage[0]['path']);
        $this->assertSame('original', $storage[0]['original']);
        $this->assertSame('masked', $storage[0]['masked']);
        $this->assertArrayHasKey('timestamp', $storage[0]);
    }

    public function testCreateNullLoggerReturnsClosure(): void
    {
        $factory = AuditLoggerFactory::create();

        $result = $factory->createNullLogger();

        $this->assertInstanceOf(Closure::class, $result);
    }

    public function testCreateNullLoggerDoesNothing(): void
    {
        $factory = AuditLoggerFactory::create();
        $logger = $factory->createNullLogger();

        // Should not throw
        $logger('path', 'original', 'masked');
        $this->assertTrue(true);
    }

    public function testCreateCallbackLoggerReturnsClosure(): void
    {
        $factory = AuditLoggerFactory::create();
        $callback = fn(string $path, mixed $original, mixed $masked): mixed => null;

        $result = $factory->createCallbackLogger($callback);

        $this->assertInstanceOf(Closure::class, $result);
    }

    public function testCreateCallbackLoggerInvokesCallback(): void
    {
        $factory = AuditLoggerFactory::create();
        $calls = [];
        $callback = function (string $path, mixed $original, mixed $masked) use (&$calls): void {
            $calls[] = ['path' => $path, 'original' => $original, 'masked' => $masked];
        };

        $logger = $factory->createCallbackLogger($callback);
        $logger('test.path', 'original', 'masked');

        $this->assertCount(1, $calls);
        $this->assertSame('test.path', $calls[0]['path']);
    }

    /**
     * @psalm-suppress DeprecatedMethod - Testing deprecated method
     */
    public function testDeprecatedRateLimitedStaticMethod(): void
    {
        $auditLogger = fn(string $path, mixed $original, mixed $masked): mixed => null;

        $result = AuditLoggerFactory::rateLimited($auditLogger);

        $this->assertInstanceOf(RateLimitedAuditLogger::class, $result);
    }

    /**
     * @psalm-suppress DeprecatedMethod - Testing deprecated method
     */
    public function testDeprecatedArrayLoggerStaticMethod(): void
    {
        $storage = [];

        $result = AuditLoggerFactory::arrayLogger($storage);

        $this->assertInstanceOf(Closure::class, $result);
    }

    public function testMultipleLogEntriesStoredCorrectly(): void
    {
        $factory = AuditLoggerFactory::create();
        $storage = [];

        $logger = $factory->createArrayLogger($storage);
        $logger('path1', 'orig1', 'mask1');
        $logger('path2', 'orig2', 'mask2');
        $logger('path3', 'orig3', 'mask3');

        $this->assertCount(3, $storage);
        $this->assertSame('path1', $storage[0]['path']);
        $this->assertSame('path2', $storage[1]['path']);
        $this->assertSame('path3', $storage[2]['path']);
    }
}
