<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Audit;

use Ivuorinen\MonologGdprFilter\RateLimitedAuditLogger;

/**
 * Enhanced audit logger wrapper with structured context support.
 *
 * Wraps a base audit logger (callable or RateLimitedAuditLogger) and
 * provides structured context information for better audit trails.
 *
 * @api
 */
final class StructuredAuditLogger
{
    /** @var callable(string, mixed, mixed): void */
    private $wrappedLogger;

    private bool $includeTimestamp;

    private bool $includeDuration;

    /**
     * @param callable|RateLimitedAuditLogger $auditLogger Base logger to wrap
     * @param bool $includeTimestamp Whether to include timestamp in metadata
     * @param bool $includeDuration Whether to include operation duration
     */
    public function __construct(
        callable|RateLimitedAuditLogger $auditLogger,
        bool $includeTimestamp = true,
        bool $includeDuration = true
    ) {
        $this->wrappedLogger = $auditLogger;
        $this->includeTimestamp = $includeTimestamp;
        $this->includeDuration = $includeDuration;
    }

    /**
     * Create a structured audit logger from a base logger.
     *
     * @param callable|RateLimitedAuditLogger $auditLogger Base logger
     */
    public static function wrap(
        callable|RateLimitedAuditLogger $auditLogger
    ): self {
        return new self($auditLogger);
    }

    /**
     * Log an audit entry with structured context.
     *
     * @param string $path The field path being masked
     * @param mixed $original The original value
     * @param mixed $masked The masked value
     * @param AuditContext|null $context Structured audit context
     */
    public function log(
        string $path,
        mixed $original,
        mixed $masked,
        ?AuditContext $context = null
    ): void {
        $enrichedContext = $context;

        if ($enrichedContext !== null) {
            $metadata = [];

            if ($this->includeTimestamp) {
                $metadata['timestamp'] = time();
                $metadata['timestamp_micro'] = microtime(true);
            }

            if ($this->includeDuration && $enrichedContext->durationMs > 0) {
                $metadata['duration_ms'] = $enrichedContext->durationMs;
            }

            if ($metadata !== []) {
                $enrichedContext = $enrichedContext->withMetadata($metadata);
            }
        }

        // Call the wrapped logger
        // The wrapped logger may be a simple callable (3 params) or enhanced (4 params)
        ($this->wrappedLogger)($path, $original, $masked);

        // If we have context and the wrapped logger doesn't handle it,
        // we store it separately (could be extended to log to a separate channel)
        if ($enrichedContext !== null) {
            $this->logContext($path, $enrichedContext);
        }
    }

    /**
     * Log a success operation.
     *
     * @param string $path The field path
     * @param mixed $original The original value
     * @param mixed $masked The masked value
     * @param string $operationType Type of masking operation
     * @param float $durationMs Duration in milliseconds
     */
    public function logSuccess(
        string $path,
        mixed $original,
        mixed $masked,
        string $operationType,
        float $durationMs = 0.0
    ): void {
        $context = AuditContext::success($operationType, $durationMs, [
            'path' => $path,
        ]);

        $this->log($path, $original, $masked, $context);
    }

    /**
     * Log a failed operation.
     *
     * @param string $path The field path
     * @param mixed $original The original value
     * @param string $operationType Type of masking operation
     * @param ErrorContext $error Error information
     * @param int $attemptNumber Which attempt failed
     */
    public function logFailure(
        string $path,
        mixed $original,
        string $operationType,
        ErrorContext $error,
        int $attemptNumber = 1
    ): void {
        $context = AuditContext::failed(
            $operationType,
            $error,
            $attemptNumber,
            0.0,
            ['path' => $path]
        );

        // For failures, the "masked" value indicates the failure
        $this->log($path, $original, '[MASKING_FAILED]', $context);
    }

    /**
     * Log a recovered operation (after retry/fallback).
     *
     * @param string $path The field path
     * @param mixed $original The original value
     * @param mixed $masked The masked value (from recovery)
     * @param string $operationType Type of masking operation
     * @param int $attemptNumber Final successful attempt number
     * @param float $totalDurationMs Total duration including retries
     */
    public function logRecovery(
        string $path,
        mixed $original,
        mixed $masked,
        string $operationType,
        int $attemptNumber,
        float $totalDurationMs = 0.0
    ): void {
        $context = AuditContext::recovered(
            $operationType,
            $attemptNumber,
            $totalDurationMs,
            ['path' => $path]
        );

        $this->log($path, $original, $masked, $context);
    }

    /**
     * Log a skipped operation.
     *
     * @param string $path The field path
     * @param mixed $value The value that was not masked
     * @param string $operationType Type of masking operation
     * @param string $reason Why masking was skipped
     */
    public function logSkipped(
        string $path,
        mixed $value,
        string $operationType,
        string $reason
    ): void {
        $context = AuditContext::skipped($operationType, $reason, [
            'path' => $path,
        ]);

        $this->log($path, $value, $value, $context);
    }

    /**
     * Start timing an operation.
     *
     * @return float Start time in microseconds
     */
    public function startTimer(): float
    {
        return microtime(true);
    }

    /**
     * Calculate elapsed time since start.
     *
     * @param float $startTime From startTimer()
     * @return float Duration in milliseconds
     */
    public function elapsed(float $startTime): float
    {
        return (microtime(true) - $startTime) * 1000.0;
    }

    /**
     * Log structured context (for extended audit trails).
     *
     * Override this method to send context to a separate logging channel.
     */
    protected function logContext(string $path, AuditContext $context): void
    {
        // Default implementation does nothing extra
        // Subclasses can override to log to a separate channel
        unset($path, $context);
    }

    /**
     * Get the wrapped logger for direct access if needed.
     *
     * @return callable
     */
    public function getWrappedLogger(): callable
    {
        return $this->wrappedLogger;
    }
}
