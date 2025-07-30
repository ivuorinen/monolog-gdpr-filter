<?php

declare(strict_types=1);

namespace Tests;

use DateTimeImmutable;
use RuntimeException;
use PHPUnit\Framework\TestCase;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Monolog\LogRecord;
use Monolog\Level;

/**
 * Test conditional masking functionality based on context and log properties.
 */
class ConditionalMaskingTest extends TestCase
{
    public function testNoConditionalRulesAppliesMasking(): void
    {
        // Test with no conditional rules - masking should always be applied
        $processor = new GdprProcessor([
            '/test@example\.com/' => '***EMAIL***'
        ]);

        $logRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            'Contact test@example.com',
            ['user_id' => 123]
        );

        $result = $processor($logRecord);

        $this->assertSame('Contact ***EMAIL***', $result->message);
    }

    public function testLevelBasedConditionalMasking(): void
    {
        // Create a processor that only masks ERROR and CRITICAL level logs
        $processor = new GdprProcessor(
            ['/test@example\.com/' => '***EMAIL***'],
            [],
            [],
            null,
            100,
            [],
            [
                'error_levels_only' => GdprProcessor::createLevelBasedRule(['Error', 'Critical'])
            ]
        );

        // Test ERROR level - should be masked
        $errorRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Error,
            'Error with test@example.com',
            []
        );

        $result = $processor($errorRecord);
        $this->assertSame('Error with ***EMAIL***', $result->message);

        // Test INFO level - should NOT be masked
        $infoRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            'Info with test@example.com',
            []
        );

        $result = $processor($infoRecord);
        $this->assertSame('Info with test@example.com', $result->message);

        // Test CRITICAL level - should be masked
        $criticalRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Critical,
            'Critical with test@example.com',
            []
        );

        $result = $processor($criticalRecord);
        $this->assertSame('Critical with ***EMAIL***', $result->message);
    }

    public function testChannelBasedConditionalMasking(): void
    {
        // Create a processor that only masks logs from 'security' and 'audit' channels
        $processor = new GdprProcessor(
            ['/test@example\.com/' => '***EMAIL***'],
            [],
            [],
            null,
            100,
            [],
            [
                'security_channels_only' => GdprProcessor::createChannelBasedRule(['security', 'audit'])
            ]
        );

        // Test security channel - should be masked
        $securityRecord = new LogRecord(
            new DateTimeImmutable(),
            'security',
            Level::Info,
            'Security event with test@example.com',
            []
        );

        $result = $processor($securityRecord);
        $this->assertSame('Security event with ***EMAIL***', $result->message);

        // Test application channel - should NOT be masked
        $appRecord = new LogRecord(
            new DateTimeImmutable(),
            'application',
            Level::Info,
            'App event with test@example.com',
            []
        );

        $result = $processor($appRecord);
        $this->assertSame('App event with test@example.com', $result->message);

        // Test audit channel - should be masked
        $auditRecord = new LogRecord(
            new DateTimeImmutable(),
            'audit',
            Level::Info,
            'Audit event with test@example.com',
            []
        );

        $result = $processor($auditRecord);
        $this->assertSame('Audit event with ***EMAIL***', $result->message);
    }

    public function testContextFieldPresenceRule(): void
    {
        // Create a processor that only masks when 'sensitive_data' field is present in context
        $processor = new GdprProcessor(
            ['/test@example\.com/' => '***EMAIL***'],
            [],
            [],
            null,
            100,
            [],
            [
                'sensitive_data_present' => GdprProcessor::createContextFieldRule('sensitive_data')
            ]
        );

        // Test with sensitive_data field present - should be masked
        $sensitiveRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            'Message with test@example.com',
            ['sensitive_data' => true, 'user_id' => 123]
        );

        $result = $processor($sensitiveRecord);
        $this->assertSame('Message with ***EMAIL***', $result->message);

        // Test without sensitive_data field - should NOT be masked
        $normalRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            'Message with test@example.com',
            ['user_id' => 123]
        );

        $result = $processor($normalRecord);
        $this->assertSame('Message with test@example.com', $result->message);
    }

    public function testNestedContextFieldRule(): void
    {
        // Test with nested field path
        $processor = new GdprProcessor(
            ['/test@example\.com/' => '***EMAIL***'],
            [],
            [],
            null,
            100,
            [],
            [
                'user_gdpr_consent' => GdprProcessor::createContextFieldRule('user.gdpr_consent')
            ]
        );

        // Test with nested field present - should be masked
        $consentRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            'User action with test@example.com',
            ['user' => ['id' => 123, 'gdpr_consent' => true]]
        );

        $result = $processor($consentRecord);
        $this->assertSame('User action with ***EMAIL***', $result->message);

        // Test without nested field - should NOT be masked
        $noConsentRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            'User action with test@example.com',
            ['user' => ['id' => 123]]
        );

        $result = $processor($noConsentRecord);
        $this->assertSame('User action with test@example.com', $result->message);
    }

    public function testContextValueRule(): void
    {
        // Create a processor that only masks when environment is 'production'
        $processor = new GdprProcessor(
            ['/test@example\.com/' => '***EMAIL***'],
            [],
            [],
            null,
            100,
            [],
            [
                'production_only' => GdprProcessor::createContextValueRule('env', 'production')
            ]
        );

        // Test with production environment - should be masked
        $prodRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            'Message with test@example.com',
            ['env' => 'production', 'user_id' => 123]
        );

        $result = $processor($prodRecord);
        $this->assertSame('Message with ***EMAIL***', $result->message);

        // Test with development environment - should NOT be masked
        $devRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            'Message with test@example.com',
            ['env' => 'development', 'user_id' => 123]
        );

        $result = $processor($devRecord);
        $this->assertSame('Message with test@example.com', $result->message);
    }

    public function testMultipleConditionalRules(): void
    {
        // Create a processor with multiple rules - ALL must be true for masking to occur
        $processor = new GdprProcessor(
            ['/test@example\.com/' => '***EMAIL***'],
            [],
            [],
            null,
            100,
            [],
            [
                'error_level' => GdprProcessor::createLevelBasedRule(['Error', 'Critical']),
                'production_env' => GdprProcessor::createContextValueRule('env', 'production'),
                'security_channel' => GdprProcessor::createChannelBasedRule(['security'])
            ]
        );

        // Test with all conditions met - should be masked
        $allConditionsRecord = new LogRecord(
            new DateTimeImmutable(),
            'security',
            Level::Error,
            'Security error with test@example.com',
            ['env' => 'production']
        );

        $result = $processor($allConditionsRecord);
        $this->assertSame('Security error with ***EMAIL***', $result->message);

        // Test with missing level condition - should NOT be masked
        $wrongLevelRecord = new LogRecord(
            new DateTimeImmutable(),
            'security',
            Level::Info,
            'Security info with test@example.com',
            ['env' => 'production']
        );

        $result = $processor($wrongLevelRecord);
        $this->assertSame('Security info with test@example.com', $result->message);

        // Test with missing environment condition - should NOT be masked
        $wrongEnvRecord = new LogRecord(
            new DateTimeImmutable(),
            'security',
            Level::Error,
            'Security error with test@example.com',
            ['env' => 'development']
        );

        $result = $processor($wrongEnvRecord);
        $this->assertSame('Security error with test@example.com', $result->message);

        // Test with missing channel condition - should NOT be masked
        $wrongChannelRecord = new LogRecord(
            new DateTimeImmutable(),
            'application',
            Level::Error,
            'Application error with test@example.com',
            ['env' => 'production']
        );

        $result = $processor($wrongChannelRecord);
        $this->assertSame('Application error with test@example.com', $result->message);
    }

    public function testCustomConditionalRule(): void
    {
        // Create a custom rule that masks only logs with user_id > 1000
        $customRule = (fn(LogRecord $record): bool => isset($record->context['user_id']) && $record->context['user_id'] > 1000);

        $processor = new GdprProcessor(
            ['/test@example\.com/' => '***EMAIL***'],
            [],
            [],
            null,
            100,
            [],
            [
                'high_user_id' => $customRule
            ]
        );

        // Test with user_id > 1000 - should be masked
        $highUserRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            'Message with test@example.com',
            ['user_id' => 1001]
        );

        $result = $processor($highUserRecord);
        $this->assertSame('Message with ***EMAIL***', $result->message);

        // Test with user_id <= 1000 - should NOT be masked
        $lowUserRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            'Message with test@example.com',
            ['user_id' => 999]
        );

        $result = $processor($lowUserRecord);
        $this->assertSame('Message with test@example.com', $result->message);

        // Test without user_id - should NOT be masked
        $noUserRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            'Message with test@example.com',
            []
        );

        $result = $processor($noUserRecord);
        $this->assertSame('Message with test@example.com', $result->message);
    }

    public function testConditionalRuleWithAuditLogger(): void
    {
        $auditLogs = [];
        $auditLogger = function (string $path, mixed $original, mixed $masked) use (&$auditLogs): void {
            $auditLogs[] = ['path' => $path, 'original' => $original, 'masked' => $masked];
        };

        $processor = new GdprProcessor(
            ['/test@example\.com/' => '***EMAIL***'],
            [],
            [],
            $auditLogger,
            100,
            [],
            [
                'error_level' => GdprProcessor::createLevelBasedRule(['Error'])
            ]
        );

        // Test INFO level - should skip masking and log the skip
        $infoRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            'Info with test@example.com',
            []
        );

        $result = $processor($infoRecord);

        $this->assertSame('Info with test@example.com', $result->message);
        $this->assertCount(1, $auditLogs);
        $this->assertSame('conditional_skip', $auditLogs[0]['path']);
        $this->assertEquals('error_level', $auditLogs[0]['original']);
        $this->assertEquals('Masking skipped due to conditional rule', $auditLogs[0]['masked']);
    }

    public function testConditionalRuleException(): void
    {
        $auditLogs = [];
        $auditLogger = function (string $path, mixed $original, mixed $masked) use (&$auditLogs): void {
            $auditLogs[] = ['path' => $path, 'original' => $original, 'masked' => $masked];
        };

        // Create a rule that throws an exception
        $faultyRule = function (LogRecord $record): bool {
            throw new RuntimeException('Rule error');
        };

        $processor = new GdprProcessor(
            ['/test@example\.com/' => '***EMAIL***'],
            [],
            [],
            $auditLogger,
            100,
            [],
            [
                'faulty_rule' => $faultyRule
            ]
        );

        // Test that exception is caught and masking continues
        $testRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            'Message with test@example.com',
            []
        );

        $result = $processor($testRecord);

        // Should be masked because the exception was caught and processing continued
        $this->assertSame('Message with ***EMAIL***', $result->message);
        $this->assertCount(1, $auditLogs);
        $this->assertSame('conditional_error', $auditLogs[0]['path']);
        $this->assertEquals('faulty_rule', $auditLogs[0]['original']);
        $this->assertStringContainsString('Rule error', (string) $auditLogs[0]['masked']);
    }

    public function testConditionalMaskingWithContextMasking(): void
    {
        // Test that conditional rules work with context field masking too
        $processor = new GdprProcessor(
            [],
            ['email' => 'email@masked.com'],
            [],
            null,
            100,
            [],
            [
                'production_only' => GdprProcessor::createContextValueRule('env', 'production')
            ]
        );

        // Test with production environment - context should be masked
        $prodRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            'User login',
            ['env' => 'production', 'email' => 'user@example.com']
        );

        $result = $processor($prodRecord);
        $this->assertEquals('email@masked.com', $result->context['email']);

        // Test with development environment - context should NOT be masked
        $devRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            'User login',
            ['env' => 'development', 'email' => 'user@example.com']
        );

        $result = $processor($devRecord);
        $this->assertEquals('user@example.com', $result->context['email']);
    }

    public function testConditionalMaskingWithDataTypeMasking(): void
    {
        // Test that conditional rules work with data type masking
        $processor = new GdprProcessor(
            [],
            [],
            [],
            null,
            100,
            ['integer' => '***INT***'],
            [
                'error_level' => GdprProcessor::createLevelBasedRule(['Error'])
            ]
        );

        // Test with ERROR level - integers should be masked
        $errorRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Error,
            'Error occurred',
            ['user_id' => 12345, 'count' => 42]
        );

        $result = $processor($errorRecord);
        $this->assertEquals('***INT***', $result->context['user_id']);
        $this->assertEquals('***INT***', $result->context['count']);

        // Test with INFO level - integers should NOT be masked
        $infoRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            'Info message',
            ['user_id' => 12345, 'count' => 42]
        );

        $result = $processor($infoRecord);
        $this->assertEquals(12345, $result->context['user_id']);
        $this->assertEquals(42, $result->context['count']);
    }
}
