<?php

declare(strict_types=1);

namespace Tests;

use Ivuorinen\MonologGdprFilter\MaskConstants;
use Ivuorinen\MonologGdprFilter\SecuritySanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test SecuritySanitizer functionality.
 *
 * @api
 */
#[CoversClass(SecuritySanitizer::class)]
class SecuritySanitizerTest extends TestCase
{
    #[Test]
    public function sanitizesPasswordInErrorMessage(): void
    {
        $message = 'Database connection failed with password=mysecretpass123';
        $sanitized = SecuritySanitizer::sanitizeErrorMessage($message);

        $this->assertStringNotContainsString('mysecretpass123', $sanitized);
        $this->assertStringContainsString('password=***', $sanitized);
    }

    #[Test]
    public function sanitizesApiKeyInErrorMessage(): void
    {
        $message = 'API request failed: api_key=sk_live_1234567890abcdef';
        $sanitized = SecuritySanitizer::sanitizeErrorMessage($message);

        $this->assertStringNotContainsString('sk_live_1234567890abcdef', $sanitized);
        $this->assertStringContainsString('api_key=***', $sanitized);
    }

    #[Test]
    public function sanitizesMultipleSensitiveValuesInSameMessage(): void
    {
        $message = 'Failed with password=secret123 and api-key: abc123def456';
        $sanitized = SecuritySanitizer::sanitizeErrorMessage($message);

        $this->assertStringNotContainsString('secret123', $sanitized);
        $this->assertStringNotContainsString('abc123def456', $sanitized);
        $this->assertStringContainsString('password=***', $sanitized);
        $this->assertStringContainsString('api_key=***', $sanitized);
    }

    #[Test]
    public function truncatesLongErrorMessages(): void
    {
        $longMessage = str_repeat('Error occurred with data: ', 50);
        $sanitized = SecuritySanitizer::sanitizeErrorMessage($longMessage);

        $this->assertLessThanOrEqual(550, strlen($sanitized)); // 500 + " (truncated for security)"
        $this->assertStringContainsString(TestConstants::ERROR_TRUNCATED_SECURITY, $sanitized);
    }

    #[Test]
    public function doesNotTruncateShortMessages(): void
    {
        $shortMessage = 'Simple error message';
        $sanitized = SecuritySanitizer::sanitizeErrorMessage($shortMessage);

        $this->assertSame($shortMessage, $sanitized);
        $this->assertStringNotContainsString('truncated', $sanitized);
    }

    #[Test]
    public function handlesEmptyString(): void
    {
        $sanitized = SecuritySanitizer::sanitizeErrorMessage('');

        $this->assertSame('', $sanitized);
    }

    #[Test]
    public function preservesNonSensitiveContent(): void
    {
        $message = 'Connection timeout to server database.example.com on port 3306';
        $sanitized = SecuritySanitizer::sanitizeErrorMessage($message);

        $this->assertSame($message, $sanitized);
    }

    #[Test]
    #[DataProvider('sensitivePatternProvider')]
    public function sanitizesVariousSensitivePatterns(string $input, string $shouldNotContain): void
    {
        $sanitized = SecuritySanitizer::sanitizeErrorMessage($input);

        $this->assertStringNotContainsString($shouldNotContain, $sanitized);
        $this->assertStringContainsString(MaskConstants::MASK_GENERIC, $sanitized);
    }

    /**
     * @return string[][]
     *
     * @psalm-return array{'password with equals': array{input: 'Error: password=secretpass', shouldNotContain: 'secretpass'}, 'api key with underscore': array{input: 'Failed: api_key=key123456', shouldNotContain: 'key123456'}, 'api key with dash': array{input: 'Failed: api-key: key123456', shouldNotContain: 'key123456'}, 'token in header': array{input: 'Request failed: Authorization: Bearer token123abc', shouldNotContain: 'token123abc'}, 'mysql connection string': array{input: 'DB error: mysql://user:pass@localhost:3306', shouldNotContain: 'user:pass'}, 'secret key': array{input: 'Config: secret_key=my-secret-123', shouldNotContain: 'my-secret-123'}, 'private key': array{input: 'Error: private_key=pk_test_12345', shouldNotContain: 'pk_test_12345'}}
     */
    public static function sensitivePatternProvider(): array
    {
        return [
            'password with equals' => [
                'input' => 'Error: password=secretpass',
                'shouldNotContain' => 'secretpass',
            ],
            'api key with underscore' => [
                'input' => 'Failed: api_key=key123456',
                'shouldNotContain' => 'key123456',
            ],
            'api key with dash' => [
                'input' => 'Failed: api-key: key123456',
                'shouldNotContain' => 'key123456',
            ],
            'token in header' => [
                'input' => 'Request failed: Authorization: Bearer token123abc',
                'shouldNotContain' => 'token123abc',
            ],
            'mysql connection string' => [
                'input' => 'DB error: mysql://user:pass@localhost:3306',
                'shouldNotContain' => 'user:pass',
            ],
            'secret key' => [
                'input' => 'Config: secret_key=my-secret-123',
                'shouldNotContain' => 'my-secret-123',
            ],
            'private key' => [
                'input' => 'Error: private_key=pk_test_12345',
                'shouldNotContain' => 'pk_test_12345',
            ],
        ];
    }

    #[Test]
    public function combinesTruncationAndSanitization(): void
    {
        $longMessageWithPassword = 'Error occurred: ' . str_repeat('data ', 100) . ' password=secret123';
        $sanitized = SecuritySanitizer::sanitizeErrorMessage($longMessageWithPassword);

        $this->assertStringNotContainsString('secret123', $sanitized);
        $this->assertStringContainsString(TestConstants::ERROR_TRUNCATED_SECURITY, $sanitized);
        $this->assertLessThanOrEqual(550, strlen($sanitized));
    }

    #[Test]
    public function handlesMessageExactlyAt500Characters(): void
    {
        $message = str_repeat('a', 500);
        $sanitized = SecuritySanitizer::sanitizeErrorMessage($message);

        $this->assertSame($message, $sanitized);
        $this->assertStringNotContainsString('truncated', $sanitized);
    }

    #[Test]
    public function handlesMessageJustOver500Characters(): void
    {
        $message = str_repeat('a', 501);
        $sanitized = SecuritySanitizer::sanitizeErrorMessage($message);

        $this->assertStringContainsString(TestConstants::ERROR_TRUNCATED_SECURITY, $sanitized);
        $this->assertLessThanOrEqual(550, strlen($sanitized)); // 500 + truncation message
    }
}
