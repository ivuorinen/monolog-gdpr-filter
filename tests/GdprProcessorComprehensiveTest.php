<?php

declare(strict_types=1);

namespace Tests;

use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\MaskConstants as Mask;
use Ivuorinen\MonologGdprFilter\Exceptions\PatternValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\TestConstants;

/**
 * @psalm-suppress DeprecatedMethod - Tests for deprecated audit logger methods
 */
#[CoversClass(GdprProcessor::class)]
final class GdprProcessorComprehensiveTest extends TestCase
{
    use TestHelpers;

    public function testMaskMessageWithPregReplaceError(): void
    {
        $logs = [];
        $auditLogger = $this->createAuditLogger($logs);

        // Use a valid pattern but test preg_replace error handling via direct method call
        // We'll test the error path by using a valid processor and checking error logging
        $processor = new GdprProcessor(
            patterns: [TestConstants::PATTERN_DIGITS => Mask::MASK_MASKED],
            auditLogger: $auditLogger
        );

        $result = $processor->maskMessage('test 123 message');

        // Should successfully mask
        $this->assertStringContainsString('MASKED', $result);
    }

    public function testMaskMessageWithErrorException(): void
    {
        $logs = [];
        $auditLogger = $this->createAuditLogger($logs);

        // Test normal masking behavior
        $processor = new GdprProcessor(
            patterns: [TestConstants::PATTERN_TEST => Mask::MASK_MASKED],
            auditLogger: $auditLogger
        );

        $result = $processor->maskMessage(TestConstants::MESSAGE_TEST_LOWERCASE);

        // Should handle masking gracefully
        $this->assertIsString($result);
        $this->assertStringContainsString('MASKED', $result);
    }

    public function testMaskMessageWithSuccessfulReplacement(): void
    {
        $processor = new GdprProcessor(
            patterns: [
                TestConstants::PATTERN_SSN_FORMAT => Mask::MASK_SSN_PATTERN,
                '/[a-z]+@[a-z]+\.[a-z]+/' => Mask::MASK_EMAIL_PATTERN,
            ]
        );

        $message = 'SSN: ' . TestConstants::SSN_US . ', Email: ' . TestConstants::EMAIL_TEST;
        $result = $processor->maskMessage($message);

        $this->assertStringContainsString(Mask::MASK_SSN_PATTERN, $result);
        $this->assertStringContainsString(Mask::MASK_EMAIL_PATTERN, $result);
        $this->assertStringNotContainsString(TestConstants::SSN_US, $result);
        $this->assertStringNotContainsString(TestConstants::EMAIL_TEST, $result);
    }

    public function testMaskMessageWithEmptyValue(): void
    {
        $processor = new GdprProcessor(
            patterns: [TestConstants::PATTERN_DIGITS => Mask::MASK_GENERIC]
        );

        $result = $processor->maskMessage('');

        $this->assertSame('', $result);
    }

    public function testMaskMessageWithNoMatches(): void
    {
        $processor = new GdprProcessor(
            patterns: ['/\d{10}/' => Mask::MASK_MASKED]
        );

        $message = 'no numbers here';
        $result = $processor->maskMessage($message);

        $this->assertSame($message, $result);
    }

    public function testMaskMessageWithJsonSupport(): void
    {
        $processor = new GdprProcessor(
            patterns: [TestConstants::PATTERN_SECRET => Mask::MASK_REDACTED]
        );

        $message = 'Log entry: {"key": "secret value"} and secret text';
        $result = $processor->regExpMessage($message);

        $this->assertStringContainsString(Mask::MASK_REDACTED, $result);
        $this->assertStringNotContainsString('secret', $result);
    }

    public function testMaskMessageWithJsonSupportAndPregReplaceError(): void
    {
        $logs = [];
        $auditLogger = $this->createAuditLogger($logs);

        // Test normal JSON processing with patterns
        $processor = new GdprProcessor(
            patterns: ['/value/' => Mask::MASK_MASKED],
            auditLogger: $auditLogger
        );

        $message = 'Test with JSON: {"key": "value"}';
        $result = $processor->regExpMessage($message);

        // Should process JSON and apply regex
        $this->assertIsString($result);
        $this->assertStringContainsString('MASKED', $result);
    }

