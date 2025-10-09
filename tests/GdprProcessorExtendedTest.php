<?php

declare(strict_types=1);

namespace Tests;

use Tests\TestConstants;
use Ivuorinen\MonologGdprFilter\RateLimitedAuditLogger;
use Ivuorinen\MonologGdprFilter\MaskConstants;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Monolog\Level;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GdprProcessor::class)]
final class GdprProcessorExtendedTest extends TestCase
{
    use TestHelpers;

    #[Test]
    public function createRateLimitedAuditLoggerCreatesRateLimiter(): void
    {
        $logs = [];
        $baseLogger = $this->createAuditLogger($logs);

        $rateLimitedLogger = GdprProcessor::createRateLimitedAuditLogger($baseLogger, 'testing');

        $this->assertInstanceOf(RateLimitedAuditLogger::class, $rateLimitedLogger);
    }

    #[Test]
    public function createRateLimitedAuditLoggerUsesDefaultProfile(): void
    {
        $baseLogger = fn($path, $original, $masked): null => null;
        $rateLimitedLogger = GdprProcessor::createRateLimitedAuditLogger($baseLogger);

        $this->assertInstanceOf(RateLimitedAuditLogger::class, $rateLimitedLogger);
    }

    #[Test]
    public function createArrayAuditLoggerWithoutRateLimitingReturnsClosure(): void
    {
        $logs = [];
        $logger = GdprProcessor::createArrayAuditLogger($logs, rateLimited: false);

        $this->assertInstanceOf(\Closure::class, $logger);

        // Test that it logs
        $logger('test.path', 'original', 'masked');

        $this->assertCount(1, $logs);
        $this->assertSame('test.path', $logs[0]['path']);
        $this->assertSame('original', $logs[0]['original']);
        $this->assertSame('masked', $logs[0]['masked']);
        $this->assertArrayHasKey('timestamp', $logs[0]);
    }

    #[Test]
    public function createArrayAuditLoggerWithRateLimitingReturnsRateLimiter(): void
    {
        $logs = [];
        $logger = GdprProcessor::createArrayAuditLogger($logs, rateLimited: true);

        $this->assertInstanceOf(RateLimitedAuditLogger::class, $logger);
    }

    #[Test]
    public function setAuditLoggerChangesAuditLogger(): void
    {
        $logs1 = [];
        $logger1 = $this->createAuditLogger($logs1);

        $processor = $this->createProcessor(
            patterns: ['/\d+/' => MaskConstants::MASK_GENERIC],
            fieldPaths: ['id' => MaskConstants::MASK_GENERIC],
            auditLogger: $logger1
        );

        $record = $this->createLogRecord(
            'User ID: 12345',
            ['id' => 12345]
        );

        $result1 = $processor($record);

        // Verify first logger captured the masking
        $this->assertNotEmpty($logs1);
        $this->assertSame(MaskConstants::MASK_GENERIC, $result1->context['id']);
        $countLogs1 = count($logs1);

        // Change audit logger
        $logs2 = [];
        $logger2 = $this->createAuditLogger($logs2);

        $processor->setAuditLogger($logger2);

        $result2 = $processor($record);

        // Verify masking still works with new logger
        $this->assertSame(MaskConstants::MASK_GENERIC, $result2->context['id']);
        // Verify second logger was used (logs2 should have entries)
        $this->assertNotEmpty($logs2);
        // Verify first logger was not used anymore (logs1 count should not increase)
        $this->assertCount($countLogs1, $logs1);
    }

    #[Test]
    public function setAuditLoggerAcceptsNull(): void
    {
        $logs = [];
        $logger = $this->createAuditLogger($logs);

        $processor = $this->createProcessor(
            patterns: ['/\d+/' => MaskConstants::MASK_GENERIC],
            auditLogger: $logger
        );

        $processor->setAuditLogger(null);

        $record = $this->createLogRecord('User ID: 12345');

        $processor($record);

        // With null logger, no logs should be added
        $this->assertEmpty($logs);
    }

    #[Test]
    public function conditionalRulesSkipMaskingWhenRuleReturnsFalse(): void
    {
        $processor = $this->createProcessor(
            patterns: ['/\d+/' => MaskConstants::MASK_GENERIC],
            conditionalRules: [
                'skip_debug' => fn($record): bool => $record->level !== Level::Debug,
            ]
        );

        $debugRecord = $this->createLogRecord(
            'Debug with SSN: ' . self::TEST_US_SSN,
            [],
            Level::Debug
        );

        $result = $processor($debugRecord);
        $this->assertStringContainsString(self::TEST_US_SSN, $result->message);

        $infoRecord = $this->createLogRecord(
            'Info with SSN: ' . self::TEST_US_SSN
        );

        $result = $processor($infoRecord);
        $this->assertStringNotContainsString(self::TEST_US_SSN, $result->message);
    }

    #[Test]
    public function conditionalRulesLogWhenSkipping(): void
    {
        $logs = [];
        $auditLogger = $this->createAuditLogger($logs);

        $processor = $this->createProcessor(
            patterns: ['/\d+/' => MaskConstants::MASK_GENERIC],
            auditLogger: $auditLogger,
            conditionalRules: [
                'skip_debug' => fn($record): false => false,
            ]
        );

        $record = $this->createLogRecord('Test message');

        $processor($record);

        $conditionalLogs = array_filter($logs, fn(array $log): bool => $log['path'] === 'conditional_skip');
        $this->assertNotEmpty($conditionalLogs);
    }

