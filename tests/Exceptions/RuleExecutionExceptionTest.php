<?php

declare(strict_types=1);

namespace Tests\Exceptions;

use Ivuorinen\MonologGdprFilter\Exceptions\RuleExecutionException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\TestConstants;

#[CoversClass(RuleExecutionException::class)]
final class RuleExecutionExceptionTest extends TestCase
{
    public function testForConditionalRuleCreatesException(): void
    {
        $exception = RuleExecutionException::forConditionalRule(
            'test_rule',
            'Rule validation failed',
            ['field' => 'value']
        );

        $this->assertInstanceOf(RuleExecutionException::class, $exception);
        $this->assertStringContainsString('test_rule', $exception->getMessage());
        $this->assertStringContainsString('Rule validation failed', $exception->getMessage());
        $this->assertStringContainsString('rule_name', $exception->getMessage());
        $this->assertStringContainsString('conditional_rule', $exception->getMessage());
    }

    public function testForConditionalRuleWithoutContext(): void
    {
        $exception = RuleExecutionException::forConditionalRule(
            'simple_rule',
            'Failed'
        );

        $this->assertStringContainsString('simple_rule', $exception->getMessage());
        $this->assertStringContainsString('Failed', $exception->getMessage());
    }

    public function testForConditionalRuleWithPreviousException(): void
    {
        $previous = new RuntimeException('Original error');
        $exception = RuleExecutionException::forConditionalRule(
            'test_rule',
            'Wrapped failure',
            null,
            $previous
        );

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testForCallbackCreatesException(): void
    {
        $exception = RuleExecutionException::forCallback(
            'custom_callback',
            TestConstants::FIELD_USER_EMAIL,
            'Callback threw exception'
        );

        $this->assertInstanceOf(RuleExecutionException::class, $exception);
        $this->assertStringContainsString('custom_callback', $exception->getMessage());
        $this->assertStringContainsString(TestConstants::FIELD_USER_EMAIL, $exception->getMessage());
        $this->assertStringContainsString('Callback threw exception', $exception->getMessage());
        $this->assertStringContainsString('callback_execution', $exception->getMessage());
    }

    public function testForCallbackWithPreviousException(): void
    {
        $previous = new RuntimeException('Callback error');
        $exception = RuleExecutionException::forCallback(
            'test_callback',
            'field.path',
            'Error',
            $previous
        );

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testForTimeoutCreatesException(): void
    {
        $exception = RuleExecutionException::forTimeout(
            'slow_rule',
            1.0,
            1.5
        );

        $this->assertInstanceOf(RuleExecutionException::class, $exception);
        $this->assertStringContainsString('slow_rule', $exception->getMessage());
        $this->assertStringContainsString('1.500', $exception->getMessage());
        $this->assertStringContainsString('1.000', $exception->getMessage());
        $this->assertStringContainsString('timed out', $exception->getMessage());
        $this->assertStringContainsString('timeout', $exception->getMessage());
    }

    public function testForTimeoutWithPreviousException(): void
    {
        $previous = new RuntimeException('Timeout error');
        $exception = RuleExecutionException::forTimeout(
            'rule',
            2.0,
            3.0,
            $previous
        );

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testForEvaluationCreatesException(): void
    {
        $inputData = ['user' => TestConstants::EMAIL_TEST];
        $exception = RuleExecutionException::forEvaluation(
            'validation_rule',
            $inputData,
            'Invalid input format'
        );

        $this->assertInstanceOf(RuleExecutionException::class, $exception);
        $this->assertStringContainsString('validation_rule', $exception->getMessage());
        $this->assertStringContainsString('Invalid input format', $exception->getMessage());
        $this->assertStringContainsString('evaluation', $exception->getMessage());
    }

    public function testForEvaluationWithPreviousException(): void
    {
        $previous = new RuntimeException('Evaluation error');
        $exception = RuleExecutionException::forEvaluation(
            'rule',
            ['data'],
            'Failed',
            $previous
        );

        $this->assertSame($previous, $exception->getPrevious());
    }
}
