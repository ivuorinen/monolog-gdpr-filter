<?php

declare(strict_types=1);

namespace Tests\Recovery;

use Ivuorinen\MonologGdprFilter\Exceptions\MaskingOperationFailedException;
use Ivuorinen\MonologGdprFilter\Exceptions\RecursionDepthExceededException;
use Ivuorinen\MonologGdprFilter\MaskConstants;
use Ivuorinen\MonologGdprFilter\Recovery\FailureMode;
use Ivuorinen\MonologGdprFilter\Recovery\RecoveryResult;
use Ivuorinen\MonologGdprFilter\Recovery\RetryStrategy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for RetryStrategy.
 *
 * @api
 */
final class RetryStrategyTest extends TestCase
{
    public function testDefaultFactory(): void
    {
        $strategy = RetryStrategy::default();

        $this->assertSame(3, $strategy->getMaxAttempts());
        $this->assertSame(FailureMode::FAIL_SAFE, $strategy->getFailureMode());
    }

    public function testNoRetryFactory(): void
    {
        $strategy = RetryStrategy::noRetry();

        $this->assertSame(1, $strategy->getMaxAttempts());
    }

    public function testFastFactory(): void
    {
        $strategy = RetryStrategy::fast();

        $this->assertSame(2, $strategy->getMaxAttempts());
        $this->assertSame(FailureMode::FAIL_SAFE, $strategy->getFailureMode());
    }

    public function testThoroughFactory(): void
    {
        $strategy = RetryStrategy::thorough();

        $this->assertSame(5, $strategy->getMaxAttempts());
        $this->assertSame(FailureMode::FAIL_CLOSED, $strategy->getFailureMode());
    }

    public function testSuccessfulExecution(): void
    {
        $strategy = new RetryStrategy(maxAttempts: 3);
        $operation = fn(): string => '[MASKED]';

        $result = $strategy->execute($operation, 'original', 'test.path');

        $this->assertTrue($result->isSuccess());
        $this->assertSame('[MASKED]', $result->value);
        $this->assertSame(1, $result->attempts);
        $this->assertSame(RecoveryResult::OUTCOME_SUCCESS, $result->outcome);
    }

    public function testRecoveryAfterRetry(): void
    {
        $strategy = new RetryStrategy(
            maxAttempts: 3,
            baseDelayMs: 1,
            maxDelayMs: 5
        );

        $attemptCount = 0;
        $operation = function () use (&$attemptCount): string {
            $attemptCount++;
            if ($attemptCount < 3) {
                throw new RuntimeException('Temporary failure');
            }
            return '[MASKED]';
        };

        $result = $strategy->execute($operation, 'original', 'test.path');

        $this->assertTrue($result->isSuccess());
        $this->assertSame('[MASKED]', $result->value);
        $this->assertSame(3, $result->attempts);
        $this->assertSame(RecoveryResult::OUTCOME_RECOVERED, $result->outcome);
    }

    public function testFallbackAfterAllFailures(): void
    {
        $strategy = new RetryStrategy(
            maxAttempts: 2,
            baseDelayMs: 1,
            maxDelayMs: 5,
            failureMode: FailureMode::FAIL_SAFE
        );

        $operation = function (): never {
            throw new RuntimeException('Permanent failure');
        };

        $result = $strategy->execute($operation, 'original string', 'test.path');

        $this->assertTrue($result->usedFallback());
        $this->assertSame(2, $result->attempts);
        $this->assertNotNull($result->lastError);
        $this->assertSame(MaskConstants::MASK_STRING, $result->value);
    }

    public function testFailOpenMode(): void
    {
        $strategy = new RetryStrategy(
            maxAttempts: 1,
            failureMode: FailureMode::FAIL_OPEN
        );

        $operation = function (): never {
            throw new RuntimeException('Failure');
        };

        $result = $strategy->execute($operation, 'original', 'test.path');

        $this->assertSame('original', $result->value);
    }

    public function testFailClosedMode(): void
    {
        $strategy = new RetryStrategy(
            maxAttempts: 1,
            failureMode: FailureMode::FAIL_CLOSED
        );

        $operation = function (): never {
            throw new RuntimeException('Failure');
        };

        $result = $strategy->execute($operation, 'original', 'test.path');

        $this->assertSame(MaskConstants::MASK_REDACTED, $result->value);
    }