    #[Test]
    public function conditionalRulesHandleExceptionsGracefully(): void
    {
        $logs = [];
        $auditLogger = $this->createAuditLogger($logs);

        $processor = $this->createProcessor(
            patterns: ['/\d+/' => MaskConstants::MASK_GENERIC],
            auditLogger: $auditLogger,
            conditionalRules: [
                'throws_exception' => function ($record): never {
                    throw new \RuntimeException('Rule failed');
                },
            ]
        );

        $record = $this->createLogRecord('User ID: 12345');

        $result = $processor($record);

        // Should still mask despite exception
        $this->assertStringNotContainsString('12345', $result->message);

        // Should log the error
        $errorLogs = array_filter($logs, fn(array $log): bool => $log['path'] === 'conditional_error');
        $this->assertNotEmpty($errorLogs);
    }

    #[Test]
    public function regExpMessageHandlesEmptyString(): void
    {
        $processor = new GdprProcessor(patterns: ['/\d+/' => MaskConstants::MASK_GENERIC]);

        $result = $processor->regExpMessage('');

        $this->assertSame('', $result);
    }

    #[Test]
    public function regExpMessagePreservesOriginalWhenMaskingResultsInEmpty(): void
    {
        $processor = new GdprProcessor(patterns: ['/.+/' => '']);

        $original = 'test message';
        $result = $processor->regExpMessage($original);

        // Should return original since masking would produce empty string
        $this->assertSame($original, $result);
    }

    #[Test]
    public function maskMessageHandlesComplexNestedJson(): void
    {
        $processor = new GdprProcessor(patterns: [
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => MaskConstants::MASK_EMAIL_PATTERN,
        ]);

        $message = json_encode([
            'user' => [
                'email' => TestConstants::EMAIL_TEST,
                'profile' => [
                    'contact_email' => 'contact@example.com',
                ],
            ],
        ]);

        $result = $processor->maskMessage($message);

        $this->assertStringNotContainsString(TestConstants::EMAIL_TEST, $result);
        $this->assertStringNotContainsString('contact@example.com', $result);
        $this->assertStringContainsString(MaskConstants::MASK_EMAIL_PATTERN, $result);
    }

    #[Test]
    public function recursiveMaskHandlesLargeArrays(): void
    {
        $processor = new GdprProcessor(patterns: ['/\d+/' => MaskConstants::MASK_GENERIC]);

        // Create array larger than chunk size (1000 items)
        $largeArray = [];
        for ($i = 0; $i < 1500; $i++) {
            $largeArray["key_$i"] = "value_$i";
        }

        $result = $processor->recursiveMask($largeArray);

        $this->assertIsArray($result);
        $this->assertCount(1500, $result);
        $this->assertStringContainsString(MaskConstants::MASK_GENERIC, $result['key_0']);
    }

    #[Test]
    public function customCallbacksAreApplied(): void
    {
        $processor = $this->createProcessor(
            patterns: [],
            fieldPaths: [],
            customCallbacks: [
                'user.id' => fn($value): string => 'USER_' . $value,
            ]
        );

        $record = $this->createLogRecord(
            'Test',
            ['user' => ['id' => 123]]
        );

        $result = $processor($record);

        $this->assertSame('USER_123', $result->context['user']['id']);
    }

    #[Test]
    public function fieldPathsAndCustomCallbacksCombinedWithDataTypeMasking(): void
    {
        $processor = $this->createProcessor(
            patterns: [],
            fieldPaths: ['user.email' => MaskConstants::MASK_EMAIL_PATTERN],
            customCallbacks: ['user.id' => fn($v): string => 'ID_' . $v],
            dataTypeMasks: ['integer' => '0']
        );

        $record = $this->createLogRecord(
            'Test',
            [
                'user' => [
                    'id' => 123,
                    'email' => TestConstants::EMAIL_TEST,
                    'age' => 25,
                ],
            ]
        );

        $result = $processor($record);

        $this->assertSame('ID_123', $result->context['user']['id']);
        $this->assertSame(MaskConstants::MASK_EMAIL_PATTERN, $result->context['user']['email']);
        // DataTypeMasker returns integer 0, not string '0'
        $this->assertSame(0, $result->context['user']['age']);
    }

    #[Test]
    public function invokeWithOnlyPatternsUsesRecursiveMask(): void
    {
        $processor = $this->createProcessor(
            patterns: ['/\d{3}-\d{2}-\d{4}/' => MaskConstants::MASK_SSN_PATTERN]
        );

        $record = $this->createLogRecord(
            'SSN: 123-45-6789',
            [
                'nested' => [
                    'ssn' => '987-65-4321',
                ],
            ]
        );

        $result = $processor($record);

        $this->assertStringContainsString(MaskConstants::MASK_SSN_PATTERN, $result->message);
        $this->assertSame(MaskConstants::MASK_SSN_PATTERN, $result->context['nested']['ssn']);
    }
}
