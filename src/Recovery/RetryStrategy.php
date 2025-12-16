<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Recovery;

use Ivuorinen\MonologGdprFilter\Audit\ErrorContext;
use Ivuorinen\MonologGdprFilter\Exceptions\MaskingOperationFailedException;
use Ivuorinen\MonologGdprFilter\Exceptions\RecursionDepthExceededException;
use Ivuorinen\MonologGdprFilter\MaskConstants;
use Throwable;

/**
 * Retry strategy with exponential backoff and fallback behavior.
 *
 * Attempts to retry failed masking operations with configurable
 * delays and maximum attempts, then falls back to a safe value.
 *
 * @api
 */
final class RetryStrategy implements RecoveryStrategy
{
    private const DEFAULT_MAX_ATTEMPTS = 3;
    private const DEFAULT_BASE_DELAY_MS = 10;
    private const DEFAULT_MAX_DELAY_MS = 100;

    /**
     * @param int $maxAttempts Maximum number of attempts (1 = no retry)
     * @param int $baseDelayMs Base delay in milliseconds for exponential backoff
     * @param int $maxDelayMs Maximum delay cap in milliseconds
     * @param FailureMode $failureMode How to handle final failure
     * @param string|null $fallbackMask Custom fallback mask value
     */
    public function __construct(
        private readonly int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
        private readonly int $baseDelayMs = self::DEFAULT_BASE_DELAY_MS,
        private readonly int $maxDelayMs = self::DEFAULT_MAX_DELAY_MS,
        private readonly FailureMode $failureMode = FailureMode::FAIL_SAFE,
        private readonly ?string $fallbackMask = null,
    ) {
    }

    /**
     * Create a retry strategy with default settings.
     */
    public static function default(): self
    {
        return new self();
    }

    /**
     * Create a strategy that doesn't retry (immediate fallback).
     */
    public static function noRetry(FailureMode $failureMode = FailureMode::FAIL_SAFE): self
    {
        return new self(maxAttempts: 1, failureMode: $failureMode);
    }

    /**
     * Create a strategy optimized for fast recovery.
     */
    public static function fast(): self
    {
        return new self(
            maxAttempts: 2,
            baseDelayMs: 5,
            maxDelayMs: 20,
            failureMode: FailureMode::FAIL_SAFE
        );
    }

    /**
     * Create a strategy for thorough retry attempts.
     */
    public static function thorough(): self
    {
        return new self(
            maxAttempts: 5,
            baseDelayMs: 20,
            maxDelayMs: 200,
            failureMode: FailureMode::FAIL_CLOSED
        );
    }

    public function execute(
        callable $operation,
        mixed $originalValue,
        string $path,
        ?callable $auditLogger = null
    ): RecoveryResult {
        $startTime = microtime(true);
        $lastError = null;

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                $result = $operation();
                $duration = (microtime(true) - $startTime) * 1000.0;

                if ($attempt === 1) {
                    return RecoveryResult::success($result, $duration);
                }

                return RecoveryResult::recovered($result, $attempt, $duration);
            } catch (Throwable $e) {
                $lastError = ErrorContext::fromThrowable($e);

                // Log the retry attempt
                if ($auditLogger !== null && $attempt < $this->maxAttempts) {
                    $auditLogger(
                        'recovery_retry',
                        ['path' => $path, 'attempt' => $attempt],
                        ['error' => $lastError->message, 'will_retry' => true]
                    );
                }

                // Don't retry if the error is not recoverable
                if (!$this->isRecoverable($e)) {
                    break;
                }

                // Apply delay before retry (except on last attempt)
                if ($attempt < $this->maxAttempts) {
                    $this->delay($attempt);
                }
            }
        }

        // All attempts failed, apply fallback
        $duration = (microtime(true) - $startTime) * 1000.0;
        $fallbackValue = $this->getFallbackValue($originalValue);

        if (!$lastError instanceof ErrorContext) {
            $lastError = ErrorContext::create('unknown', 'No error captured');
        }

        // Log fallback usage
        if ($auditLogger !== null) {
            $auditLogger(
                'recovery_fallback',
                ['path' => $path, 'mode' => $this->failureMode->value],
                ['error' => $lastError->message, 'fallback_applied' => true]
            );
        }

        return RecoveryResult::fallback(
            $fallbackValue,
            $this->maxAttempts,
            $lastError,
            $duration
        );
    }

    public function isRecoverable(Throwable $error): bool
    {
        // These errors indicate permanent failures that won't recover with retry
        $nonRecoverableTypes = [
            RecursionDepthExceededException::class,
        ];

        foreach ($nonRecoverableTypes as $type) {
            if ($error instanceof $type) {
                return false;
            }
        }

        // Some MaskingOperationFailedException errors might be recoverable
        if ($error instanceof MaskingOperationFailedException) {
            $message = $error->getMessage();

            // Pattern compilation errors won't recover
            if (str_contains($message, 'Pattern compilation failed')) {
                return false;
            }

            // ReDoS errors won't recover
            if (str_contains($message, 'ReDoS')) {
                return false;
            }
        }

        // Transient errors like timeouts might recover
        return true;
    }

    public function getFailureMode(): FailureMode
    {
        return $this->failureMode;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function getConfiguration(): array
    {
        return [
            'max_attempts' => $this->maxAttempts,
            'base_delay_ms' => $this->baseDelayMs,
            'max_delay_ms' => $this->maxDelayMs,
            'failure_mode' => $this->failureMode->value,
            'fallback_mask' => $this->fallbackMask ?? '[auto]',
        ];
    }

    /**
     * Get the fallback value based on failure mode.
     */
    private function getFallbackValue(mixed $originalValue): mixed
    {
        if ($this->fallbackMask !== null) {
            return $this->fallbackMask;
        }

        return match ($this->failureMode) {
            FailureMode::FAIL_OPEN => $originalValue,
            FailureMode::FAIL_CLOSED => MaskConstants::MASK_REDACTED,
            FailureMode::FAIL_SAFE => $this->getSafeFallback($originalValue),
        };
    }

    /**
     * Get a safe fallback value that preserves type information.
     */
    private function getSafeFallback(mixed $originalValue): mixed
    {
        return match (gettype($originalValue)) {
            'string' => MaskConstants::MASK_STRING,
            'integer' => MaskConstants::MASK_INT,
            'double' => MaskConstants::MASK_FLOAT,
            'boolean' => MaskConstants::MASK_BOOL,
            'array' => MaskConstants::MASK_ARRAY,
            'object' => MaskConstants::MASK_OBJECT,
            'NULL' => MaskConstants::MASK_NULL,
            default => MaskConstants::MASK_MASKED,
        };
    }

    /**
     * Apply exponential backoff delay.
     *
     * @param int $attempt Current attempt number (1-based)
     */
    private function delay(int $attempt): void
    {
        // Exponential backoff: baseDelay * 2^(attempt-1)
        $delay = $this->baseDelayMs * (2 ** ($attempt - 1));

        // Apply jitter (random 0-25% of delay)
        $jitterMax = (int) floor((float) $delay * 0.25);
        $jitter = random_int(0, $jitterMax);
        $delay += $jitter;

        // Cap at max delay
        $delay = min($delay, $this->maxDelayMs);

        // Convert to microseconds and sleep
        usleep($delay * 1000);
    }
}
