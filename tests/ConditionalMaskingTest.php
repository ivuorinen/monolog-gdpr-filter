<?php

declare(strict_types=1);

namespace Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ivuorinen\MonologGdprFilter\ConditionalRuleFactory;
use Ivuorinen\MonologGdprFilter\Exceptions\RuleExecutionException;
use Ivuorinen\MonologGdprFilter\MaskConstants;
use Monolog\LogRecord;
use Monolog\Level;
use Tests\TestConstants;

/**
 * Test conditional masking functionality based on context and log properties.
 *
 * @api
 */
class ConditionalMaskingTest extends TestCase
{
    use TestHelpers;

    public function testNoConditionalRulesAppliesMasking(): void
    {
        // Test with no conditional rules - masking should always be applied
        $processor = $this->createProcessor([
            TestConstants::PATTERN_EMAIL_TEST => MaskConstants::MASK_EMAIL
        ]);

        $logRecord = $this->createLogRecord(
            'Contact test@example.com',
            ['user_id' => 123]
        );

        $result = $processor($logRecord);

        $this->assertSame('Contact ' . MaskConstants::MASK_EMAIL, $result->message);
    }

    public function testLevelBasedConditionalMasking(): void
    {
        // Create a processor that only masks ERROR and CRITICAL level logs
        $processor = $this->createProcessor(
            [TestConstants::PATTERN_EMAIL_TEST => MaskConstants::MASK_EMAIL],
            [],
            [],
            null,
            100,
            [],
            [
                'error_levels_only' => ConditionalRuleFactory::createLevelBasedRule(['Error', 'Critical'])
            ]
        );

        // Test ERROR level - should be masked
        $errorRecord = $this->createLogRecord(
            'Error with test@example.com',
            [],
            Level::Error,
            'test'
        );

        $result = $processor($errorRecord);
        $this->assertSame('Error with ' . MaskConstants::MASK_EMAIL, $result->message);

        // Test INFO level - should NOT be masked
        $infoRecord = $this->createLogRecord(TestConstants::MESSAGE_INFO_EMAIL);

        $result = $processor($infoRecord);
        $this->assertSame(TestConstants::MESSAGE_INFO_EMAIL, $result->message);

        // Test CRITICAL level - should be masked
        $criticalRecord = $this->createLogRecord(
            'Critical with test@example.com',
            [],
            Level::Critical,
            'test'
        );

        $result = $processor($criticalRecord);
        $this->assertSame('Critical with ' . MaskConstants::MASK_EMAIL, $result->message);
    }

    public function testChannelBasedConditionalMasking(): void
    {
        // Create a processor that only masks logs from 'security' and 'audit' channels
        $processor = $this->createProcessor(
            [TestConstants::PATTERN_EMAIL_TEST => MaskConstants::MASK_EMAIL],
            [],
            [],
            null,
            100,
            [],
            [
                'security_channels_only' => ConditionalRuleFactory::createChannelBasedRule(['security', 'audit'])
            ]
        );

        // Test security channel - should be masked
        $securityRecord = $this->createLogRecord(
            'Security event with test@example.com',
            [],
            Level::Info,
            'security'
        );

        $result = $processor($securityRecord);
        $this->assertSame('Security event with ' . MaskConstants::MASK_EMAIL, $result->message);

        // Test application channel - should NOT be masked
        $appRecord = $this->createLogRecord(
            'App event with test@example.com',
            [],
            Level::Info,
            'application'
        );

        $result = $processor($appRecord);
        $this->assertSame('App event with test@example.com', $result->message);

        // Test audit channel - should be masked
        $auditRecord = $this->createLogRecord(
            'Audit event with test@example.com',
            [],
            Level::Info,
            'audit'
        );

        $result = $processor($auditRecord);
        $this->assertSame('Audit event with ' . MaskConstants::MASK_EMAIL, $result->message);
    }

    public function testContextFieldPresenceRule(): void
    {
        // Create a processor that only masks when 'sensitive_data' field is present in context
        $processor = $this->createProcessor(
            [TestConstants::PATTERN_EMAIL_TEST => MaskConstants::MASK_EMAIL],
            [],
            [],
            null,
            100,
            [],
            [
                'sensitive_data_present' => ConditionalRuleFactory::createContextFieldRule('sensitive_data')
            ]
        );

        // Test with sensitive_data field present - should be masked
        $sensitiveRecord = $this->createLogRecord(
            TestConstants::MESSAGE_WITH_EMAIL,
            ['sensitive_data' => true, 'user_id' => 123]
        );

        $result = $processor($sensitiveRecord);
        $this->assertSame(TestConstants::MESSAGE_WITH_EMAIL_PREFIX . MaskConstants::MASK_EMAIL, $result->message);

        // Test without sensitive_data field - should NOT be masked
        $normalRecord = $this->createLogRecord(
            TestConstants::MESSAGE_WITH_EMAIL,
            ['user_id' => 123]
        );

        $result = $processor($normalRecord);
        $this->assertSame(TestConstants::MESSAGE_WITH_EMAIL, $result->message);
    }

