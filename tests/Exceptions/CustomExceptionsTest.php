<?php

declare(strict_types=1);

namespace Tests\Exceptions;

use Tests\TestConstants;
use Exception;
use PHPUnit\Framework\TestCase;
use Ivuorinen\MonologGdprFilter\Exceptions\GdprProcessorException;
use Ivuorinen\MonologGdprFilter\Exceptions\InvalidRegexPatternException;
use Ivuorinen\MonologGdprFilter\Exceptions\MaskingOperationFailedException;
use Ivuorinen\MonologGdprFilter\Exceptions\AuditLoggingException;
use Ivuorinen\MonologGdprFilter\Exceptions\RecursionDepthExceededException;
use RuntimeException;

/**
 * Tests for custom GDPR processor exceptions.
 * @api
 */
class CustomExceptionsTest extends TestCase
{
    public function testGdprProcessorExceptionBasicUsage(): void
    {
        $exception = new GdprProcessorException(TestConstants::MESSAGE_DEFAULT, 123);

        $this->assertSame(TestConstants::MESSAGE_DEFAULT, $exception->getMessage());
        $this->assertEquals(123, $exception->getCode());
    }

    public function testGdprProcessorExceptionWithContext(): void
    {
        $context = ['field' => TestConstants::CONTEXT_EMAIL, 'value' => TestConstants::EMAIL_TEST];
        $exception = GdprProcessorException::withContext(TestConstants::MESSAGE_BASE, $context);

        $this->assertStringContainsString(TestConstants::MESSAGE_BASE, $exception->getMessage());
        $this->assertStringContainsString('field: "' . TestConstants::CONTEXT_EMAIL . '"', $exception->getMessage());
        $this->assertStringContainsString('value: "' . TestConstants::EMAIL_TEST . '"', $exception->getMessage());
    }

    public function testGdprProcessorExceptionWithEmptyContext(): void
    {
        $exception = GdprProcessorException::withContext(TestConstants::MESSAGE_BASE, []);

        $this->assertSame(TestConstants::MESSAGE_BASE, $exception->getMessage());
    }

    public function testInvalidRegexPatternExceptionForPattern(): void
    {
        $exception = InvalidRegexPatternException::forPattern(
            TestConstants::PATTERN_INVALID_UNCLOSED_BRACKET,
            'Unclosed bracket',
            PREG_INTERNAL_ERROR
        );

        $this->assertStringContainsString(
            "Invalid regex pattern '" . TestConstants::PATTERN_INVALID_UNCLOSED_BRACKET . "'",
            $exception->getMessage()
        );
        $this->assertStringContainsString(
            'Unclosed bracket',
            $exception->getMessage()
        );
        $this->assertStringContainsString(
            'PCRE Error: Internal PCRE error',
            $exception->getMessage()
        );
        $this->assertEquals(
            PREG_INTERNAL_ERROR,
            $exception->getCode()
        );
    }

    public function testInvalidRegexPatternExceptionCompilationFailed(): void
    {
        $exception = InvalidRegexPatternException::compilationFailed('/test[/', PREG_INTERNAL_ERROR);

        $this->assertStringContainsString("Invalid regex pattern '/test[/'", $exception->getMessage());
        $this->assertStringContainsString('Pattern compilation failed', $exception->getMessage());
        $this->assertEquals(PREG_INTERNAL_ERROR, $exception->getCode());
    }

    public function testInvalidRegexPatternExceptionRedosVulnerable(): void
    {
        $exception = InvalidRegexPatternException::redosVulnerable('/(a+)+$/', 'Catastrophic backtracking');

        $this->assertStringContainsString("Invalid regex pattern '/(a+)+$/'", $exception->getMessage());
        $this->assertStringContainsString(
            'Potential ReDoS vulnerability: Catastrophic backtracking',
            $exception->getMessage()
        );
    }

