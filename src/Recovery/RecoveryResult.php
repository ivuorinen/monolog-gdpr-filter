<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Recovery;

use Ivuorinen\MonologGdprFilter\Audit\AuditContext;
use Ivuorinen\MonologGdprFilter\Audit\ErrorContext;

/**
 * Result of a recovery operation.
 *
 * Encapsulates the outcome of attempting an operation with recovery,
 * including whether it succeeded, failed, or used a fallback.
 *
 * @api
 */
final readonly class RecoveryResult
{
    public const OUTCOME_SUCCESS = 'success';
    public const OUTCOME_RECOVERED = 'recovered';
    public const OUTCOME_FALLBACK = 'fallback';
    public const OUTCOME_FAILED = 'failed';

    /**
     * @param mixed $value The resulting value (masked or fallback)
     * @param string $outcome The outcome type
     * @param int $attempts Number of attempts made
     * @param float $totalDurationMs Total time spent including retries
     * @param ErrorContext|null $lastError The last error if any occurred
     */
    public function __construct(
        public mixed $value,
        public string $outcome,
        public int $attempts = 1,
        public float $totalDurationMs = 0.0,
        public ?ErrorContext $lastError = null,
    ) {
    }

    /**
     * Create a success result (first attempt succeeded).
     *
     * @param mixed $value The masked value
     * @param float $durationMs Operation duration
     */
    public static function success(mixed $value, float $durationMs = 0.0): self
    {
        return new self(
            value: $value,
            outcome: self::OUTCOME_SUCCESS,
            attempts: 1,
            totalDurationMs: $durationMs,
        );
    }

    /**
     * Create a recovered result (succeeded after retry).
     *
     * @param mixed $value The masked value
     * @param int $attempts Number of attempts needed
     * @param float $totalDurationMs Total duration including retries
     */
    public static function recovered(
        mixed $value,
        int $attempts,
        float $totalDurationMs = 0.0
    ): self {
        return new self(
            value: $value,
            outcome: self::OUTCOME_RECOVERED,
            attempts: $attempts,
            totalDurationMs: $totalDurationMs,
        );
    }

    /**
     * Create a fallback result (used fallback value after failures).
     *
     * @param mixed $fallbackValue The fallback value used
     * @param int $attempts Number of attempts made before fallback
     * @param ErrorContext $lastError The error that triggered fallback
     * @param float $totalDurationMs Total duration including retries
     */
    public static function fallback(
        mixed $fallbackValue,
        int $attempts,
        ErrorContext $lastError,
        float $totalDurationMs = 0.0
    ): self {
        return new self(
            value: $fallbackValue,
            outcome: self::OUTCOME_FALLBACK,
            attempts: $attempts,
            totalDurationMs: $totalDurationMs,
            lastError: $lastError,
        );
    }

    /**
     * Create a failed result (all recovery attempts exhausted).
     *
     * @param mixed $originalValue The original value (returned as-is)
     * @param int $attempts Number of attempts made
     * @param ErrorContext $error The final error
     * @param float $totalDurationMs Total duration including retries
     */
    public static function failed(
        mixed $originalValue,
        int $attempts,
        ErrorContext $error,
        float $totalDurationMs = 0.0
    ): self {
        return new self(
            value: $originalValue,
            outcome: self::OUTCOME_FAILED,
            attempts: $attempts,
            totalDurationMs: $totalDurationMs,
            lastError: $error,
        );
    }

    /**
     * Check if the operation was successful (including recovery).
     */
    public function isSuccess(): bool
    {
        return $this->outcome === self::OUTCOME_SUCCESS
            || $this->outcome === self::OUTCOME_RECOVERED;
    }

    /**
     * Check if a fallback was used.
     */
    public function usedFallback(): bool
    {
        return $this->outcome === self::OUTCOME_FALLBACK;
    }

    /**
     * Check if the operation completely failed.
     */
    public function isFailed(): bool
    {
        return $this->outcome === self::OUTCOME_FAILED;
    }

    /**
     * Check if retry was needed.
     */
    public function neededRetry(): bool
    {
        return $this->attempts > 1;
    }

    /**
     * Create an AuditContext from this result.
     *
     * @param string $operationType The type of operation performed
     */
    public function toAuditContext(string $operationType): AuditContext
    {
        return match ($this->outcome) {
            self::OUTCOME_SUCCESS => AuditContext::success(
                $operationType,
                $this->totalDurationMs
            ),
            self::OUTCOME_RECOVERED => AuditContext::recovered(
                $operationType,
                $this->attempts,
                $this->totalDurationMs
            ),
            default => AuditContext::failed(
                $operationType,
                $this->lastError ?? ErrorContext::create('unknown', 'Unknown error'),
                $this->attempts,
                $this->totalDurationMs,
                ['outcome' => $this->outcome]
            ),
        };
    }

    /**
     * Convert to array for logging/debugging.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'outcome' => $this->outcome,
            'attempts' => $this->attempts,
            'duration_ms' => round($this->totalDurationMs, 3),
        ];

        if ($this->lastError !== null) {
            $data['error'] = $this->lastError->toArray();
        }

        return $data;
    }
}