    public function testCustomFallbackMask(): void
    {
        $strategy = new RetryStrategy(
            maxAttempts: 1,
            fallbackMask: '[CUSTOM_FALLBACK]'
        );

        $operation = function (): never {
            throw new RuntimeException('Failure');
        };

        $result = $strategy->execute($operation, 'original', 'test.path');

        $this->assertSame('[CUSTOM_FALLBACK]', $result->value);
    }

    public function testNonRecoverableErrorSkipsRetry(): void
    {
        $strategy = new RetryStrategy(
            maxAttempts: 5,
            baseDelayMs: 1,
            maxDelayMs: 5
        );

        $attemptCount = 0;
        $operation = function () use (&$attemptCount): never {
            $attemptCount++;
            throw RecursionDepthExceededException::depthExceeded(100, 50, 'path');
        };

        $result = $strategy->execute($operation, 'original', 'test.path');

        $this->assertSame(1, $attemptCount);
        $this->assertTrue($result->usedFallback());
    }

    public function testIsRecoverableWithRecursionDepthException(): void
    {
        $strategy = new RetryStrategy();
        $exception = RecursionDepthExceededException::depthExceeded(100, 50, 'path');

        $this->assertFalse($strategy->isRecoverable($exception));
    }

    public function testIsRecoverableWithPatternCompilationError(): void
    {
        $strategy = new RetryStrategy();
        $exception = MaskingOperationFailedException::regexMaskingFailed(
            '/test/',
            'input',
            'Pattern compilation failed'
        );

        $this->assertFalse($strategy->isRecoverable($exception));
    }

    public function testIsRecoverableWithReDoSError(): void
    {
        $strategy = new RetryStrategy();
        $exception = MaskingOperationFailedException::regexMaskingFailed(
            '/test/',
            'input',
            'Potential ReDoS vulnerability detected'
        );

        $this->assertFalse($strategy->isRecoverable($exception));
    }

    public function testIsRecoverableWithTransientError(): void
    {
        $strategy = new RetryStrategy();
        $exception = new RuntimeException('Temporary failure');

        $this->assertTrue($strategy->isRecoverable($exception));
    }

    public function testGetConfiguration(): void
    {
        $strategy = new RetryStrategy(
            maxAttempts: 5,
            baseDelayMs: 20,
            maxDelayMs: 200,
            failureMode: FailureMode::FAIL_CLOSED,
            fallbackMask: '[CUSTOM]'
        );

        $config = $strategy->getConfiguration();

        $this->assertArrayHasKey('max_attempts', $config);
        $this->assertArrayHasKey('base_delay_ms', $config);
        $this->assertArrayHasKey('max_delay_ms', $config);
        $this->assertArrayHasKey('failure_mode', $config);
        $this->assertArrayHasKey('fallback_mask', $config);

        $this->assertSame(5, $config['max_attempts']);
        $this->assertSame(20, $config['base_delay_ms']);
        $this->assertSame(200, $config['max_delay_ms']);
        $this->assertSame('fail_closed', $config['failure_mode']);
        $this->assertSame('[CUSTOM]', $config['fallback_mask']);
    }

    public function testTypeFallbackForInteger(): void
    {
        $strategy = new RetryStrategy(
            maxAttempts: 1,
            failureMode: FailureMode::FAIL_SAFE
        );

        $operation = function (): never {
            throw new RuntimeException('Failure');
        };

        $result = $strategy->execute($operation, 42, 'test.path');

        $this->assertSame(MaskConstants::MASK_INT, $result->value);
    }

    public function testTypeFallbackForArray(): void
    {
        $strategy = new RetryStrategy(
            maxAttempts: 1,
            failureMode: FailureMode::FAIL_SAFE
        );

        $operation = function (): never {
            throw new RuntimeException('Failure');
        };

        $result = $strategy->execute($operation, ['key' => 'value'], 'test.path');

        $this->assertSame(MaskConstants::MASK_ARRAY, $result->value);
    }

    public function testAuditLoggerCalledOnRetry(): void
    {
        $auditLogs = [];
        $auditLogger = function (
            string $path,
            mixed $original,
            mixed $masked
        ) use (&$auditLogs): void {
            $auditLogs[] = [
                'path' => $path,
                'original' => $original,
                'masked' => $masked
            ];
        };

        $strategy = new RetryStrategy(
            maxAttempts: 2,
            baseDelayMs: 1,
            maxDelayMs: 2
        );

        $operation = function (): never {
            throw new RuntimeException('Failure');
        };

        $strategy->execute($operation, 'original', 'test.path', $auditLogger);

        $this->assertNotEmpty($auditLogs);
        $this->assertStringContainsString('recovery', $auditLogs[0]['path']);
    }
}
