<?php

declare(strict_types=1);

namespace Tests;

use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\MaskConstants as Mask;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\TestConstants;

#[CoversClass(GdprProcessor::class)]
final class GdprProcessorConditionalRulesTest extends TestCase
{
    use TestHelpers;

    public function testConditionalRuleSkipsMaskingWithAuditLog(): void
    {
        $auditLog = [];
        $auditLogger = function (string $path, mixed $original, mixed $masked) use (&$auditLog): void {
            $auditLog[] = [
                'path' => $path,
                'original' => $original,
                TestConstants::DATA_MASKED => $masked,
            ];
        };

        // Create processor with conditional rule that returns false
        $processor = new GdprProcessor(
            patterns: [TestConstants::PATTERN_SECRET => Mask::MASK_MASKED],
            fieldPaths: [],
            customCallbacks: [],
            auditLogger: $auditLogger,
            maxDepth: 100,
            dataTypeMasks: [],
            conditionalRules: [
                'skip_rule' => fn($record): false => false, // Always skip masking
            ]
        );

        $record = $this->createLogRecord('secret data');
        $result = $processor($record);

        // Message should NOT be masked because rule returned false
        $this->assertStringContainsString('secret', $result->message);

        // Audit log should contain conditional_skip entry
        $this->assertNotEmpty($auditLog);
        $skipEntry = array_filter($auditLog, fn(array $entry): bool => $entry['path'] === 'conditional_skip');
        $this->assertNotEmpty($skipEntry);
    }

    public function testConditionalRuleExceptionIsLoggedAndMaskingContinues(): void
    {
        $auditLog = [];
        $auditLogger = function (string $path, mixed $original, mixed $masked) use (&$auditLog): void {
            $auditLog[] = [
                'path' => $path,
                'original' => $original,
                TestConstants::DATA_MASKED => $masked,
            ];
        };

        // Create processor with conditional rule that throws exception
        $processor = new GdprProcessor(
            patterns: [TestConstants::PATTERN_SECRET => Mask::MASK_MASKED],
            fieldPaths: [],
            customCallbacks: [],
            auditLogger: $auditLogger,
            maxDepth: 100,
            dataTypeMasks: [],
            conditionalRules: [
                'error_rule' => function ($record): never {
                    throw new \RuntimeException('Rule failed');
                },
            ]
        );

        $record = $this->createLogRecord('secret data');
        $result = $processor($record);

        // Message SHOULD be masked because exception causes rule to be skipped
        $this->assertStringContainsString(Mask::MASK_MASKED, $result->message);
        $this->assertStringNotContainsString('secret', $result->message);

        // Audit log should contain conditional_error entry
        $this->assertNotEmpty($auditLog);
        $errorEntry = array_filter($auditLog, fn(array $entry): bool => $entry['path'] === 'conditional_error');
        $this->assertNotEmpty($errorEntry);

        // Check that error message was sanitized
        $errorEntry = reset($errorEntry);
        $this->assertStringContainsString('Rule error:', $errorEntry[TestConstants::DATA_MASKED]);
    }

    public function testMultipleConditionalRulesAllMustPass(): void
    {
        $processor = new GdprProcessor(
            patterns: [TestConstants::PATTERN_SECRET => Mask::MASK_MASKED],
            fieldPaths: [],
            customCallbacks: [],
            auditLogger: null,
            maxDepth: 100,
            dataTypeMasks: [],
            conditionalRules: [
                'rule1' => fn($record): true => true,  // Pass
                'rule2' => fn($record): true => true,  // Pass
                'rule3' => fn($record): false => false, // Fail
            ]
        );

        $record = $this->createLogRecord('secret data');
        $result = $processor($record);

        // Message should NOT be masked because rule3 returned false
        $this->assertStringContainsString('secret', $result->message);
    }

    public function testConditionalRuleExceptionWithSensitiveDataGetsSanitized(): void
    {
        $auditLog = [];
        $auditLogger = function (string $path, mixed $original, mixed $masked) use (&$auditLog): void {
            $auditLog[] = [
                'path' => $path,
                'original' => $original,
                TestConstants::DATA_MASKED => $masked,
            ];
        };

        // Create processor with conditional rule that throws exception with sensitive data
        $processor = new GdprProcessor(
            patterns: ['/data/' => Mask::MASK_MASKED],
            fieldPaths: [],
            customCallbacks: [],
            auditLogger: $auditLogger,
            maxDepth: 100,
            dataTypeMasks: [],
            conditionalRules: [
                'sensitive_error' => function ($record): never {
                    throw new \RuntimeException('Error with password=secret123 in message');
                },
            ]
        );

        $record = $this->createLogRecord('data here');
        $result = $processor($record);

        // Check that error message was sanitized (password should be masked)
        $errorEntry = array_filter($auditLog, fn(array $entry): bool => $entry['path'] === 'conditional_error');
        $this->assertNotEmpty($errorEntry);

        $errorEntry = reset($errorEntry);
        $errorMessage = $errorEntry[TestConstants::DATA_MASKED];

        // Password should be sanitized to ***
        $this->assertStringNotContainsString('secret123', $errorMessage);
        $this->assertStringContainsString(Mask::MASK_GENERIC, $errorMessage);
    }

    public function testRegExpMessageReturnsOriginalWhenResultIsEmpty(): void
    {
        // Test the edge case where masking results in empty string
        $processor = new GdprProcessor(
            patterns: ['/.*/' => ''], // Replace everything with empty
            fieldPaths: [],
        );

        $result = $processor->regExpMessage(TestConstants::MESSAGE_TEST_LOWERCASE);

        // Should return original message when result would be empty
        $this->assertSame(TestConstants::MESSAGE_TEST_LOWERCASE, $result);
    }

    public function testRegExpMessageReturnsOriginalWhenResultIsZero(): void
    {
        // Test the edge case where masking results in '0'
        $processor = new GdprProcessor(
            patterns: [TestConstants::PATTERN_TEST => '0'],
            fieldPaths: [],
        );

        $result = $processor->regExpMessage('test');

        // '0' is treated as empty by the check, so original is returned
        $this->assertSame('test', $result);
    }
}