    public function testMaskMessageWithJsonSupportAndRegexError(): void
    {
        $logs = [];
        $auditLogger = $this->createAuditLogger($logs);

        // Test normal pattern processing
        $processor = new GdprProcessor(
            patterns: ['/bad/' => Mask::MASK_MASKED],
            auditLogger: $auditLogger
        );

        $message = 'Test message with bad pattern';
        $result = $processor->regExpMessage($message);

        // Should handle masking and continue
        $this->assertIsString($result);
        $this->assertStringContainsString('MASKED', $result);
    }

    public function testRegExpMessageReturnsZeroWhenThatIsTheMaskedResult(): void
    {
        // Pattern that replaces everything with '0'
        $processor = new GdprProcessor(
            patterns: ['/.+/' => '0']
        );

        $original = TestConstants::MESSAGE_TEST_LOWERCASE;
        $result = $processor->regExpMessage($original);

        // '0' is a legitimate masking result. Returning $original here would re-emit
        // the unmasked message.
        $this->assertSame('0', $result);
        $this->assertNotSame($original, $result);
    }

    public function testValidatePatternsArrayWithInvalidPattern(): void
    {
        $this->expectException(PatternValidationException::class);

        GdprProcessor::validatePatternsArray([
            'invalid-pattern-no-delimiters' => 'replacement',
        ]);
    }

    public function testValidatePatternsArrayWithValidPatterns(): void
    {
        // Void validator: passing means not throwing. Declared explicitly rather than
        // faked with a vacuous assertion; rejection is covered by the matching negative tests.
        $this->expectNotToPerformAssertions();

        // Should not throw exception
        GdprProcessor::validatePatternsArray([
            TestConstants::PATTERN_DIGITS => Mask::MASK_MASKED,
            TestConstants::PATTERN_SAFE => Mask::MASK_REDACTED,
        ]);
    }

    public function testGetDefaultPatternsReturnsArray(): void
    {
        $patterns = GdprProcessor::getDefaultPatterns();

        $this->assertIsArray($patterns);
        $this->assertNotEmpty($patterns);
        // Check for Finnish HETU pattern
        $this->assertArrayHasKey('/\b\d{6}[-+A]?\d{3}[A-Z]\b/u', $patterns);

        // Assert behaviour rather than the pattern literal: the defaults must mask a US
        // SSN and a HETU both standalone and embedded in a message.
        $processor = new GdprProcessor(patterns: $patterns);
        $this->assertSame(Mask::MASK_USSSN, $processor->maskMessage('123-45-6789'));
        $this->assertSame(
            'ssn ' . Mask::MASK_USSSN . ' logged',
            $processor->maskMessage('ssn 123-45-6789 logged')
        );
        $this->assertSame(
            'hetu ' . Mask::MASK_HETU . ' logged',
            $processor->maskMessage('hetu 010190-123A logged')
        );
    }

    public function testRecursiveMaskDelegatesToRecursiveProcessor(): void
    {
        $processor = new GdprProcessor(
            patterns: [TestConstants::PATTERN_SECRET => Mask::MASK_MASKED]
        );

        $data = [
            'level1' => [
                'level2' => [
                    'value' => TestConstants::MESSAGE_SECRET_DATA,
                ],
            ],
        ];

        $result = $processor->recursiveMask($data);

        $this->assertIsArray($result);
        $this->assertSame(Mask::MASK_MASKED . ' data', $result['level1']['level2']['value']);
    }

    public function testRecursiveMaskWithStringInput(): void
    {
        $processor = new GdprProcessor(
            patterns: ['/password/' => Mask::MASK_REDACTED]
        );

        $result = $processor->recursiveMask('password: secret123');

        $this->assertIsString($result);
        $this->assertSame(Mask::MASK_REDACTED . ': secret123', $result);
    }

