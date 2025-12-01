<?php

declare(strict_types=1);

namespace Tests\Audit;

use Ivuorinen\MonologGdprFilter\Audit\AuditContext;
use Ivuorinen\MonologGdprFilter\Audit\ErrorContext;
use Ivuorinen\MonologGdprFilter\Audit\StructuredAuditLogger;
use Ivuorinen\MonologGdprFilter\RateLimitedAuditLogger;
use Ivuorinen\MonologGdprFilter\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for StructuredAuditLogger.
 *
 * @api
 */
final class StructuredAuditLoggerTest extends TestCase
{
    /** @var array<array{path: string, original: mixed, masked: mixed}> */
    private array $logs;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->logs = [];
        RateLimiter::clearAll();
    }

    #[\Override]
    protected function tearDown(): void
    {
        RateLimiter::clearAll();
        parent::tearDown();
    }

    private function createBaseLogger(): callable
    {
        return function (string $path, mixed $original, mixed $masked): void {
            $this->logs[] = [
                'path' => $path,
                'original' => $original,
                'masked' => $masked
            ];
        };
    }

    public function testBasicLogging(): void
    {
        $logger = new StructuredAuditLogger($this->createBaseLogger());

        $logger->log('user.email', 'john@example.com', '[MASKED]');

        $this->assertCount(1, $this->logs);
        $this->assertSame('user.email', $this->logs[0]['path']);
        $this->assertSame('john@example.com', $this->logs[0]['original']);
        $this->assertSame('[MASKED]', $this->logs[0]['masked']);
    }

    public function testLogWithContext(): void
    {
        $logger = new StructuredAuditLogger($this->createBaseLogger());
        $context = AuditContext::success(AuditContext::OP_REGEX, 5.0);

        $logger->log('user.email', 'john@example.com', '[MASKED]', $context);

        $this->assertCount(1, $this->logs);
    }

    public function testLogSuccess(): void
    {
        $logger = new StructuredAuditLogger($this->createBaseLogger());

        $logger->logSuccess(
            'user.ssn',
            '123-45-6789',
            '[SSN]',
            AuditContext::OP_REGEX,
            10.5
        );

        $this->assertCount(1, $this->logs);
        $this->assertSame('user.ssn', $this->logs[0]['path']);
    }

    public function testLogFailure(): void
    {
        $logger = new StructuredAuditLogger($this->createBaseLogger());
        $error = ErrorContext::create('RegexError', 'Pattern failed');

        $logger->logFailure(
            'user.data',
            'sensitive value',
            AuditContext::OP_REGEX,
            $error
        );

        $this->assertCount(1, $this->logs);
        $this->assertSame('[MASKING_FAILED]', $this->logs[0]['masked']);
    }

    public function testLogRecovery(): void
    {
        $logger = new StructuredAuditLogger($this->createBaseLogger());

        $logger->logRecovery(
            'user.email',
            'john@example.com',
            '[MASKED]',
            AuditContext::OP_REGEX,
            2,
            25.0
        );

        $this->assertCount(1, $this->logs);
    }

    public function testLogSkipped(): void
    {
        $logger = new StructuredAuditLogger($this->createBaseLogger());

        $logger->logSkipped(
            'user.public_name',
            'John Doe',
            AuditContext::OP_CONDITIONAL,
            'Field not in mask list'
        );

        $this->assertCount(1, $this->logs);
        $this->assertSame('John Doe', $this->logs[0]['original']);
        $this->assertSame('John Doe', $this->logs[0]['masked']);
    }

    public function testWrapStaticFactory(): void
    {
        $logger = StructuredAuditLogger::wrap($this->createBaseLogger());

        $logger->log('test.path', 'original', 'masked');

        $this->assertCount(1, $this->logs);
    }

    public function testWithRateLimitedLogger(): void
    {
        $rateLimited = new RateLimitedAuditLogger(
            $this->createBaseLogger(),
            100,
            60
        );
        $logger = new StructuredAuditLogger($rateLimited);

        $logger->log('user.email', 'john@example.com', '[MASKED]');

        $this->assertCount(1, $this->logs);
    }

    public function testTimerMethods(): void
    {
        $logger = new StructuredAuditLogger($this->createBaseLogger());

        $start = $logger->startTimer();
        usleep(10000);
        $elapsed = $logger->elapsed($start);

        $this->assertGreaterThan(0, $elapsed);
        $this->assertLessThan(100, $elapsed);
    }

    public function testGetWrappedLogger(): void
    {
        $baseLogger = $this->createBaseLogger();
        $logger = new StructuredAuditLogger($baseLogger);

        $wrapped = $logger->getWrappedLogger();

        // Verify the wrapped logger works by calling it
        $wrapped('test.path', 'original', 'masked');
        $this->assertCount(1, $this->logs);
    }

    public function testDisableTimestamp(): void
    {
        $logger = new StructuredAuditLogger(
            $this->createBaseLogger(),
            includeTimestamp: false
        );

        $logger->log('test', 'original', 'masked');

        $this->assertCount(1, $this->logs);
    }

    public function testDisableDuration(): void
    {
        $logger = new StructuredAuditLogger(
            $this->createBaseLogger(),
            includeDuration: false
        );

        $logger->log('test', 'original', 'masked');

        $this->assertCount(1, $this->logs);
    }
}
