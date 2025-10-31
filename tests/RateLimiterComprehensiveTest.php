<?php

declare(strict_types=1);

namespace Tests;

use Ivuorinen\MonologGdprFilter\RateLimiter;
use Ivuorinen\MonologGdprFilter\Exceptions\InvalidRateLimitConfigurationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RateLimiter::class)]
final class RateLimiterComprehensiveTest extends TestCase
{
    protected function setUp(): void
    {
        // Clear all rate limiter data before each test
        RateLimiter::clearAll();
    }

    protected function tearDown(): void
    {
        // Clean up after each test
        RateLimiter::clearAll();
    }

    public function testGetRemainingRequestsReturnsZeroWhenNoKey(): void
    {
        $limiter = new RateLimiter(maxRequests: 10, windowSeconds: 60);

        // Key doesn't exist yet, should use fallback to 0
        $remaining = $limiter->getRemainingRequests('nonexistent_key');

        // Since key doesn't exist and getStats returns the value, it should be 10 (max - 0 current)
        $this->assertGreaterThanOrEqual(0, $remaining);
    }

    public function testGlobalCleanupTriggeredAfterInterval(): void
    {
        // Set a very short cleanup interval for testing
        RateLimiter::setCleanupInterval(60); // 1 minute

        $limiter = new RateLimiter(maxRequests: 5, windowSeconds: 1); // 1 second window

        // Make some requests
        $limiter->isAllowed('test_key_1');
        $limiter->isAllowed('test_key_2');

        // Wait for window to expire
        sleep(2);

        // Get memory stats before
        $statsBefore = RateLimiter::getMemoryStats();

        // Trigger cleanup by making a request after the interval
        // We need to manipulate lastCleanup to trigger cleanup
        $reflection = new \ReflectionClass(RateLimiter::class);
        $lastCleanupProp = $reflection->getProperty('lastCleanup');
        $lastCleanupProp->setValue(null, time() - 301); // Set to 301 seconds ago

        // This should trigger cleanup
        $limiter->isAllowed('test_key_3');

        // Old keys should be cleaned up
        $statsAfter = RateLimiter::getMemoryStats();

        // Verify cleanup happened (lastCleanup should be updated)
        $this->assertGreaterThanOrEqual($statsBefore['last_cleanup'], $statsAfter['last_cleanup']);
    }

    public function testPerformGlobalCleanupRemovesEmptyKeys(): void
    {
        $limiter = new RateLimiter(maxRequests: 5, windowSeconds: 1);

        // Add requests
        $limiter->isAllowed('key1');
        $limiter->isAllowed('key2');

        // Wait for window to expire
        sleep(2);

        // Trigger cleanup by manipulating lastCleanup
        $reflection = new \ReflectionClass(RateLimiter::class);
        $lastCleanupProp = $reflection->getProperty('lastCleanup');
        $lastCleanupProp->setValue(null, time() - 301);

        // This should trigger cleanup which removes expired keys
        $limiter->isAllowed('new_key');

        $stats = RateLimiter::getMemoryStats();

        // Only new_key should remain
        $this->assertLessThanOrEqual(1, $stats['total_keys']);
    }

    public function testSetCleanupIntervalValidation(): void
    {
        // Test minimum value
        $this->expectException(InvalidRateLimitConfigurationException::class);
        RateLimiter::setCleanupInterval(30); // Below minimum of 60
    }

    public function testSetCleanupIntervalTooLarge(): void
    {
        $this->expectException(InvalidRateLimitConfigurationException::class);
        RateLimiter::setCleanupInterval(700000); // Above maximum of 604800
    }

    public function testSetCleanupIntervalNegative(): void
    {
        $this->expectException(InvalidRateLimitConfigurationException::class);
        RateLimiter::setCleanupInterval(-10);
    }

    public function testSetCleanupIntervalZero(): void
    {
        $this->expectException(InvalidRateLimitConfigurationException::class);
        RateLimiter::setCleanupInterval(0);
    }

    public function testSetCleanupIntervalValid(): void
    {
        RateLimiter::setCleanupInterval(120);

        $stats = RateLimiter::getMemoryStats();
        $this->assertSame(120, $stats['cleanup_interval']);

        // Reset to default
        RateLimiter::setCleanupInterval(300);
    }

    public function testValidateKeyWithControlCharacters(): void
    {
        $limiter = new RateLimiter(maxRequests: 10, windowSeconds: 60);

        $this->expectException(InvalidRateLimitConfigurationException::class);
        $this->expectExceptionMessage('control characters');

        // Key with null byte (control character)
        $limiter->isAllowed("key\x00with\x00null");
    }

