<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Recovery;

use Ivuorinen\MonologGdprFilter\Audit\AuditContext;
use Throwable;

/**
 * Interface for implementing recovery strategies when masking operations fail.
 *
 * Recovery strategies define how the processor should handle errors during
 * masking operations, including retry logic and fallback behavior.
 *
 * @api
 */
interface RecoveryStrategy
{
    /**
     * Attempt to execute an operation with recovery logic.
     *
     * @param callable $operation The masking operation to execute
     * @param mixed $originalValue The original value being masked
     * @param string $path The field path
     * @param callable|null $auditLogger Optional audit logger for recovery events
     *
     * @return RecoveryResult The result of the operation (success or fallback)
     */
    public function execute(
        callable $operation,
        mixed $originalValue,
        string $path,
        ?callable $auditLogger = null
    ): RecoveryResult;

    /**
     * Determine if an error is recoverable (worth retrying).
     *
     * @param Throwable $error The error that occurred
     * @return bool True if the operation should be retried
     */
    public function isRecoverable(Throwable $error): bool;

    /**
     * Get the failure mode for this recovery strategy.
     */
    public function getFailureMode(): FailureMode;

    /**
     * Get the maximum number of retry attempts.
     */
    public function getMaxAttempts(): int;

    /**
     * Get configuration information about this strategy.
     *
     * @return array<string, mixed>
     */
    public function getConfiguration(): array;
}
