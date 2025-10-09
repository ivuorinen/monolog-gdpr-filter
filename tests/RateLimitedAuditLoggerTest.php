<?php

declare(strict_types=1);

namespace Tests;

use Closure;
use PHPUnit\Framework\TestCase;
use Ivuorinen\MonologGdprFilter\RateLimitedAuditLogger;
use Ivuorinen\MonologGdprFilter\RateLimiter;

/**
 * Test rate-limited audit logging functionality.
 *
 * @api
 */
class RateLimitedAuditLoggerTest extends TestCase
{
    /** @var array<array{path: string, original: mixed, masked: mixed}> */
    private array $logStorage;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->logStorage = [];
        RateLimiter::clearAll();
    }

    #[\Override]
    protected function tearDown(): void
    {
        RateLimiter::clearAll();
        parent::tearDown();
    }

    /**
     * @psalm-return Closure(string, mixed, mixed):void
     */
    private function createTestAuditLogger(): Closure
    {
        return function (string $path, mixed $original, mixed $masked): void {
            $this->logStorage[] = [
                'path' => $path,
                'original' => $original,
                'masked' => $masked
            ];
        };
    }

    public function testBasicRateLimiting(): void
    {
        $baseLogger = $this->createTestAuditLogger();
        $rateLimitedLogger = new RateLimitedAuditLogger($baseLogger, 3, 60); // 3 per minute

        // First 3 logs should go through
        $rateLimitedLogger('test_operation', 'original1', 'masked1');
        $rateLimitedLogger('test_operation', 'original2', 'masked2');
        $rateLimitedLogger('test_operation', 'original3', 'masked3');

        $this->assertCount(3, $this->logStorage);

        // 4th log should be rate limited and generate a warning
        $rateLimitedLogger('test_operation', 'original4', 'masked4');

        // Should have 3 original logs + 1 rate limit warning = 4 total
        $this->assertCount(4, $this->logStorage);

        // The last log should be a rate limit warning
        $this->assertEquals('rate_limit_exceeded', $this->logStorage[3]['path']);
    }

    public function testDifferentOperationTypes(): void
    {
        $baseLogger = $this->createTestAuditLogger();
        $rateLimitedLogger = new RateLimitedAuditLogger($baseLogger, 2, 60); // 2 per minute per operation type

        // Different operation types should have separate rate limits
        $rateLimitedLogger('json_masked', 'original1', 'masked1');
        $rateLimitedLogger('json_masked', 'original2', 'masked2');
        $rateLimitedLogger('conditional_skip', 'original3', 'masked3');
        $rateLimitedLogger('conditional_skip', 'original4', 'masked4');
        $rateLimitedLogger('regex_error', 'original5', 'masked5');
        $rateLimitedLogger('regex_error', 'original6', 'masked6');

        // All should go through because they're different operation types
        $this->assertCount(6, $this->logStorage);

        // Now exceed the limit for json operations
        $rateLimitedLogger('json_encode_error', 'original7', 'masked7'); // This is json operation type

        // Should have 6 original logs + 1 rate limit warning = 7 total
        $this->assertCount(7, $this->logStorage);

        // The last log should be a rate limit warning
        $this->assertEquals('rate_limit_exceeded', $this->logStorage[6]['path']);
    }

    public function testRateLimitWarnings(): void
    {
        $baseLogger = $this->createTestAuditLogger();
        $rateLimitedLogger = new RateLimitedAuditLogger($baseLogger, 1, 60); // Very restrictive: 1 per minute

        // First log goes through
        $rateLimitedLogger('test_operation', 'original1', 'masked1');
        $this->assertCount(1, $this->logStorage);

        // Second log triggers rate limiting and should generate a warning
        $rateLimitedLogger('test_operation', 'original2', 'masked2');

        // Should have original log + rate limit warning
        $this->assertCount(2, $this->logStorage);
        $this->assertEquals('rate_limit_exceeded', $this->logStorage[1]['path']);
    }

    public function testFactoryProfiles(): void
    {
        $baseLogger = $this->createTestAuditLogger();

        // Test strict profile
        $strictLogger = RateLimitedAuditLogger::create($baseLogger, 'strict');
        $this->assertInstanceOf(RateLimitedAuditLogger::class, $strictLogger);

        // Test relaxed profile
        $relaxedLogger = RateLimitedAuditLogger::create($baseLogger, 'relaxed');
        $this->assertInstanceOf(RateLimitedAuditLogger::class, $relaxedLogger);

        // Test testing profile
        $testingLogger = RateLimitedAuditLogger::create($baseLogger, 'testing');
        $this->assertInstanceOf(RateLimitedAuditLogger::class, $testingLogger);

        // Test default profile
        $defaultLogger = RateLimitedAuditLogger::create($baseLogger, 'default');
        $this->assertInstanceOf(RateLimitedAuditLogger::class, $defaultLogger);
    }

    public function testStrictProfile(): void
    {
        $baseLogger = $this->createTestAuditLogger();
        $strictLogger = RateLimitedAuditLogger::create($baseLogger, 'strict'); // 50 per minute

        // Should allow 50 operations before rate limiting
        for ($i = 0; $i < 55; $i++) {
            $strictLogger("test_operation", 'original' . $i, 'masked' . $i);
        }

        // Should have 50 successful logs + some rate limit warnings
        $successfulLogs = array_filter(
            $this->logStorage,
            fn(array $log): bool => $log['path'] !== 'rate_limit_exceeded'
        );
        $this->assertCount(50, $successfulLogs);
    }

    public function testRelaxedProfile(): void
    {
        $baseLogger = $this->createTestAuditLogger();
        $relaxedLogger = RateLimitedAuditLogger::create($baseLogger, 'relaxed'); // 200 per minute

        // Should allow more operations
        for ($i = 0; $i < 150; $i++) {
            $relaxedLogger("test_operation", 'original' . $i, 'masked' . $i);
        }

        // All 150 should go through with relaxed profile
        $this->assertCount(150, $this->logStorage);
    }

    public function testRateLimitStats(): void
    {
        $baseLogger = $this->createTestAuditLogger();
        $rateLimitedLogger = new RateLimitedAuditLogger($baseLogger, 10, 60);

        // Make some requests
        $rateLimitedLogger('json_masked', 'original1', 'masked1');
        $rateLimitedLogger('conditional_skip', 'original2', 'masked2');
        $rateLimitedLogger('regex_error', 'original3', 'masked3');

        $stats = $rateLimitedLogger->getRateLimitStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('audit:json_operations', $stats);
        $this->assertArrayHasKey('audit:conditional_operations', $stats);
        $this->assertArrayHasKey('audit:regex_operations', $stats);

        // Check that the used operation types show activity
        $this->assertEquals(1, $stats['audit:json_operations']['current_requests']);
        $this->assertEquals(1, $stats['audit:conditional_operations']['current_requests']);
        $this->assertEquals(1, $stats['audit:regex_operations']['current_requests']);
    }

    public function testIsOperationAllowed(): void
    {
        $baseLogger = $this->createTestAuditLogger();
        $rateLimitedLogger = new RateLimitedAuditLogger($baseLogger, 2, 60);

        // Initially all operations should be allowed
        $this->assertTrue($rateLimitedLogger->isOperationAllowed('json_operations'));
        $this->assertTrue($rateLimitedLogger->isOperationAllowed('regex_operations'));

        // Use up the limit for json operations
        $rateLimitedLogger('json_masked', 'original1', 'masked1');
        $rateLimitedLogger('json_encode_error', 'original2', 'masked2');

        // json operations should now be at limit
        $this->assertFalse($rateLimitedLogger->isOperationAllowed('json_operations'));
        // Other operations should still be allowed
        $this->assertTrue($rateLimitedLogger->isOperationAllowed('regex_operations'));
    }

    public function testClearRateLimitData(): void
    {
        $baseLogger = $this->createTestAuditLogger();
        $rateLimitedLogger = new RateLimitedAuditLogger($baseLogger, 1, 60);

        // Use up the limit
        $rateLimitedLogger('test_operation', 'original1', 'masked1');
        $rateLimitedLogger('test_operation', 'original2', 'masked2'); // Should be blocked

        $this->assertCount(2, $this->logStorage); // 1 successful + 1 rate limit warning

        // Clear rate limit data
        $rateLimitedLogger->clearRateLimitData();

        // Should be able to log again
        $this->logStorage = []; // Clear log storage for clean test
        $rateLimitedLogger('test_operation', 'original3', 'masked3');
        $this->assertCount(1, $this->logStorage);
    }

    public function testOperationTypeClassification(): void
    {
        $baseLogger = $this->createTestAuditLogger();
        $rateLimitedLogger = new RateLimitedAuditLogger($baseLogger, 1, 60); // Very restrictive

        // Test that different paths are classified correctly
        $rateLimitedLogger('json_masked', 'original', 'masked');
        $rateLimitedLogger('json_encode_error', 'original', 'masked'); // Should be blocked (same type)

        $this->assertCount(2, $this->logStorage); // 1 successful + 1 rate limit warning

        $this->logStorage = []; // Reset

        $rateLimitedLogger('conditional_skip', 'original', 'masked');
        $rateLimitedLogger('conditional_error', 'original', 'masked'); // Should be blocked (same type)

        $this->assertCount(2, $this->logStorage); // 1 successful + 1 rate limit warning

        $this->logStorage = []; // Reset

        $rateLimitedLogger('regex_error', 'original', 'masked');
        $rateLimitedLogger('preg_replace_error', 'original', 'masked'); // Should be blocked (same type)

        $this->assertCount(2, $this->logStorage); // 1 successful + 1 rate limit warning
    }

    public function testNonCallableAuditLogger(): void
    {
        // Test with a non-callable audit logger
        $rateLimitedLogger = new RateLimitedAuditLogger('not_callable', 10, 60);

        // Should not throw an error, just silently handle the non-callable
        $rateLimitedLogger('test_operation', 'original', 'masked');

        // No logs should be created since the base logger is not callable
        $this->assertCount(0, $this->logStorage);
    }
}