    public function testInvalidRegexPatternExceptionPcreErrorMessages(): void
    {
        $testCases = [
            PREG_INTERNAL_ERROR => 'Internal PCRE error',
            PREG_BACKTRACK_LIMIT_ERROR => 'Backtrack limit exceeded',
            PREG_RECURSION_LIMIT_ERROR => 'Recursion limit exceeded',
            PREG_BAD_UTF8_ERROR => 'Invalid UTF-8 data',
            PREG_BAD_UTF8_OFFSET_ERROR => 'Invalid UTF-8 offset',
            PREG_JIT_STACKLIMIT_ERROR => 'JIT stack limit exceeded',
            99999 => 'Unknown PCRE error (code: 99999)',
        ];

        foreach ($testCases as $errorCode => $expectedMessage) {
            $exception = InvalidRegexPatternException::forPattern(TestConstants::PATTERN_TEST, 'Test', $errorCode);
            $this->assertStringContainsString($expectedMessage, $exception->getMessage());
        }

        // Test case where no error is provided (should not include PCRE error message)
        $noErrorException = InvalidRegexPatternException::forPattern(
            TestConstants::PATTERN_TEST,
            'Test',
            PREG_NO_ERROR
        );
        $this->assertStringNotContainsString('PCRE Error:', $noErrorException->getMessage());
    }

    public function testMaskingOperationFailedExceptionRegexMasking(): void
    {
        $exception = MaskingOperationFailedException::regexMaskingFailed(
            TestConstants::PATTERN_TEST,
            'input string',
            'PCRE error'
        );

        $this->assertStringContainsString(
            "Regex masking failed for pattern '" . TestConstants::PATTERN_TEST . "'",
            $exception->getMessage()
        );
        $this->assertStringContainsString('PCRE error', $exception->getMessage());
        $this->assertStringContainsString('operation_type: "regex_masking"', $exception->getMessage());
        $this->assertStringContainsString('input_length: 12', $exception->getMessage());
    }

    public function testMaskingOperationFailedExceptionFieldPathMasking(): void
    {
        $exception = MaskingOperationFailedException::fieldPathMaskingFailed(
            TestConstants::FIELD_USER_EMAIL,
            TestConstants::EMAIL_TEST,
            'Invalid configuration'
        );

        $expectedMsg = "Field path masking failed for path '" . TestConstants::FIELD_USER_EMAIL . "'";
        $this->assertStringContainsString($expectedMsg, $exception->getMessage());
        $this->assertStringContainsString('Invalid configuration', $exception->getMessage());
        $this->assertStringContainsString('operation_type: "field_path_masking"', $exception->getMessage());
        $this->assertStringContainsString('value_type: "string"', $exception->getMessage());
    }

    public function testMaskingOperationFailedExceptionCustomCallback(): void
    {
        $exception = MaskingOperationFailedException::customCallbackFailed(
            TestConstants::FIELD_USER_NAME,
            [TestConstants::NAME_FIRST, TestConstants::NAME_LAST],
            'Callback threw exception'
        );

        $this->assertStringContainsString(
            "Custom callback masking failed for path '" . TestConstants::FIELD_USER_NAME . "'",
            $exception->getMessage()
        );
        $this->assertStringContainsString('Callback threw exception', $exception->getMessage());
        $this->assertStringContainsString('operation_type: "custom_callback"', $exception->getMessage());
        $this->assertStringContainsString('value_type: "array"', $exception->getMessage());
    }

    public function testMaskingOperationFailedExceptionDataTypeMasking(): void
    {
        $exception = MaskingOperationFailedException::dataTypeMaskingFailed(
            'integer',
            'not an integer',
            'Type mismatch'
        );

        $this->assertStringContainsString("Data type masking failed for type 'integer'", $exception->getMessage());
        $this->assertStringContainsString('Type mismatch', $exception->getMessage());
        $this->assertStringContainsString('expected_type: "integer"', $exception->getMessage());
        $this->assertStringContainsString('actual_type: "string"', $exception->getMessage());
    }

    public function testMaskingOperationFailedExceptionJsonMasking(): void
    {
        $exception = MaskingOperationFailedException::jsonMaskingFailed(
            '{"invalid": json}',
            'Malformed JSON',
            JSON_ERROR_SYNTAX
        );

        $this->assertStringContainsString('JSON masking failed: Malformed JSON', $exception->getMessage());
        $this->assertStringContainsString('operation_type: "json_masking"', $exception->getMessage());
        $this->assertStringContainsString('json_error: ' . JSON_ERROR_SYNTAX, $exception->getMessage());
    }

