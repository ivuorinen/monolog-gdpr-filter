<?php

declare(strict_types=1);

namespace Tests\Recovery;

use Ivuorinen\MonologGdprFilter\Audit\AuditContext;
use Ivuorinen\MonologGdprFilter\Audit\ErrorContext;
use Ivuorinen\MonologGdprFilter\Recovery\RecoveryResult;
use PHPUnit\Framework\TestCase;
use Tests\TestConstants;

/**
 * Tests for RecoveryResult value object.
 *
 * @api
 */
final class RecoveryResultTest extends TestCase
{
    public function testSuccessCreation(): void
    {
        $result = RecoveryResult::success(TestConstants::MASK_MASKED_BRACKETS, 5.5);

        $this->assertSame(TestConstants::MASK_MASKED_BRACKETS, $result->value);
        $this->assertSame(RecoveryResult::OUTCOME_SUCCESS, $result->outcome);
        $this->assertSame(1, $result->attempts);
        $this->assertSame(5.5, $result->totalDurationMs);
        $this->assertNull($result->lastError);
    }

    public function testRecoveredCreation(): void
    {
        $result = RecoveryResult::recovered(TestConstants::MASK_MASKED_BRACKETS, 3, 25.0);

        $this->assertSame(TestConstants::MASK_MASKED_BRACKETS, $result->value);
        $this->assertSame(RecoveryResult::OUTCOME_RECOVERED, $result->outcome);
        $this->assertSame(3, $result->attempts);
        $this->assertSame(25.0, $result->totalDurationMs);
    }

    public function testFallbackCreation(): void
    {
        $error = ErrorContext::create('TestError', 'Failed to mask');
        $result = RecoveryResult::fallback('[REDACTED]', 3, $error, 50.0);

        $this->assertSame('[REDACTED]', $result->value);
        $this->assertSame(RecoveryResult::OUTCOME_FALLBACK, $result->outcome);
        $this->assertSame(3, $result->attempts);
        $this->assertSame($error, $result->lastError);
        $this->assertSame(50.0, $result->totalDurationMs);
    }

    public function testFailedCreation(): void
    {
        $error = ErrorContext::create('FatalError', 'Cannot recover');
        $result = RecoveryResult::failed('original', 5, $error, 100.0);

        $this->assertSame('original', $result->value);
        $this->assertSame(RecoveryResult::OUTCOME_FAILED, $result->outcome);
        $this->assertSame(5, $result->attempts);
        $this->assertSame($error, $result->lastError);
    }

    public function testIsSuccess(): void
    {
        $success = RecoveryResult::success(TestConstants::MASK_MASKED_BRACKETS);
        $recovered = RecoveryResult::recovered(TestConstants::MASK_MASKED_BRACKETS, 2);
        $error = ErrorContext::create('E', 'M');
        $fallback = RecoveryResult::fallback('[X]', 3, $error);
        $failed = RecoveryResult::failed('orig', 3, $error);

        $this->assertTrue($success->isSuccess());
        $this->assertTrue($recovered->isSuccess());
        $this->assertFalse($fallback->isSuccess());
        $this->assertFalse($failed->isSuccess());
    }

    public function testUsedFallback(): void
    {
        $success = RecoveryResult::success(TestConstants::MASK_MASKED_BRACKETS);
        $error = ErrorContext::create('E', 'M');
        $fallback = RecoveryResult::fallback('[X]', 3, $error);
        $failed = RecoveryResult::failed('orig', 3, $error);

        $this->assertFalse($success->usedFallback());
        $this->assertTrue($fallback->usedFallback());
        $this->assertFalse($failed->usedFallback());
    }

    public function testIsFailed(): void
    {
        $success = RecoveryResult::success(TestConstants::MASK_MASKED_BRACKETS);
        $error = ErrorContext::create('E', 'M');
        $fallback = RecoveryResult::fallback('[X]', 3, $error);
        $failed = RecoveryResult::failed('orig', 3, $error);

        $this->assertFalse($success->isFailed());
        $this->assertFalse($fallback->isFailed());
        $this->assertTrue($failed->isFailed());
    }

    public function testNeededRetry(): void
    {
        $firstTry = RecoveryResult::success(TestConstants::MASK_MASKED_BRACKETS);
        $secondTry = RecoveryResult::recovered(TestConstants::MASK_MASKED_BRACKETS, 2);

        $this->assertFalse($firstTry->neededRetry());
        $this->assertTrue($secondTry->neededRetry());
    }

    public function testToAuditContextSuccess(): void
    {
        $result = RecoveryResult::success(TestConstants::MASK_MASKED_BRACKETS, 10.0);
        $context = $result->toAuditContext(AuditContext::OP_REGEX);

        $this->assertSame(AuditContext::OP_REGEX, $context->operationType);
        $this->assertSame(AuditContext::STATUS_SUCCESS, $context->status);
        $this->assertSame(10.0, $context->durationMs);
    }

    public function testToAuditContextRecovered(): void
    {
        $result = RecoveryResult::recovered(TestConstants::MASK_MASKED_BRACKETS, 3, 30.0);
        $context = $result->toAuditContext(AuditContext::OP_FIELD_PATH);

        $this->assertSame(AuditContext::STATUS_RECOVERED, $context->status);
        $this->assertSame(3, $context->attemptNumber);
    }

    public function testToAuditContextFailed(): void
    {
        $error = ErrorContext::create('Error', 'Message');
        $result = RecoveryResult::failed('orig', 3, $error, 50.0);
        $context = $result->toAuditContext(AuditContext::OP_CALLBACK);

        $this->assertSame(AuditContext::STATUS_FAILED, $context->status);
        $this->assertSame($error, $context->error);
    }

    public function testToArray(): void
    {
        $result = RecoveryResult::success(TestConstants::MASK_MASKED_BRACKETS, 15.123456);
        $array = $result->toArray();

        $this->assertArrayHasKey('outcome', $array);
        $this->assertArrayHasKey('attempts', $array);
        $this->assertArrayHasKey('duration_ms', $array);
        $this->assertArrayNotHasKey('error', $array);

        $this->assertSame('success', $array['outcome']);
        $this->assertSame(1, $array['attempts']);
        $this->assertSame(15.123, $array['duration_ms']);
    }

    public function testToArrayWithError(): void
    {
        $error = ErrorContext::create('TestError', 'Message');
        $result = RecoveryResult::failed('orig', 3, $error);
        $array = $result->toArray();

        $this->assertArrayHasKey('error', $array);
        $this->assertIsArray($array['error']);
    }

    public function testOutcomeConstants(): void
    {
        $this->assertSame('success', RecoveryResult::OUTCOME_SUCCESS);
        $this->assertSame('recovered', RecoveryResult::OUTCOME_RECOVERED);
        $this->assertSame('fallback', RecoveryResult::OUTCOME_FALLBACK);
        $this->assertSame('failed', RecoveryResult::OUTCOME_FAILED);
    }
}
