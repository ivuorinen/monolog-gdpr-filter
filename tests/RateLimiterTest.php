<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Ivuorinen\MonologGdprFilter\RateLimiter;

/**
 * Test rate limiting functionality.
 */
class RateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Clear rate limiter state before each test
        RateLimiter::clearAll();
    }

    protected function tearDown(): void
    {
        // Clean up after each test
        RateLimiter::clearAll();
        parent::tearDown();
    }

    public function testBasicRateLimiting(): void
    {
        $rateLimiter = new RateLimiter(3, 60); // 3 requests per 60 seconds
        $key = 'test_key';

        // First 3 requests should be allowed
        $this->assertTrue($rateLimiter->isAllowed($key));
        $this->assertTrue($rateLimiter->isAllowed($key));
        $this->assertTrue($rateLimiter->isAllowed($key));

        // 4th request should be denied
        $this->assertFalse($rateLimiter->isAllowed($key));
        $this->assertFalse($rateLimiter->isAllowed($key));
    }

    public function testRemainingRequests(): void
    {
        $rateLimiter = new RateLimiter(5, 60);
        $key = 'test_key';

        $this->assertEquals(5, $rateLimiter->getRemainingRequests($key));

        $rateLimiter->isAllowed($key); // Use 1 request
        $this->assertEquals(4, $rateLimiter->getRemainingRequests($key));

        $rateLimiter->isAllowed($key); // Use another request
        $this->assertEquals(3, $rateLimiter->getRemainingRequests($key));
    }

    public function testSlidingWindow(): void
    {
        $rateLimiter = new RateLimiter(2, 2); // 2 requests per 2 seconds
        $key = 'test_key';

        // Use up the limit
        $this->assertTrue($rateLimiter->isAllowed($key));
        $this->assertTrue($rateLimiter->isAllowed($key));
        $this->assertFalse($rateLimiter->isAllowed($key));

        // Wait for window to slide (simulate time passage)
        // In a real scenario, we'd wait, but for testing we'll manipulate the internal state
        sleep(3); // Wait longer than the window

        // Now requests should be allowed again
        $this->assertTrue($rateLimiter->isAllowed($key));
        $this->assertTrue($rateLimiter->isAllowed($key));
        $this->assertFalse($rateLimiter->isAllowed($key));
    }

    public function testMultipleKeys(): void
    {
        $rateLimiter = new RateLimiter(2, 60);

        // Each key should have its own limit
        $this->assertTrue($rateLimiter->isAllowed('key1'));
        $this->assertTrue($rateLimiter->isAllowed('key1'));
        $this->assertFalse($rateLimiter->isAllowed('key1')); // key1 exhausted

        // key2 should still work
        $this->assertTrue($rateLimiter->isAllowed('key2'));
        $this->assertTrue($rateLimiter->isAllowed('key2'));
        $this->assertFalse($rateLimiter->isAllowed('key2')); // key2 exhausted
    }

    public function testTimeUntilReset(): void
    {
        $rateLimiter = new RateLimiter(1, 10); // 1 request per 10 seconds
        $key = 'test_key';

        // Use the single allowed request
        $this->assertTrue($rateLimiter->isAllowed($key));

        // Check time until reset (should be around 10 seconds, allowing for some variance)
        $timeUntilReset = $rateLimiter->getTimeUntilReset($key);
        $this->assertGreaterThan(8, $timeUntilReset);
        $this->assertLessThanOrEqual(10, $timeUntilReset);
    }

    public function testGetStats(): void
    {
        $rateLimiter = new RateLimiter(5, 60);
        $key = 'test_key';

        // Initial stats
        $stats = $rateLimiter->getStats($key);
        $this->assertEquals(0, $stats['current_requests']);
        $this->assertEquals(5, $stats['remaining_requests']);
        $this->assertEquals(0, $stats['time_until_reset']);

        // After using some requests
        $rateLimiter->isAllowed($key);
        $rateLimiter->isAllowed($key);

        $stats = $rateLimiter->getStats($key);
        $this->assertEquals(2, $stats['current_requests']);
        $this->assertEquals(3, $stats['remaining_requests']);
        $this->assertGreaterThan(0, $stats['time_until_reset']);
    }

    public function testClearAll(): void
    {
        $rateLimiter = new RateLimiter(1, 60);
        $key = 'test_key';

        // Use up the limit
        $this->assertTrue($rateLimiter->isAllowed($key));
        $this->assertFalse($rateLimiter->isAllowed($key));

        // Clear all data
        RateLimiter::clearAll();

        // Should be able to make requests again
        $this->assertTrue($rateLimiter->isAllowed($key));
    }

    public function testZeroLimit(): void
    {
        $rateLimiter = new RateLimiter(0, 60); // No requests allowed
        $key = 'test_key';

        $this->assertFalse($rateLimiter->isAllowed($key));
        $this->assertEquals(0, $rateLimiter->getRemainingRequests($key));
    }

    public function testHighVolumeRequests(): void
    {
        $rateLimiter = new RateLimiter(10, 60);
        $key = 'high_volume_key';

        $allowedCount = 0;
        $deniedCount = 0;

        // Make 20 requests
        for ($i = 0; $i < 20; $i++) {
            if ($rateLimiter->isAllowed($key)) {
                $allowedCount++;
            } else {
                $deniedCount++;
            }
        }

        $this->assertSame(10, $allowedCount);
        $this->assertSame(10, $deniedCount);
    }

    public function testConcurrentKeyAccess(): void
    {
        $rateLimiter = new RateLimiter(3, 60);

        // Test multiple keys being used simultaneously
        $keys = ['key1', 'key2', 'key3', 'key4', 'key5'];

        foreach ($keys as $key) {
            // Each key should allow 3 requests
            $this->assertTrue($rateLimiter->isAllowed($key));
            $this->assertTrue($rateLimiter->isAllowed($key));
            $this->assertTrue($rateLimiter->isAllowed($key));
            $this->assertFalse($rateLimiter->isAllowed($key));
        }

        // Verify stats for each key
        foreach ($keys as $key) {
            $stats = $rateLimiter->getStats($key);
            $this->assertEquals(3, $stats['current_requests']);
            $this->assertEquals(0, $stats['remaining_requests']);
        }
    }

    public function testEdgeCaseEmptyKey(): void
    {
        $rateLimiter = new RateLimiter(2, 60);

        // Empty string key should work
        $this->assertTrue($rateLimiter->isAllowed(''));
        $this->assertTrue($rateLimiter->isAllowed(''));
        $this->assertFalse($rateLimiter->isAllowed(''));
    }

    public function testVeryShortWindow(): void
    {
        $rateLimiter = new RateLimiter(1, 1); // 1 request per 1 second
        $key = 'short_window';

        $this->assertTrue($rateLimiter->isAllowed($key));
        $this->assertFalse($rateLimiter->isAllowed($key));

        // Wait for the window to expire
        sleep(2);

        $this->assertTrue($rateLimiter->isAllowed($key));
    }
}
