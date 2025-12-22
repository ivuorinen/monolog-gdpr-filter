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
        $message = 'API request failed: api_key=' . TestConstants::API_KEY;
        $sanitized = SecuritySanitizer::sanitizeErrorMessage($message);

        $this->assertStringNotContainsString(TestConstants::API_KEY, $sanitized);
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
     * @psalm-return array{
     *     'password with equals': array{
     *         input: 'Error: password=secretpass',
     *         shouldNotContain: 'secretpass'
     *     },
     *     'api key with underscore': array{
     *         input: 'Failed: api_key=key123456',
     *         shouldNotContain: 'key123456'
     *     },
     *     'api key with dash': array{
     *         input: 'Failed: api-key: key123456',
     *         shouldNotContain: 'key123456'
     *     },
     *     'token in header': array{
     *         input: 'Request failed: Authorization: Bearer token123abc',
     *         shouldNotContain: 'token123abc'
     *     },
     *     'mysql connection string': array{
     *         input: 'DB error: mysql://user:pass@localhost:3306',
     *         shouldNotContain: 'user:pass'
     *     },
     *     'secret key': array{
     *         input: 'Config: secret_key=my-secret-123',
     *         shouldNotContain: 'my-secret-123'
     *     },
     *     'private key': array{
     *         input: 'Error: private_key=pk_test_12345',
     *         shouldNotContain: 'pk_test_12345'
     *     }
     * }
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

    #[Test]
    public function sanitizesPwdAndPassVariations(): void
    {
        $message = 'Connection failed: pwd=mypassword pass=anotherpass';
        $sanitized = SecuritySanitizer::sanitizeErrorMessage($message);

        $this->assertStringContainsString('pwd=***', $sanitized);
        $this->assertStringContainsString('pass=***', $sanitized);
        $this->assertStringNotContainsString('mypassword', $sanitized);
        $this->assertStringNotContainsString('anotherpass', $sanitized);
    }

    #[Test]
    public function sanitizesHostServerHostnamePatterns(): void
    {
        $message = 'Error: host=db.example.com server=192.168.1.1 hostname=internal.local';
        $sanitized = SecuritySanitizer::sanitizeErrorMessage($message);

        $this->assertStringContainsString('host=***', $sanitized);
        $this->assertStringContainsString('server=***', $sanitized);
        $this->assertStringContainsString('hostname=***', $sanitized);
        $this->assertStringNotContainsString('db.example.com', $sanitized);
        $this->assertStringNotContainsString('internal.local', $sanitized);
    }

    #[Test]
    public function sanitizesUsernameAndUidPatterns(): void
    {
        $message = 'Auth error: username=admin uid=12345';
        $sanitized = SecuritySanitizer::sanitizeErrorMessage($message);

        $this->assertStringContainsString('username=***', $sanitized);
        $this->assertStringContainsString('uid=***', $sanitized);
        $this->assertStringNotContainsString('=admin', $sanitized);
    }

    #[Test]
    public function sanitizesStripeStyleKeys(): void
    {
        $message = 'Stripe error: sk_live_abc123def456 pk_test_xyz789ghi012';
        $sanitized = SecuritySanitizer::sanitizeErrorMessage($message);

        $this->assertStringContainsString('sk_***', $sanitized);
        $this->assertStringContainsString('pk_***', $sanitized);
        $this->assertStringNotContainsString('sk_live_abc123def456', $sanitized);
        $this->assertStringNotContainsString('pk_test_xyz789ghi012', $sanitized);
    }

    #[Test]
    public function sanitizesRedisConnectionString(): void
    {
        $message = 'Redis error: redis://user:pass@localhost:6379';
        $sanitized = SecuritySanitizer::sanitizeErrorMessage($message);

        $this->assertStringContainsString('redis://***:***@***:***', $sanitized);
        $this->assertStringNotContainsString('user:pass@', $sanitized);
    }

    #[Test]
    public function sanitizesPostgresqlConnectionString(): void
    {
        $message = 'Connection error: postgresql://admin:password@pg.server:5432';
        $sanitized = SecuritySanitizer::sanitizeErrorMessage($message);

        $this->assertStringContainsString('postgresql://***:***@***:***', $sanitized);
        $this->assertStringNotContainsString('admin:password@', $sanitized);
    }

    #[Test]
    public function sanitizesJwtSecretPatterns(): void
    {
        $message = 'JWT error: jwt_secret=mysupersecret123';
        $sanitized = SecuritySanitizer::sanitizeErrorMessage($message);

        $this->assertStringContainsString('jwt_secret=***', $sanitized);
        $this->assertStringNotContainsString('mysupersecret123', $sanitized);
    }

    #[Test]
    public function sanitizesSuperSecretPattern(): void
    {
        $message = 'Config contains super_secret_value';
        $sanitized = SecuritySanitizer::sanitizeErrorMessage($message);

        $this->assertStringContainsString(MaskConstants::MASK_SECRET, $sanitized);
        $this->assertStringNotContainsString('super_secret_value', $sanitized);
    }

    #[Test]
    #[DataProvider('internalIpRangesProvider')]
    public function sanitizesInternalIpAddresses(string $ip, string $range): void
    {
        $message = 'Cannot reach server at ' . $ip;
        $sanitized = SecuritySanitizer::sanitizeErrorMessage($message);

        $this->assertStringNotContainsString($ip, $sanitized, "Internal IP in $range range should be masked");
        $this->assertStringContainsString('***.***.***', $sanitized);
    }

    /**
     * @return array<string, array{ip: string, range: string}>
     */
    public static function internalIpRangesProvider(): array
    {
        return [
            '10.0.0.1' => ['ip' => '10.0.0.1', 'range' => '10.x.x.x'],
            '10.255.255.255' => ['ip' => '10.255.255.255', 'range' => '10.x.x.x'],
            '172.16.0.1' => ['ip' => '172.16.0.1', 'range' => '172.16-31.x.x'],
            '172.31.255.255' => ['ip' => '172.31.255.255', 'range' => '172.16-31.x.x'],
            '192.168.0.1' => ['ip' => '192.168.0.1', 'range' => '192.168.x.x'],
            '192.168.255.255' => ['ip' => '192.168.255.255', 'range' => '192.168.x.x'],
        ];
    }

    #[Test]
    public function preservesPublicIpAddresses(): void
    {
        $message = 'External server at 8.8.8.8 responded';
        $sanitized = SecuritySanitizer::sanitizeErrorMessage($message);

        $this->assertStringContainsString('8.8.8.8', $sanitized);
    }

    #[Test]
    public function sanitizesSensitiveFilePaths(): void
    {
        $message = 'Error reading /var/www/config/secrets.json';
        $sanitized = SecuritySanitizer::sanitizeErrorMessage($message);

        $this->assertStringNotContainsString('/var/www/config', $sanitized);
        $this->assertStringContainsString('/***/', $sanitized);
    }

    #[Test]
    public function sanitizesWindowsSensitiveFilePaths(): void
    {
        $message = 'Error reading C:\\Users\\admin\\config\\secrets.txt';
        $sanitized = SecuritySanitizer::sanitizeErrorMessage($message);

        // Windows paths with sensitive keywords are masked
        $this->assertStringNotContainsString('admin', $sanitized);
        $this->assertStringNotContainsString('secrets.txt', $sanitized);
        $this->assertStringContainsString('C:\\***', $sanitized);
    }

    #[Test]
    public function sanitizesGenericSecretPatterns(): void
    {
        $message = 'Config: app_secret=abcd1234567890 encryption_key: xyz9876543210abc';
        $sanitized = SecuritySanitizer::sanitizeErrorMessage($message);

        $this->assertStringNotContainsString('abcd1234567890', $sanitized);
        $this->assertStringNotContainsString('xyz9876543210abc', $sanitized);
    }

    #[Test]
    public function classCannotBeInstantiated(): void
    {
        $reflection = new \ReflectionClass(SecuritySanitizer::class);
        $constructor = $reflection->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate());
    }

    #[Test]
    public function sanitizesUserPattern(): void
    {
        $message = 'Connection failed: user=dbadmin';
        $sanitized = SecuritySanitizer::sanitizeErrorMessage($message);

        $this->assertStringContainsString('user=***', $sanitized);
        $this->assertStringNotContainsString('dbadmin', $sanitized);
    }

    #[Test]
    public function caseInsensitivePatternMatching(): void
    {
        $message = 'Error: PASSWORD=secret HOST=server.local TOKEN=abc123token';
        $sanitized = SecuritySanitizer::sanitizeErrorMessage($message);

        // Case-insensitive matching means uppercase is matched but replaced with lowercase pattern
        $this->assertStringNotContainsString('secret', $sanitized);
        $this->assertStringNotContainsString('server.local', $sanitized);
        $this->assertStringNotContainsString('abc123token', $sanitized);
        $this->assertStringContainsString('=***', $sanitized);
    }
}
