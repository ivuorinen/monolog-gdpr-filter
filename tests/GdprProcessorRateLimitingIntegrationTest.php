<?php

declare(strict_types=1);

namespace Tests;

use Tests\TestConstants;
use Ivuorinen\MonologGdprFilter\MaskConstants;
use DateTimeImmutable;
use Ivuorinen\MonologGdprFilter\ConditionalRuleFactory;
use Ivuorinen\MonologGdprFilter\Exceptions\AuditLoggingException;
use Ivuorinen\MonologGdprFilter\FieldMaskConfig;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\RateLimitedAuditLogger;
use Ivuorinen\MonologGdprFilter\RateLimiter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Tests\TestHelpers;

/**
 * Integration tests for GDPR processor with rate-limited audit logging.
 *
 * @api
 */
class GdprProcessorRateLimitingIntegrationTest extends TestCase
{
    use TestHelpers;

    /** @var array<array{path: string, original: mixed, masked: mixed, timestamp?: int}> */
    private array $auditLogs;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->auditLogs = [];
        RateLimiter::clearAll();
    }

    #[\Override]
    protected function tearDown(): void
    {
        RateLimiter::clearAll();
        parent::tearDown();
    }

    public function testProcessorWithRateLimitedAuditLogger(): void
    {
        // Create a base audit logger and wrap it with rate limiting
        $baseLogger = GdprProcessor::createArrayAuditLogger($this->auditLogs, false);
        $rateLimitedLogger = GdprProcessor::createRateLimitedAuditLogger($baseLogger, 'testing');

        $processor = $this->createProcessor(
            [TestConstants::PATTERN_EMAIL_TEST => MaskConstants::MASK_EMAIL],
            [TestConstants::FIELD_USER_EMAIL => 'masked@example.com'], // Add field path masking to generate audit logs
            [],
            $rateLimitedLogger
        );

        // Process multiple log records
        for ($i = 0; $i < 5; $i++) {
            $logRecord = new LogRecord(
                new DateTimeImmutable(),
                'test',
                Level::Info,
                sprintf(TestConstants::TEMPLATE_MESSAGE_EMAIL, $i),
                ['user' => [TestConstants::CONTEXT_EMAIL => sprintf(TestConstants::TEMPLATE_USER_EMAIL, $i)]] // Add context data to be masked
            );

            $result = $processor($logRecord);
            $this->assertStringContainsString(MaskConstants::MASK_EMAIL, $result->message);
            $this->assertEquals('masked@example.com', $result->context['user'][TestConstants::CONTEXT_EMAIL]);
        }

        // With testing profile (1000 per minute), all should go through
        $this->assertGreaterThan(0, count($this->auditLogs));
    }

    public function testProcessorWithStrictRateLimiting(): void
    {
        // Create a strict rate-limited audit logger
        $baseLogger = GdprProcessor::createArrayAuditLogger($this->auditLogs, false);
        $strictAuditLogger = GdprProcessor::createRateLimitedAuditLogger($baseLogger, 'strict');

        $processor = $this->createProcessor(
            [TestConstants::PATTERN_EMAIL_TEST => MaskConstants::MASK_EMAIL],
            [],
            [],
            $strictAuditLogger
        );

        // Process many log records to trigger rate limiting
        $processedCount = 0;
        for ($i = 0; $i < 60; $i++) {
            $logRecord = new LogRecord(
                new DateTimeImmutable(),
                'test',
                Level::Info,
                sprintf(TestConstants::TEMPLATE_MESSAGE_EMAIL, $i),
                []
            );

            $result = $processor($logRecord);
            if (str_contains($result->message, MaskConstants::MASK_EMAIL)) {
                $processedCount++;
            }
        }

        // All messages should be processed (rate limiting only affects audit logs)
        $this->assertSame(60, $processedCount);

        // But audit logs should be rate limited (strict = 50 per minute)
        $this->assertLessThanOrEqual(52, count($this->auditLogs)); // 50 + some rate limit warnings
    }

    public function testMultipleOperationTypesWithRateLimiting(): void
    {
        $baseLogger = GdprProcessor::createArrayAuditLogger($this->auditLogs, false);
        $rateLimitedLogger = new RateLimitedAuditLogger($baseLogger, 3, 60); // Very restrictive

        $processor = $this->createProcessor(
            [TestConstants::PATTERN_EMAIL_TEST => MaskConstants::MASK_EMAIL],
            [TestConstants::FIELD_USER_EMAIL => 'user@masked.com'],
            [],
            $rateLimitedLogger
        );

        // This will generate both regex masking and context masking audit logs
        $logRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            TestConstants::MESSAGE_WITH_EMAIL,
            ['user' => [TestConstants::CONTEXT_EMAIL => TestConstants::EMAIL_USER]]
        );

        // Process the same record multiple times
        for ($i = 0; $i < 10; $i++) {
            $processor($logRecord);
        }

        // Should have some audit logs but not all due to rate limiting
        $this->assertGreaterThan(0, count($this->auditLogs));
        $this->assertLessThan(20, count($this->auditLogs)); // Would be 20 without rate limiting (2 per record * 10)

        // Should contain rate limit warnings
        $rateLimitWarnings = array_filter(
            $this->auditLogs,
            fn(array $log): bool => $log['path'] === 'rate_limit_exceeded'
        );
        $this->assertGreaterThan(0, count($rateLimitWarnings));
    }

    public function testConditionalMaskingWithRateLimitedAuditLogger(): void
    {
        $baseLogger = GdprProcessor::createArrayAuditLogger($this->auditLogs, false);
        $rateLimitedLogger = new RateLimitedAuditLogger($baseLogger, 5, 60);

        $processor = $this->createProcessor(
            [TestConstants::PATTERN_EMAIL_TEST => MaskConstants::MASK_EMAIL],
            [],
            [],
            $rateLimitedLogger,
            100,
            [],
            [
                'error_level' => ConditionalRuleFactory::createLevelBasedRule(['Error'])
            ]
        );

        // Test with ERROR level (should mask and generate audit logs)
        for ($i = 0; $i < 5; $i++) {
            $errorRecord = new LogRecord(
                new DateTimeImmutable(),
                'test',
                Level::Error,
                sprintf('Error %d with test@example.com', $i),
                []
            );

            $result = $processor($errorRecord);
            $this->assertStringContainsString(MaskConstants::MASK_EMAIL, $result->message);
        }

        // Test with INFO level (should not mask, but generates conditional skip audit logs)
        for ($i = 0; $i < 10; $i++) {
            $infoRecord = new LogRecord(
                new DateTimeImmutable(),
                'test',
                Level::Info,
                sprintf('Info %d with test@example.com', $i),
                []
            );

            $result = $processor($infoRecord);
            $this->assertStringContainsString(TestConstants::EMAIL_TEST, $result->message);
        }

        // Should have audit logs for both masking and conditional skips
        $this->assertGreaterThan(0, count($this->auditLogs));

        // Check for conditional skip logs
        $conditionalSkips = array_filter($this->auditLogs, fn(array $log): bool => $log['path'] === 'conditional_skip');
        $this->assertGreaterThan(0, count($conditionalSkips));
    }

    public function testDataTypeMaskingWithRateLimitedAuditLogger(): void
    {
        $baseLogger = GdprProcessor::createArrayAuditLogger($this->auditLogs, false);
        $rateLimitedLogger = new RateLimitedAuditLogger($baseLogger, 10, 60);

        $processor = $this->createProcessor(
            // Add a regex pattern to ensure masking happens
            [TestConstants::PATTERN_EMAIL_TEST => MaskConstants::MASK_EMAIL],
            [
                'text' => FieldMaskConfig::useProcessorPatterns(),
                'number' => '999'
            ], // Use field path masking to generate audit logs
            [],
            $rateLimitedLogger,
            100,
            [
                'string' => MaskConstants::MASK_STRING,
                'integer' => MaskConstants::MASK_INT
            ] // Won't be used due to field paths
        );

        // Process records with different data types
        for ($i = 0; $i < 8; $i++) {
            $logRecord = new LogRecord(
                new DateTimeImmutable(),
                'test',
                Level::Info,
                "Data type test with test@example.com", // Include regex pattern to trigger audit logs
                [
                    'text' => sprintf(
                        'String value %d with test@example.com',
                        $i
                    ), // This will be masked by field path regex
                    'number' => $i * 10,
                    'flag' => true
                ]
            );

            $result = $processor($logRecord);
            $this->assertStringContainsString(MaskConstants::MASK_EMAIL, $result->message);
            $this->assertStringContainsString(
                MaskConstants::MASK_EMAIL,
                (string) $result->context['text']
            ); // Field path regex masking
            $this->assertEquals('999', $result->context['number']); // Field path static replacement
            $this->assertTrue($result->context['flag']); // Boolean not masked (no field path for it)
        }

        // Should have audit logs for field path masking
        $this->assertGreaterThan(0, count($this->auditLogs));
    }

    public function testRateLimitingPreventsCascadingFailures(): void
    {
        // Simulate a scenario where audit logging might fail or be slow
        $failingAuditLogger = function (string $path, mixed $original, mixed $masked): void {
            // Simulate work that might fail or be slow
            if (str_contains($path, 'error')) {
                throw AuditLoggingException::callbackFailed($path, $original, $masked, 'Audit logging failed');
            }

            $this->auditLogs[] = ['path' => $path, 'original' => $original, TestConstants::DATA_MASKED => $masked, 'timestamp' => time()];
        };

        $rateLimitedLogger = new RateLimitedAuditLogger($failingAuditLogger, 2, 60);

        $processor = $this->createProcessor(
            [TestConstants::PATTERN_EMAIL_TEST => MaskConstants::MASK_EMAIL],
            [],
            [],
            $rateLimitedLogger
        );

        // This should not fail even if audit logging has issues
        $logRecord = $this->createLogRecord(TestConstants::MESSAGE_WITH_EMAIL);

        // Processing should succeed regardless of audit logger issues
        $result = $processor($logRecord);
        $this->assertStringContainsString(MaskConstants::MASK_EMAIL, $result->message);
    }

    public function testRateLimitStatsAccessibility(): void
    {
        $baseLogger = GdprProcessor::createArrayAuditLogger($this->auditLogs, false);
        $rateLimitedLogger = RateLimitedAuditLogger::create($baseLogger, 'default');

        $processor = $this->createProcessor(
            [TestConstants::PATTERN_EMAIL_TEST => MaskConstants::MASK_EMAIL],
            [TestConstants::FIELD_USER_EMAIL => 'user@masked.com'], // Add field path masking to generate more audit logs
            [],
            $rateLimitedLogger
        );

        // Generate some audit activity
        for ($i = 0; $i < 3; $i++) {
            $logRecord = new LogRecord(
                new DateTimeImmutable(),
                'test',
                Level::Info,
                sprintf(TestConstants::TEMPLATE_MESSAGE_EMAIL, $i),
                ['user' => [TestConstants::CONTEXT_EMAIL => 'original@example.com']]
            );

            $processor($logRecord);
        }

        // Should have some audit logs now
        $this->assertGreaterThan(0, count($this->auditLogs));

        // Access rate limit statistics
        $stats = $rateLimitedLogger->getRateLimitStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('audit:general_operations', $stats);
        $this->assertGreaterThan(0, $stats['audit:general_operations']['current_requests']);
    }
}