    public function testMaskingOperationFailedExceptionValuePreview(): void
    {
        // Test long string truncation
        $longString = str_repeat('a', 150);
        $exception = MaskingOperationFailedException::fieldPathMaskingFailed('test.field', $longString, 'Test');
        $this->assertStringContainsString('...', $exception->getMessage());

        // Test object serialization
        $object = (object) ['property' => 'value'];
        $exception = MaskingOperationFailedException::fieldPathMaskingFailed('test.field', $object, 'Test');
        $this->assertStringContainsString('\"property\":\"value\"', $exception->getMessage());
    }

    public function testAuditLoggingExceptionCallbackFailed(): void
    {
        $exception = AuditLoggingException::callbackFailed(
            TestConstants::FIELD_USER_EMAIL,
            'original@example.com',
            'masked@example.com',
            'Logger unavailable'
        );

        $this->assertStringContainsString(
            "Audit logging callback failed for path '" . TestConstants::FIELD_USER_EMAIL . "'",
            $exception->getMessage()
        );
        $this->assertStringContainsString('Logger unavailable', $exception->getMessage());
        $this->assertStringContainsString('audit_type: "callback_failure"', $exception->getMessage());
        $this->assertStringContainsString('original_type: "string"', $exception->getMessage());
        $this->assertStringContainsString('masked_type: "string"', $exception->getMessage());
    }

    public function testAuditLoggingExceptionSerializationFailed(): void
    {
        $exception = AuditLoggingException::serializationFailed(
            'user.data',
            ['circular' => 'reference'],
            'Circular reference detected'
        );

        $this->assertStringContainsString(
            "Audit data serialization failed for path 'user.data'",
            $exception->getMessage()
        );
        $this->assertStringContainsString('Circular reference detected', $exception->getMessage());
        $this->assertStringContainsString('audit_type: "serialization_failure"', $exception->getMessage());
    }

    public function testAuditLoggingExceptionRateLimitingFailed(): void
    {
        $exception = AuditLoggingException::rateLimitingFailed('general_operations', 55, 50, 'Rate limit exceeded');

        $this->assertStringContainsString(
            "Rate-limited audit logging failed for operation 'general_operations'",
            $exception->getMessage()
        );
        $this->assertStringContainsString('Rate limit exceeded', $exception->getMessage());
        $this->assertStringContainsString('current_requests: 55', $exception->getMessage());
        $this->assertStringContainsString('max_requests: 50', $exception->getMessage());
    }

    public function testAuditLoggingExceptionInvalidConfiguration(): void
    {
        $config = ['invalid_key' => 'invalid_value'];
        $exception = AuditLoggingException::invalidConfiguration('Missing required key', $config);

        $this->assertStringContainsString(
            'Invalid audit logger configuration: Missing required key',
            $exception->getMessage()
        );
        $this->assertStringContainsString(
            'audit_type: "configuration_error"',
            $exception->getMessage()
        );
        $this->assertStringContainsString('config:', $exception->getMessage());
    }

    public function testAuditLoggingExceptionLoggerCreationFailed(): void
    {
        $exception = AuditLoggingException::loggerCreationFailed('file_logger', 'Directory not writable');

        $this->assertStringContainsString(
            "Audit logger creation failed for type 'file_logger'",
            $exception->getMessage()
        );
        $this->assertStringContainsString('Directory not writable', $exception->getMessage());
        $this->assertStringContainsString('audit_type: "logger_creation_failure"', $exception->getMessage());
    }

    public function testRecursionDepthExceededExceptionDepthExceeded(): void
    {
        $exception = RecursionDepthExceededException::depthExceeded(105, 100, 'user.deep.nested.field');

        $this->assertStringContainsString(
            'Maximum recursion depth of 100 exceeded (current: 105)',
            $exception->getMessage()
        );
        $this->assertStringContainsString("at path 'user.deep.nested.field'", $exception->getMessage());
        $this->assertStringContainsString('error_type: "depth_exceeded"', $exception->getMessage());
        $this->assertStringContainsString('current_depth: 105', $exception->getMessage());
        $this->assertStringContainsString('max_depth: 100', $exception->getMessage());
    }