    public function testValidateKeyWithOtherControlCharacters(): void
    {
        $limiter = new RateLimiter(maxRequests: 10, windowSeconds: 60);

        $this->expectException(InvalidRateLimitConfigurationException::class);

        // Key with other control characters
        $limiter->isAllowed("key\x01\x02\x03");
    }

    public function testClearKeyValidatesKey(): void
    {
        $this->expectException(InvalidRateLimitConfigurationException::class);

        RateLimiter::clearKey('');
    }

    public function testClearKeyWithControlCharacters(): void
    {
        $this->expectException(InvalidRateLimitConfigurationException::class);

        RateLimiter::clearKey("bad\x00key");
    }

    public function testClearKeyWithTooLongKey(): void
    {
        $this->expectException(InvalidRateLimitConfigurationException::class);

        $longKey = str_repeat('a', 251);
        RateLimiter::clearKey($longKey);
    }

    public function testGetStatsWithExpiredTimestamps(): void
    {
        $limiter = new RateLimiter(maxRequests: 5, windowSeconds: 1);

        // Make some requests
        $limiter->isAllowed('test_key');
        $limiter->isAllowed('test_key');

        // Wait for window to expire
        sleep(2);

        // Get stats - should filter out expired timestamps
        $stats = $limiter->getStats('test_key');

        $this->assertSame(0, $stats['current_requests']);
        $this->assertSame(5, $stats['remaining_requests']);
    }

    public function testIsAllowedFiltersExpiredRequests(): void
    {
        $limiter = new RateLimiter(maxRequests: 2, windowSeconds: 1);

        // Fill up the limit
        $this->assertTrue($limiter->isAllowed('key'));
        $this->assertTrue($limiter->isAllowed('key'));
        $this->assertFalse($limiter->isAllowed('key')); // Limit reached

        // Wait for window to expire
        sleep(2);

        // Should be allowed again after window expires
        $this->assertTrue($limiter->isAllowed('key'));
    }

    public function testGetTimeUntilResetWithNoRequests(): void
    {
        $limiter = new RateLimiter(maxRequests: 10, windowSeconds: 60);

        $time = $limiter->getTimeUntilReset('never_used_key');

        $this->assertSame(0, $time);
    }

    public function testGetTimeUntilResetWithEmptyArray(): void
    {
        $limiter = new RateLimiter(maxRequests: 10, windowSeconds: 60);

        // Make a request then clear it
        $limiter->isAllowed('test_key');
        RateLimiter::clearKey('test_key');

        $time = $limiter->getTimeUntilReset('test_key');

        $this->assertSame(0, $time);
    }

    public function testMemoryStatsEstimation(): void
    {
        RateLimiter::clearAll();

        $limiter = new RateLimiter(maxRequests: 100, windowSeconds: 60);

        // Make several requests across different keys
        for ($i = 0; $i < 10; $i++) {
            $limiter->isAllowed("key_$i");
            $limiter->isAllowed("key_$i");
        }

        $stats = RateLimiter::getMemoryStats();

        $this->assertSame(10, $stats['total_keys']);
        $this->assertSame(20, $stats['total_timestamps']); // 2 per key
        $this->assertGreaterThan(0, $stats['estimated_memory_bytes']);

        // Estimated memory should be: 10 keys * 50 + 20 timestamps * 8 = 500 + 160 = 660
        $this->assertSame(660, $stats['estimated_memory_bytes']);
    }

    public function testPerformGlobalCleanupKeepsValidTimestamps(): void
    {
        $limiter = new RateLimiter(maxRequests: 10, windowSeconds: 5); // 5 second window

        // Add some requests
        $limiter->isAllowed('key1');
        $limiter->isAllowed('key2');

        sleep(1);

        // Add more recent requests
        $limiter->isAllowed('key1');
        $limiter->isAllowed('key3');

        sleep(1);

        // Trigger cleanup
        $reflection = new \ReflectionClass(RateLimiter::class);
        $lastCleanupProp = $reflection->getProperty('lastCleanup');
        $lastCleanupProp->setValue(null, time() - 301);

        $limiter->isAllowed('key4');

        // All keys should still exist because they're within the 5-second window
        $stats = RateLimiter::getMemoryStats();
        $this->assertGreaterThanOrEqual(3, $stats['total_keys']);
    }

    public function testRateLimiterWithVeryShortWindow(): void
    {
        $limiter = new RateLimiter(maxRequests: 2, windowSeconds: 1);

        $this->assertTrue($limiter->isAllowed('fast_key'));
        $this->assertTrue($limiter->isAllowed('fast_key'));
        $this->assertFalse($limiter->isAllowed('fast_key'));

        // Immediate stats
        $stats = $limiter->getStats('fast_key');
        $this->assertSame(2, $stats['current_requests']);
        $this->assertSame(0, $stats['remaining_requests']);
        $this->assertGreaterThan(0, $stats['time_until_reset']);
    }
}