    public function testNestedContextFieldRule(): void
    {
        // Test with nested field path
        $processor = $this->createProcessor(
            [TestConstants::PATTERN_EMAIL_TEST => MaskConstants::MASK_EMAIL],
            [],
            [],
            null,
            100,
            [],
            [
                'user_gdpr_consent' => ConditionalRuleFactory::createContextFieldRule('user.gdpr_consent')
            ]
        );

        // Test with nested field present - should be masked
        $consentRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            TestConstants::MESSAGE_USER_ACTION_EMAIL,
            ['user' => ['id' => 123, 'gdpr_consent' => true]]
        );

        $result = $processor($consentRecord);
        $this->assertSame('User action with ' . MaskConstants::MASK_EMAIL, $result->message);

        // Test without nested field - should NOT be masked
        $noConsentRecord = new LogRecord(
            new DateTimeImmutable(),
            'test',
            Level::Info,
            TestConstants::MESSAGE_USER_ACTION_EMAIL,
            ['user' => ['id' => 123]]
        );

        $result = $processor($noConsentRecord);
        $this->assertSame(TestConstants::MESSAGE_USER_ACTION_EMAIL, $result->message);
    }

    public function testContextValueRule(): void
    {
        // Create a processor that only masks when environment is 'production'
        $processor = $this->createProcessor(
            [TestConstants::PATTERN_EMAIL_TEST => MaskConstants::MASK_EMAIL],
            [],
            [],
            null,
            100,
            [],
            [
                'production_only' => ConditionalRuleFactory::createContextValueRule('env', 'production')
            ]
        );

        // Test with production environment - should be masked
        $prodRecord = $this->createLogRecord(
            TestConstants::MESSAGE_WITH_EMAIL,
            ['env' => 'production', 'user_id' => 123]
        );

        $result = $processor($prodRecord);
        $this->assertSame(TestConstants::MESSAGE_WITH_EMAIL_PREFIX . MaskConstants::MASK_EMAIL, $result->message);

        // Test with development environment - should NOT be masked
        $devRecord = $this->createLogRecord(
            TestConstants::MESSAGE_WITH_EMAIL,
            ['env' => 'development', 'user_id' => 123]
        );

        $result = $processor($devRecord);
        $this->assertSame(TestConstants::MESSAGE_WITH_EMAIL, $result->message);
    }

    public function testMultipleConditionalRules(): void
    {
        // Create a processor with multiple rules - ALL must be true for masking to occur
        $processor = $this->createProcessor(
            [TestConstants::PATTERN_EMAIL_TEST => MaskConstants::MASK_EMAIL],
            [],
            [],
            null,
            100,
            [],
            [
                'error_level' => ConditionalRuleFactory::createLevelBasedRule(['Error', 'Critical']),
                'production_env' => ConditionalRuleFactory::createContextValueRule('env', 'production'),
                'security_channel' => ConditionalRuleFactory::createChannelBasedRule(['security'])
            ]
        );

        // Test with all conditions met - should be masked
        $allConditionsRecord = $this->createLogRecord(
            TestConstants::MESSAGE_SECURITY_ERROR_EMAIL,
            ['env' => 'production'],
            Level::Error,
            'security'
        );

        $result = $processor($allConditionsRecord);
        $this->assertSame('Security error with ' . MaskConstants::MASK_EMAIL, $result->message);

        // Test with missing level condition - should NOT be masked
        $wrongLevelRecord = $this->createLogRecord(
            'Security info with test@example.com',
            ['env' => 'production'],
            Level::Info,
            'security'
        );

        $result = $processor($wrongLevelRecord);
        $this->assertSame('Security info with test@example.com', $result->message);

        // Test with missing environment condition - should NOT be masked
        $wrongEnvRecord = $this->createLogRecord(
            TestConstants::MESSAGE_SECURITY_ERROR_EMAIL,
            ['env' => 'development'],
            Level::Error,
            'security'
        );

        $result = $processor($wrongEnvRecord);
        $this->assertSame(TestConstants::MESSAGE_SECURITY_ERROR_EMAIL, $result->message);

        // Test with missing channel condition - should NOT be masked
        $wrongChannelRecord = $this->createLogRecord(
            'Application error with test@example.com',
            ['env' => 'production'],
            Level::Error,
            'application'
        );

        $result = $processor($wrongChannelRecord);
        $this->assertSame('Application error with test@example.com', $result->message);
    }

    public function testCustomConditionalRule(): void
    {
        // Create a custom rule that masks only logs with user_id > 1000
        $customRule = (
            fn(LogRecord $record): bool => isset($record->context['user_id']) && $record->context['user_id'] > 1000
        );

        $processor = $this->createProcessor(
            [TestConstants::PATTERN_EMAIL_TEST => MaskConstants::MASK_EMAIL],
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
        $highUserRecord = $this->createLogRecord(
            TestConstants::MESSAGE_WITH_EMAIL,
            ['user_id' => 1001]
        );

        $result = $processor($highUserRecord);
        $this->assertSame(TestConstants::MESSAGE_WITH_EMAIL_PREFIX . MaskConstants::MASK_EMAIL, $result->message);

        // Test with user_id <= 1000 - should NOT be masked
        $lowUserRecord = $this->createLogRecord(
            TestConstants::MESSAGE_WITH_EMAIL,
            ['user_id' => 999]
        );

        $result = $processor($lowUserRecord);
        $this->assertSame(TestConstants::MESSAGE_WITH_EMAIL, $result->message);

        // Test without user_id - should NOT be masked
        $noUserRecord = $this->createLogRecord(TestConstants::MESSAGE_WITH_EMAIL);

        $result = $processor($noUserRecord);
        $this->assertSame(TestConstants::MESSAGE_WITH_EMAIL, $result->message);
    }

    public function testConditionalRuleWithAuditLogger(): void
    {
        $auditLogs = [];
        $auditLogger = function (string $path, mixed $original, mixed $masked) use (&$auditLogs): void {
            $auditLogs[] = ['path' => $path, 'original' => $original, 'masked' => $masked];
        };

        $processor = $this->createProcessor(
            [TestConstants::PATTERN_EMAIL_TEST => MaskConstants::MASK_EMAIL],
            [],
            [],
            $auditLogger,
            100,
            [],
            [
                'error_level' => ConditionalRuleFactory::createLevelBasedRule(['Error'])
            ]
        );

        // Test INFO level - should skip masking and log the skip
        $infoRecord = $this->createLogRecord(TestConstants::MESSAGE_INFO_EMAIL);

        $result = $processor($infoRecord);

        $this->assertSame(TestConstants::MESSAGE_INFO_EMAIL, $result->message);
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
        $faultyRule =
            /**
             * @return never
             */
            function (): void {
                throw RuleExecutionException::forConditionalRule('test_error_rule', 'Rule error');
            };

        $processor = $this->createProcessor(
            [TestConstants::PATTERN_EMAIL_TEST => MaskConstants::MASK_EMAIL],
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
        $testRecord = $this->createLogRecord(TestConstants::MESSAGE_WITH_EMAIL);

        $result = $processor($testRecord);

        // Should be masked because the exception was caught and processing continued
        $this->assertSame(TestConstants::MESSAGE_WITH_EMAIL_PREFIX . MaskConstants::MASK_EMAIL, $result->message);
        $this->assertCount(1, $auditLogs);
        $this->assertSame('conditional_error', $auditLogs[0]['path']);
        $this->assertEquals('faulty_rule', $auditLogs[0]['original']);
        $this->assertStringContainsString('Rule error', (string) $auditLogs[0]['masked']);
    }

    public function testConditionalMaskingWithContextMasking(): void
    {
        // Test that conditional rules work with context field masking too
        $processor = $this->createProcessor(
            [],
            ['email' => 'email@masked.com'],
            [],
            null,
            100,
            [],
            [
                'production_only' => ConditionalRuleFactory::createContextValueRule('env', 'production')
            ]
        );

        // Test with production environment - context should be masked
        $prodRecord = $this->createLogRecord(
            'User login',
            ['env' => 'production', 'email' => TestConstants::EMAIL_USER]
        );

        $result = $processor($prodRecord);
        $this->assertEquals('email@masked.com', $result->context['email']);

        // Test with development environment - context should NOT be masked
        $devRecord = $this->createLogRecord(
            'User login',
            ['env' => 'development', 'email' => TestConstants::EMAIL_USER]
        );

        $result = $processor($devRecord);
        $this->assertEquals(TestConstants::EMAIL_USER, $result->context['email']);
    }

    public function testConditionalMaskingWithDataTypeMasking(): void
    {
        // Test that conditional rules work with data type masking
        $processor = $this->createProcessor(
            [],
            [],
            [],
            null,
            100,
            ['integer' => MaskConstants::MASK_INT],
            [
                'error_level' => ConditionalRuleFactory::createLevelBasedRule(['Error'])
            ]
        );

        // Test with ERROR level - integers should be masked
        $errorRecord = $this->createLogRecord(
            'Error occurred',
            ['user_id' => 12345, 'count' => 42],
            Level::Error,
            'test'
        );

        $result = $processor($errorRecord);
        $this->assertEquals(MaskConstants::MASK_INT, $result->context['user_id']);
        $this->assertEquals(MaskConstants::MASK_INT, $result->context['count']);

        // Test with INFO level - integers should NOT be masked
        $infoRecord = $this->createLogRecord(
            'Info message',
            ['user_id' => 12345, 'count' => 42]
        );

        $result = $processor($infoRecord);
        $this->assertEquals(12345, $result->context['user_id']);
        $this->assertEquals(42, $result->context['count']);
    }
}