    public function testRecursionDepthExceededExceptionCircularReference(): void
    {
        $exception = RecursionDepthExceededException::circularReferenceDetected('user.self_reference', 50, 100);

        $this->assertStringContainsString(
            "Potential circular reference detected at path 'user.self_reference'",
            $exception->getMessage()
        );
        $this->assertStringContainsString('depth: 50/100', $exception->getMessage());
        $this->assertStringContainsString('error_type: "circular_reference"', $exception->getMessage());
    }

    public function testRecursionDepthExceededExceptionExtremeNesting(): void
    {
        $exception = RecursionDepthExceededException::extremeNesting('array', 95, 100, 'data.nested.array');

        $this->assertStringContainsString(
            "Extremely deep nesting detected in array at path 'data.nested.array'",
            $exception->getMessage()
        );
        $this->assertStringContainsString('depth: 95/100', $exception->getMessage());
        $this->assertStringContainsString('error_type: "extreme_nesting"', $exception->getMessage());
        $this->assertStringContainsString('data_type: "array"', $exception->getMessage());
    }

    public function testRecursionDepthExceededExceptionInvalidConfiguration(): void
    {
        $exception = RecursionDepthExceededException::invalidDepthConfiguration(-5, 'Depth cannot be negative');

        $this->assertStringContainsString(
            'Invalid recursion depth configuration: -5 (Depth cannot be negative)',
            $exception->getMessage()
        );
        $this->assertStringContainsString('error_type: "invalid_configuration"', $exception->getMessage());
        $this->assertStringContainsString('invalid_depth: -5', $exception->getMessage());
    }

    public function testRecursionDepthExceededExceptionWithRecommendations(): void
    {
        $recommendations = [
            'Increase maxDepth parameter',
            'Flatten data structure',
            'Use pagination for large datasets'
        ];
        $exception = RecursionDepthExceededException::withRecommendations(100, 100, 'data.path', $recommendations);

        $this->assertStringContainsString('Recursion depth limit reached', $exception->getMessage());
        $this->assertStringContainsString('error_type: "depth_with_recommendations"', $exception->getMessage());
        $this->assertStringContainsString('recommendations:', $exception->getMessage());
        $this->assertStringContainsString('Increase maxDepth parameter', $exception->getMessage());
    }

    public function testExceptionHierarchy(): void
    {
        $baseException = new GdprProcessorException('Base exception');
        $regexException = InvalidRegexPatternException::forPattern(TestConstants::PATTERN_TEST, 'Invalid');
        $maskingException = MaskingOperationFailedException::regexMaskingFailed(
            TestConstants::PATTERN_TEST,
            'input',
            'Failed'
        );
        $auditException = AuditLoggingException::callbackFailed(
            'path',
            'original',
            TestConstants::DATA_MASKED,
            'Failed'
        );
        $depthException = RecursionDepthExceededException::depthExceeded(10, 5, 'path');

        // All should inherit from GdprProcessorException
        $this->assertInstanceOf(GdprProcessorException::class, $baseException);
        $this->assertInstanceOf(GdprProcessorException::class, $regexException);
        $this->assertInstanceOf(GdprProcessorException::class, $maskingException);
        $this->assertInstanceOf(GdprProcessorException::class, $auditException);
        $this->assertInstanceOf(GdprProcessorException::class, $depthException);

        // All should inherit from \Exception
        $this->assertInstanceOf(Exception::class, $baseException);
        $this->assertInstanceOf(Exception::class, $regexException);
        $this->assertInstanceOf(Exception::class, $maskingException);
        $this->assertInstanceOf(Exception::class, $auditException);
        $this->assertInstanceOf(Exception::class, $depthException);
    }

    public function testExceptionChaining(): void
    {
        $originalException = new RuntimeException('Original error');
        $gdprException = InvalidRegexPatternException::forPattern(
            TestConstants::PATTERN_TEST,
            'Invalid pattern',
            0,
            $originalException
        );

        $this->assertSame($originalException, $gdprException->getPrevious());
        $this->assertSame('Original error', $gdprException->getPrevious()->getMessage());
    }
}
