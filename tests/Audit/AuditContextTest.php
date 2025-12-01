<?php

declare(strict_types=1);

namespace Tests\Audit;

use Ivuorinen\MonologGdprFilter\Audit\AuditContext;
use Ivuorinen\MonologGdprFilter\Audit\ErrorContext;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AuditContext value object.
 *
 * @api
 */
final class AuditContextTest extends TestCase
{
    public function testSuccessCreation(): void
    {
        $context = AuditContext::success(
            AuditContext::OP_REGEX,
            12.5,
            ['key' => 'value']
        );

        $this->assertSame(AuditContext::OP_REGEX, $context->operationType);
        $this->assertSame(AuditContext::STATUS_SUCCESS, $context->status);
        $this->assertSame(1, $context->attemptNumber);
        $this->assertSame(12.5, $context->durationMs);
        $this->assertNull($context->error);
        $this->assertSame(['key' => 'value'], $context->metadata);
    }

    public function testFailedCreation(): void
    {
        $error = ErrorContext::create('TestError', 'Something went wrong');
        $context = AuditContext::failed(
            AuditContext::OP_FIELD_PATH,
            $error,
            3,
            50.0,
            ['retry' => true]
        );

        $this->assertSame(AuditContext::OP_FIELD_PATH, $context->operationType);
        $this->assertSame(AuditContext::STATUS_FAILED, $context->status);
        $this->assertSame(3, $context->attemptNumber);
        $this->assertSame(50.0, $context->durationMs);
        $this->assertSame($error, $context->error);
        $this->assertArrayHasKey('retry', $context->metadata);
    }

    public function testRecoveredCreation(): void
    {
        $context = AuditContext::recovered(
            AuditContext::OP_CALLBACK,
            2,
            25.0
        );

        $this->assertSame(AuditContext::OP_CALLBACK, $context->operationType);
        $this->assertSame(AuditContext::STATUS_RECOVERED, $context->status);
        $this->assertSame(2, $context->attemptNumber);
        $this->assertSame(25.0, $context->durationMs);
    }

    public function testSkippedCreation(): void
    {
        $context = AuditContext::skipped(
            AuditContext::OP_CONDITIONAL,
            'Condition not met'
        );

        $this->assertSame(AuditContext::OP_CONDITIONAL, $context->operationType);
        $this->assertSame(AuditContext::STATUS_SKIPPED, $context->status);
        $this->assertArrayHasKey('skip_reason', $context->metadata);
        $this->assertSame('Condition not met', $context->metadata['skip_reason']);
    }

    public function testWithCorrelationId(): void
    {
        $context = AuditContext::success(AuditContext::OP_REGEX);
        $this->assertNull($context->correlationId);

        $withId = $context->withCorrelationId('abc123');

        $this->assertNull($context->correlationId);
        $this->assertSame('abc123', $withId->correlationId);
        $this->assertSame($context->operationType, $withId->operationType);
        $this->assertSame($context->status, $withId->status);
    }

    public function testWithMetadata(): void
    {
        $context = AuditContext::success(
            AuditContext::OP_REGEX,
            0.0,
            ['original' => 'value']
        );

        $withMeta = $context->withMetadata(['added' => 'new']);

        $this->assertArrayHasKey('original', $withMeta->metadata);
        $this->assertArrayHasKey('added', $withMeta->metadata);
        $this->assertSame('value', $withMeta->metadata['original']);
        $this->assertSame('new', $withMeta->metadata['added']);
    }

    public function testIsSuccess(): void
    {
        $success = AuditContext::success(AuditContext::OP_REGEX);
        $recovered = AuditContext::recovered(AuditContext::OP_REGEX, 2);
        $error = ErrorContext::create('Error', 'msg');
        $failed = AuditContext::failed(AuditContext::OP_REGEX, $error);
        $skipped = AuditContext::skipped(AuditContext::OP_REGEX, 'reason');

        $this->assertTrue($success->isSuccess());
        $this->assertTrue($recovered->isSuccess());
        $this->assertFalse($failed->isSuccess());
        $this->assertFalse($skipped->isSuccess());
    }

    public function testToArray(): void
    {
        $context = AuditContext::success(
            AuditContext::OP_REGEX,
            15.123456,
            ['key' => 'value']
        );

        $array = $context->toArray();

        $this->assertArrayHasKey('operation_type', $array);
        $this->assertArrayHasKey('status', $array);
        $this->assertArrayHasKey('attempt_number', $array);
        $this->assertArrayHasKey('duration_ms', $array);
        $this->assertArrayHasKey('metadata', $array);
        $this->assertSame(15.123, $array['duration_ms']);
    }

    public function testToArrayWithError(): void
    {
        $error = ErrorContext::create('TestError', 'Message');
        $context = AuditContext::failed(AuditContext::OP_REGEX, $error);

        $array = $context->toArray();

        $this->assertArrayHasKey('error', $array);
        $this->assertIsArray($array['error']);
    }

    public function testToArrayWithCorrelationId(): void
    {
        $context = AuditContext::success(AuditContext::OP_REGEX)
            ->withCorrelationId('test-id');

        $array = $context->toArray();

        $this->assertArrayHasKey('correlation_id', $array);
        $this->assertSame('test-id', $array['correlation_id']);
    }

    public function testGenerateCorrelationId(): void
    {
        $id1 = AuditContext::generateCorrelationId();
        $id2 = AuditContext::generateCorrelationId();

        $this->assertIsString($id1);
        $this->assertSame(16, strlen($id1));
        $this->assertNotSame($id1, $id2);
    }

    public function testOperationTypeConstants(): void
    {
        $this->assertSame('regex', AuditContext::OP_REGEX);
        $this->assertSame('field_path', AuditContext::OP_FIELD_PATH);
        $this->assertSame('callback', AuditContext::OP_CALLBACK);
        $this->assertSame('data_type', AuditContext::OP_DATA_TYPE);
        $this->assertSame('json', AuditContext::OP_JSON);
        $this->assertSame('conditional', AuditContext::OP_CONDITIONAL);
    }

    public function testStatusConstants(): void
    {
        $this->assertSame('success', AuditContext::STATUS_SUCCESS);
        $this->assertSame('failed', AuditContext::STATUS_FAILED);
        $this->assertSame('recovered', AuditContext::STATUS_RECOVERED);
        $this->assertSame('skipped', AuditContext::STATUS_SKIPPED);
    }
}
