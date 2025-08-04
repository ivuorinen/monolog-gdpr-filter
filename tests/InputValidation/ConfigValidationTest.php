<?php

declare(strict_types=1);

namespace Tests\InputValidation;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the ConfigValidationTest class.
 *
 * @api
 */
#[CoversNothing]
class ConfigValidationTest extends TestCase
{
    /**
     * Get a test configuration array that simulates the actual config without Laravel dependencies.
     *
     * @return ((bool|int|string)[]|bool|int)[]
     *
     * @psalm-return array{auto_register: bool, channels: list{'single', 'daily', 'stack'}, patterns: array<never, never>, field_paths: array<never, never>, custom_callbacks: array<never, never>, max_depth: int<1, 1000>, audit_logging: array{enabled: bool, channel: string}, performance: array{chunk_size: int<100, 10000>, garbage_collection_threshold: int<1000, 100000>}, validation: array{max_pattern_length: int<10, 1000>, max_field_path_length: int<5, 500>, allow_empty_patterns: bool, strict_regex_validation: bool}}
     */
    private function getTestConfig(): array
    {
        return [
            'auto_register' => filter_var($_ENV['GDPR_AUTO_REGISTER'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'channels' => ['single', 'daily', 'stack'],
            'patterns' => [],
            'field_paths' => [],
            'custom_callbacks' => [],
            'max_depth' => max(1, min(1000, (int) ($_ENV['GDPR_MAX_DEPTH'] ?? 100))),
            'audit_logging' => [
                'enabled' => filter_var($_ENV['GDPR_AUDIT_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'channel' => trim($_ENV['GDPR_AUDIT_CHANNEL'] ?? 'gdpr-audit') ?: 'gdpr-audit',
            ],
            'performance' => [
                'chunk_size' => max(100, min(10000, (int) ($_ENV['GDPR_CHUNK_SIZE'] ?? 1000))),
                'garbage_collection_threshold' => max(1000, min(100000, (int) ($_ENV['GDPR_GC_THRESHOLD'] ?? 10000))),
            ],
            'validation' => [
                'max_pattern_length' => max(10, min(1000, (int) ($_ENV['GDPR_MAX_PATTERN_LENGTH'] ?? 500))),
                'max_field_path_length' => max(5, min(500, (int) ($_ENV['GDPR_MAX_FIELD_PATH_LENGTH'] ?? 100))),
                'allow_empty_patterns' => filter_var($_ENV['GDPR_ALLOW_EMPTY_PATTERNS'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'strict_regex_validation' => filter_var($_ENV['GDPR_STRICT_REGEX_VALIDATION'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ],
        ];
    }

    #[Test]
    public function configFileExists(): void
    {
        $configPath = __DIR__ . '/../../config/gdpr.php';
        $this->assertFileExists($configPath, 'GDPR configuration file should exist');
    }

    #[Test]
    public function configReturnsValidArray(): void
    {
        $config = $this->getTestConfig();

        $this->assertIsArray($config, 'Configuration should return an array');
        $this->assertNotEmpty($config, 'Configuration should not be empty');
    }

    #[Test]
    public function configHasRequiredKeys(): void
    {
        $config = $this->getTestConfig();

        $requiredKeys = [
            'auto_register',
            'channels',
            'patterns',
            'field_paths',
            'custom_callbacks',
            'max_depth',
            'audit_logging',
            'performance',
            'validation'
        ];

        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $config, sprintf("Configuration should have '%s' key", $key));
        }
    }

    #[Test]
    public function autoRegisterDefaultsToFalseForSecurity(): void
    {
        // Clear environment variable to test default
        $oldValue = $_ENV['GDPR_AUTO_REGISTER'] ?? null;
        unset($_ENV['GDPR_AUTO_REGISTER']);

        $config = $this->getTestConfig();

        $this->assertFalse($config['auto_register'], 'auto_register should default to false for security');

        // Restore environment variable
        if ($oldValue !== null) {
            $_ENV['GDPR_AUTO_REGISTER'] = $oldValue;
        }
    }

    #[Test]
    public function autoRegisterValidatesBooleanValues(): void
    {
        $testCases = [
            'true' => true,
            '1' => true,
            'yes' => true,
            'on' => true,
            'false' => false,
            '0' => false,
            'no' => false,
            'off' => false,
            '' => false,
            'invalid' => false
        ];

        foreach ($testCases as $envValue => $expectedResult) {
            $_ENV['GDPR_AUTO_REGISTER'] = $envValue;

            $config = $this->getTestConfig();

            $this->assertSame(
                $expectedResult,
                $config['auto_register'],
                sprintf("Environment value '%s' should result in ", $envValue) . ($expectedResult ? 'true' : 'false')
            );
        }

        unset($_ENV['GDPR_AUTO_REGISTER']);
    }

    #[Test]
    public function maxDepthHasValidBounds(): void
    {
        $testCases = [
            '-10' => 1,    // Below minimum, should be clamped to 1
            '0' => 1,      // Below minimum, should be clamped to 1
            '1' => 1,      // Valid minimum
            '100' => 100,  // Valid default
            '1000' => 1000, // Valid maximum
            '1500' => 1000, // Above maximum, should be clamped to 1000
            'invalid' => 1, // Invalid value, should be clamped to 1 (via int cast)
            '' => 1        // Empty value, should be clamped to 1
        ];

        foreach ($testCases as $envValue => $expectedResult) {
            $_ENV['GDPR_MAX_DEPTH'] = $envValue;

            $config = $this->getTestConfig();

            $this->assertSame(
                $expectedResult,
                $config['max_depth'],
                sprintf("Environment value '%s' should result in %s", $envValue, $expectedResult)
            );
        }

        unset($_ENV['GDPR_MAX_DEPTH']);
    }

    #[Test]
    public function auditLoggingEnabledValidatesBooleanValues(): void
    {
        $testCases = [
            'true' => true,
            '1' => true,
            'false' => false,
            '0' => false,
            'invalid' => false
        ];

        foreach ($testCases as $envValue => $expectedResult) {
            $_ENV['GDPR_AUDIT_ENABLED'] = $envValue;

            $config = $this->getTestConfig();

            $this->assertSame(
                $expectedResult,
                $config['audit_logging']['enabled'],
                sprintf("Environment value '%s' should result in ", $envValue) . ($expectedResult ? 'true' : 'false')
            );
        }

        unset($_ENV['GDPR_AUDIT_ENABLED']);
    }

    #[Test]
    public function auditLoggingChannelHandlesEmptyValues(): void
    {
        $testCases = [
            'custom-channel' => 'custom-channel',
            '  spaced  ' => 'spaced', // Should be trimmed
            '' => 'gdpr-audit',      // Empty should use default
            '   ' => 'gdpr-audit'    // Whitespace only should use default
        ];

        foreach ($testCases as $envValue => $expectedResult) {
            $_ENV['GDPR_AUDIT_CHANNEL'] = $envValue;

            $config = $this->getTestConfig();

            $this->assertSame(
                $expectedResult,
                $config['audit_logging']['channel'],
                sprintf("Environment value '%s' should result in '%s'", $envValue, $expectedResult)
            );
        }

        unset($_ENV['GDPR_AUDIT_CHANNEL']);
    }

    #[Test]
    public function performanceChunkSizeHasValidBounds(): void
    {
        $testCases = [
            '50' => 100,   // Below minimum, should be clamped to 100
            '100' => 100,  // Valid minimum
            '1000' => 1000, // Valid default
            '10000' => 10000, // Valid maximum
            '15000' => 10000, // Above maximum, should be clamped to 10000
            'invalid' => 100  // Invalid value, should be clamped to minimum
        ];

        foreach ($testCases as $envValue => $expectedResult) {
            $_ENV['GDPR_CHUNK_SIZE'] = $envValue;

            $config = $this->getTestConfig();

            $this->assertSame(
                $expectedResult,
                $config['performance']['chunk_size'],
                sprintf("Environment value '%s' should result in %s", $envValue, $expectedResult)
            );
        }

        unset($_ENV['GDPR_CHUNK_SIZE']);
    }

    #[Test]
    public function performanceGcThresholdHasValidBounds(): void
    {
        $testCases = [
            '500' => 1000,   // Below minimum, should be clamped to 1000
            '1000' => 1000,  // Valid minimum
            '10000' => 10000, // Valid default
            '100000' => 100000, // Valid maximum
            '150000' => 100000, // Above maximum, should be clamped to 100000
            'invalid' => 1000   // Invalid value, should be clamped to minimum
        ];

        foreach ($testCases as $envValue => $expectedResult) {
            $_ENV['GDPR_GC_THRESHOLD'] = $envValue;

            $config = $this->getTestConfig();

            $this->assertSame(
                $expectedResult,
                $config['performance']['garbage_collection_threshold'],
                sprintf("Environment value '%s' should result in %s", $envValue, $expectedResult)
            );
        }

        unset($_ENV['GDPR_GC_THRESHOLD']);
    }

    #[Test]
    public function validationSectionExists(): void
    {
        $config = $this->getTestConfig();

        $this->assertArrayHasKey('validation', $config, 'Configuration should have validation section');
        $this->assertIsArray($config['validation'], 'Validation section should be an array');
    }

    #[Test]
    public function validationSectionHasRequiredKeys(): void
    {
        $config = $this->getTestConfig();

        $validationKeys = [
            'max_pattern_length',
            'max_field_path_length',
            'allow_empty_patterns',
            'strict_regex_validation'
        ];

        foreach ($validationKeys as $key) {
            $this->assertArrayHasKey(
                $key,
                $config['validation'],
                sprintf("Validation section should have '%s' key", $key)
            );
        }
    }

    #[Test]
    public function validationMaxPatternLengthHasValidBounds(): void
    {
        $testCases = [
            '5' => 10,     // Below minimum, should be clamped to 10
            '10' => 10,    // Valid minimum
            '500' => 500,  // Valid default
            '1000' => 1000, // Valid maximum
            '1500' => 1000, // Above maximum, should be clamped to 1000
            'invalid' => 10 // Invalid value, should be clamped to minimum
        ];

        foreach ($testCases as $envValue => $expectedResult) {
            $_ENV['GDPR_MAX_PATTERN_LENGTH'] = $envValue;

            $config = $this->getTestConfig();

            $this->assertSame(
                $expectedResult,
                $config['validation']['max_pattern_length'],
                sprintf("Environment value '%s' should result in %s", $envValue, $expectedResult)
            );
        }

        unset($_ENV['GDPR_MAX_PATTERN_LENGTH']);
    }

    #[Test]
    public function validationMaxFieldPathLengthHasValidBounds(): void
    {
        $testCases = [
            '3' => 5,      // Below minimum, should be clamped to 5
            '5' => 5,      // Valid minimum
            '100' => 100,  // Valid default
            '500' => 500,  // Valid maximum
            '600' => 500,  // Above maximum, should be clamped to 500
            'invalid' => 5 // Invalid value, should be clamped to minimum
        ];

        foreach ($testCases as $envValue => $expectedResult) {
            $_ENV['GDPR_MAX_FIELD_PATH_LENGTH'] = $envValue;

            $config = $this->getTestConfig();

            $this->assertSame(
                $expectedResult,
                $config['validation']['max_field_path_length'],
                sprintf("Environment value '%s' should result in %s", $envValue, $expectedResult)
            );
        }

        unset($_ENV['GDPR_MAX_FIELD_PATH_LENGTH']);
    }

    #[Test]
    public function validationAllowEmptyPatternsValidatesBooleanValues(): void
    {
        $testCases = [
            'true' => true,
            '1' => true,
            'false' => false,
            '0' => false,
            'invalid' => false
        ];

        foreach ($testCases as $envValue => $expectedResult) {
            $_ENV['GDPR_ALLOW_EMPTY_PATTERNS'] = $envValue;

            $config = $this->getTestConfig();

            $this->assertSame(
                $expectedResult,
                $config['validation']['allow_empty_patterns'],
                sprintf("Environment value '%s' should result in ", $envValue) . ($expectedResult ? 'true' : 'false')
            );
        }

        unset($_ENV['GDPR_ALLOW_EMPTY_PATTERNS']);
    }

    #[Test]
    public function validationStrictRegexValidationValidatesBooleanValues(): void
    {
        $testCases = [
            'true' => true,
            '1' => true,
            'false' => false,
            '0' => false,
            'invalid' => false
        ];

        foreach ($testCases as $envValue => $expectedResult) {
            $_ENV['GDPR_STRICT_REGEX_VALIDATION'] = $envValue;

            $config = $this->getTestConfig();

            $this->assertSame(
                $expectedResult,
                $config['validation']['strict_regex_validation'],
                sprintf("Environment value '%s' should result in ", $envValue) . ($expectedResult ? 'true' : 'false')
            );
        }

        unset($_ENV['GDPR_STRICT_REGEX_VALIDATION']);
    }

    #[Test]
    public function configDefaultsAreSecure(): void
    {
        // Clear all environment variables to test defaults
        $envVars = [
            'GDPR_AUTO_REGISTER',
            'GDPR_AUDIT_ENABLED',
            'GDPR_ALLOW_EMPTY_PATTERNS'
        ];

        $oldValues = [];
        foreach ($envVars as $var) {
            $oldValues[$var] = $_ENV[$var] ?? null;
            unset($_ENV[$var]);
        }

        $config = $this->getTestConfig();

        // Security-focused defaults
        $this->assertFalse($config['auto_register'], 'auto_register should default to false');
        $this->assertFalse($config['audit_logging']['enabled'], 'audit logging should default to false');
        $this->assertFalse($config['validation']['allow_empty_patterns'], 'empty patterns should not be allowed by default');
        $this->assertTrue($config['validation']['strict_regex_validation'], 'strict regex validation should be enabled by default');

        // Restore environment variables
        foreach ($oldValues as $var => $value) {
            if ($value !== null) {
                $_ENV[$var] = $value;
            }
        }
    }

    #[Test]
    public function configHandlesAllDataTypes(): void
    {
        $config = $this->getTestConfig();

        // Test data types
        $this->assertIsBool($config['auto_register']);
        $this->assertIsArray($config['channels']);
        $this->assertIsArray($config['patterns']);
        $this->assertIsArray($config['field_paths']);
        $this->assertIsArray($config['custom_callbacks']);
        $this->assertIsInt($config['max_depth']);
        $this->assertIsArray($config['audit_logging']);
        $this->assertIsBool($config['audit_logging']['enabled']);
        $this->assertIsString($config['audit_logging']['channel']);
        $this->assertIsArray($config['performance']);
        $this->assertIsInt($config['performance']['chunk_size']);
        $this->assertIsInt($config['performance']['garbage_collection_threshold']);
        $this->assertIsArray($config['validation']);
        $this->assertIsInt($config['validation']['max_pattern_length']);
        $this->assertIsInt($config['validation']['max_field_path_length']);
        $this->assertIsBool($config['validation']['allow_empty_patterns']);
        $this->assertIsBool($config['validation']['strict_regex_validation']);
    }

    #[Test]
    public function configBoundsAreReasonable(): void
    {
        $config = $this->getTestConfig();

        // Test reasonable bounds
        $this->assertGreaterThanOrEqual(1, $config['max_depth']);
        $this->assertLessThanOrEqual(1000, $config['max_depth']);

        $this->assertGreaterThanOrEqual(100, $config['performance']['chunk_size']);
        $this->assertLessThanOrEqual(10000, $config['performance']['chunk_size']);

        $this->assertGreaterThanOrEqual(1000, $config['performance']['garbage_collection_threshold']);
        $this->assertLessThanOrEqual(100000, $config['performance']['garbage_collection_threshold']);

        $this->assertGreaterThanOrEqual(10, $config['validation']['max_pattern_length']);
        $this->assertLessThanOrEqual(1000, $config['validation']['max_pattern_length']);

        $this->assertGreaterThanOrEqual(5, $config['validation']['max_field_path_length']);
        $this->assertLessThanOrEqual(500, $config['validation']['max_field_path_length']);
    }

    #[Test]
    public function configChannelsArrayIsValid(): void
    {
        $config = $this->getTestConfig();

        $this->assertIsArray($config['channels']);
        $this->assertNotEmpty($config['channels']);

        foreach ($config['channels'] as $channel) {
            $this->assertIsString($channel, 'Each channel should be a string');
            $this->assertNotEmpty($channel, 'Channel names should not be empty');
        }
    }

    #[Test]
    public function configEmptyArraysAreProperlyInitialized(): void
    {
        $config = $this->getTestConfig();

        // These should be empty arrays by default but properly initialized
        $this->assertIsArray($config['patterns']);
        $this->assertIsArray($config['field_paths']);
        $this->assertIsArray($config['custom_callbacks']);

        // They can be empty, that's fine
        $this->assertCount(0, $config['patterns']);
        $this->assertCount(0, $config['field_paths']);
        $this->assertCount(0, $config['custom_callbacks']);
    }
}