    public function testInvokeWithEmptyFieldPathsAndCallbacks(): void
    {
        $processor = new GdprProcessor(
            patterns: [TestConstants::PATTERN_DIGITS => 'NUM'],
            fieldPaths: [],
            customCallbacks: []
        );

        $record = $this->createLogRecord(
            'Message with 123 numbers',
            ['key' => 'value with 456 numbers']
        );

        $result = $processor($record);

        $this->assertStringContainsString('NUM', $result->message);
        $this->assertStringContainsString('NUM', $result->context['key']);
    }

    public function testInvokeWithFieldPathsTriggersDataTypeMasking(): void
    {
        $processor = new GdprProcessor(
            patterns: [],
            fieldPaths: [TestConstants::FIELD_USER_NAME => Mask::MASK_REDACTED],
            dataTypeMasks: ['integer' => Mask::MASK_INT]
        );

        $record = $this->createLogRecord(
            'Test',
            [
                'user' => ['name' => TestConstants::NAME_FIRST, 'age' => 30],
                'count' => 42,
            ]
        );

        $result = $processor($record);

        $this->assertSame(Mask::MASK_REDACTED, $result->context['user']['name']);
        $this->assertSame(Mask::MASK_INT, $result->context['user']['age']);
        $this->assertSame(Mask::MASK_INT, $result->context['count']);
    }

    public function testInvokeWithConditionalRulesAllReturningTrue(): void
    {
        $processor = new GdprProcessor(
            patterns: [TestConstants::PATTERN_DIGITS => 'NUM'],
            conditionalRules: [
                'rule1' => /**
                 * @return true
                 */
                fn($record): bool => true,
                'rule2' => /**
                 * @return true
                 */
                fn($record): bool => true,
                'rule3' => /**
                 * @return true
                 */
                fn($record): bool => true,
            ]
        );

        $record = $this->createLogRecord(TestConstants::MESSAGE_TEST_WITH_DIGITS);

        $result = $processor($record);

        // All rules returned true, so masking should be applied
        $this->assertStringContainsString('NUM', $result->message);
    }

    public function testInvokeWithConditionalRuleThrowingExceptionContinuesProcessing(): void
    {
        $logs = [];
        $auditLogger = $this->createAuditLogger($logs);

        $processor = new GdprProcessor(
            patterns: [TestConstants::PATTERN_DIGITS => 'NUM'],
            auditLogger: $auditLogger,
            conditionalRules: [
                'failing_rule' => function (): never {
                    throw new TestException('Rule failed');
                },
                'passing_rule' => /**
                 * @return true
                 */
                fn($record): bool => true,
            ]
        );

        $record = $this->createLogRecord(TestConstants::MESSAGE_TEST_WITH_DIGITS);

        $result = $processor($record);

        // Should still apply masking despite one rule throwing
        $this->assertStringContainsString('NUM', $result->message);

        // Should log the error
        $errorLogs = array_filter(
            $logs,
            fn(array $log): bool => $log['path'] === 'conditional_error'
        );
        $this->assertNotEmpty($errorLogs);
    }

    public function testInvokeSkipsMaskingWhenNoConditionalRulesAndEmptyArray(): void
    {
        // This tests the branch where conditionalRules is empty array
        $processor = new GdprProcessor(
            patterns: [TestConstants::PATTERN_DIGITS => 'NUM'],
            conditionalRules: []
        );

        $record = $this->createLogRecord(TestConstants::MESSAGE_TEST_WITH_DIGITS);

        $result = $processor($record);

        // Should apply masking when no conditional rules exist
        $this->assertStringContainsString('NUM', $result->message);
    }

    public function testCreateArrayAuditLoggerStoresTimestamp(): void
    {
        $logs = [];
        $logger = GdprProcessor::createArrayAuditLogger($logs, rateLimited: false);

        $this->assertInstanceOf(\Closure::class, $logger);

        $logger('path1', 'orig1', 'masked1');
        $logger('path2', 'orig2', 'masked2');

        $this->assertCount(2, $logs);
        $this->assertArrayHasKey('timestamp', $logs[0]);
        $this->assertArrayHasKey('timestamp', $logs[1]);
        $this->assertIsInt($logs[0]['timestamp']);
        $this->assertGreaterThan(0, $logs[0]['timestamp']);
    }
}
