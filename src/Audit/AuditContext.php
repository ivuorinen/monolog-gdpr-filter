<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Audit;

/**
 * Structured context for audit log entries.
 *
 * Provides a standardized format for tracking masking operations,
 * including timing, retry attempts, and error information.
 *
 * @api
 */
final readonly class AuditContext
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_RECOVERED = 'recovered';
    public const STATUS_SKIPPED = 'skipped';

    public const OP_REGEX = 'regex';
    public const OP_FIELD_PATH = 'field_path';
    public const OP_CALLBACK = 'callback';
    public const OP_DATA_TYPE = 'data_type';
    public const OP_JSON = 'json';
    public const OP_CONDITIONAL = 'conditional';

    /**
     * @param string $operationType Type of masking operation performed
     * @param string $status Operation result status
     * @param string|null $correlationId Unique ID linking related operations
     * @param int $attemptNumber Retry attempt number (1 = first attempt)
     * @param float $durationMs Operation duration in milliseconds
     * @param ErrorContext|null $error Error details if operation failed
     * @param array<string, mixed> $metadata Additional context information
     */
    public function __construct(
        public string $operationType,
        public string $status = self::STATUS_SUCCESS,
        public ?string $correlationId = null,
        public int $attemptNumber = 1,
        public float $durationMs = 0.0,
        public ?ErrorContext $error = null,
        public array $metadata = [],
    ) {
    }

    /**
     * Create a success audit context.
     *
     * @param string $operationType The type of masking operation
     * @param float $durationMs Operation duration in milliseconds
     * @param array<string, mixed> $metadata Additional context
     */
    public static function success(
        string $operationType,
        float $durationMs = 0.0,
        array $metadata = []
    ): self {
        return new self(
            operationType: $operationType,
            status: self::STATUS_SUCCESS,
            durationMs: $durationMs,
            metadata: $metadata,
        );
    }

    /**
     * Create a failed audit context.
     *
     * @param string $operationType The type of masking operation
     * @param ErrorContext $error The error that occurred
     * @param int $attemptNumber Which attempt this was
     * @param float $durationMs Operation duration in milliseconds
     * @param array<string, mixed> $metadata Additional context
     */
    public static function failed(
        string $operationType,
        ErrorContext $error,
        int $attemptNumber = 1,
        float $durationMs = 0.0,
        array $metadata = []
    ): self {
        return new self(
            operationType: $operationType,
            status: self::STATUS_FAILED,
            attemptNumber: $attemptNumber,
            durationMs: $durationMs,
            error: $error,
            metadata: $metadata,
        );
    }

    /**
     * Create a recovered audit context (after retry/fallback).
     *
     * @param string $operationType The type of masking operation
     * @param int $attemptNumber Final attempt number before success
     * @param float $durationMs Total duration including retries
     * @param array<string, mixed> $metadata Additional context
     */
    public static function recovered(
        string $operationType,
        int $attemptNumber,
        float $durationMs = 0.0,
        array $metadata = []
    ): self {
        return new self(
            operationType: $operationType,
            status: self::STATUS_RECOVERED,
            attemptNumber: $attemptNumber,
            durationMs: $durationMs,
            metadata: $metadata,
        );
    }

    /**
     * Create a skipped audit context (conditional rule prevented masking).
     *
     * @param string $operationType The type of masking operation
     * @param string $reason Why the operation was skipped
     * @param array<string, mixed> $metadata Additional context
     */
    public static function skipped(
        string $operationType,
        string $reason,
        array $metadata = []
    ): self {
        return new self(
            operationType: $operationType,
            status: self::STATUS_SKIPPED,
            metadata: array_merge($metadata, ['skip_reason' => $reason]),
        );
    }

    /**
     * Create a copy with a correlation ID.
     */
    public function withCorrelationId(string $correlationId): self
    {
        return new self(
            operationType: $this->operationType,
            status: $this->status,
            correlationId: $correlationId,
            attemptNumber: $this->attemptNumber,
            durationMs: $this->durationMs,
            error: $this->error,
            metadata: $this->metadata,
        );
    }

    /**
     * Create a copy with additional metadata.
     *
     * @param array<string, mixed> $additionalMetadata
     */
    public function withMetadata(array $additionalMetadata): self
    {
        return new self(
            operationType: $this->operationType,
            status: $this->status,
            correlationId: $this->correlationId,
            attemptNumber: $this->attemptNumber,
            durationMs: $this->durationMs,
            error: $this->error,
            metadata: array_merge($this->metadata, $additionalMetadata),
        );
    }

    /**
     * Check if the operation succeeded.
     */
    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS
            || $this->status === self::STATUS_RECOVERED;
    }

    /**
     * Convert to array for serialization/logging.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'operation_type' => $this->operationType,
            'status' => $this->status,
            'attempt_number' => $this->attemptNumber,
            'duration_ms' => round($this->durationMs, 3),
        ];

        if ($this->correlationId !== null) {
            $data['correlation_id'] = $this->correlationId;
        }

        if ($this->error !== null) {
            $data['error'] = $this->error->toArray();
        }

        if ($this->metadata !== []) {
            $data['metadata'] = $this->metadata;
        }

        return $data;
    }

    /**
     * Generate a unique correlation ID for tracking related operations.
     */
    public static function generateCorrelationId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
