<?php

declare(strict_types=1);

namespace Tests\InputValidation;

use Ivuorinen\MonologGdprFilter\Exceptions\InvalidRateLimitConfigurationException;
use Ivuorinen\MonologGdprFilter\RateLimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\TestConstants;

/**
 * Tests for the RateLimiter class.
 *
 * @api
 */
#[CoversClass(RateLimiter::class)]
class RateLimiterValidationTest extends TestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        // Clean up static state between tests
        RateLimiter::clearAll();
        RateLimiter::setCleanupInterval(300); // Reset to default
        parent::tearDown();
    }

    #[Test]
    public function constructorThrowsExceptionForZeroMaxRequests(): void
    {
        $this->expectException(InvalidRateLimitConfigurationException::class);
        $this->expectExceptionMessage('Maximum requests must be a positive integer, got: 0');

        new RateLimiter(0, 60);
    }

    #[Test]
    public function constructorThrowsExceptionForNegativeMaxRequests(): void
    {
        $this->expectException(InvalidRateLimitConfigurationException::class);
        $this->expectExceptionMessage('Maximum requests must be a positive integer, got: -10');

        new RateLimiter(-10, 60);
    }

    #[Test]
    public function constructorThrowsExceptionForExcessiveMaxRequests(): void
    {
        $this->expectException(InvalidRateLimitConfigurationException::class);
        $this->expectExceptionMessage('Cannot exceed 1,000,000 for memory safety');

        new RateLimiter(1000001, 60);
    }

    #[Test]
    public function constructorThrowsExceptionForZeroWindowSeconds(): void
    {
        $this->expectException(InvalidRateLimitConfigurationException::class);
        $this->expectExceptionMessage('Time window must be a positive integer representing seconds, got: 0');

        new RateLimiter(10, 0);
    }

    #[Test]
    public function constructorThrowsExceptionForNegativeWindowSeconds(): void
    {
        $this->expectException(InvalidRateLimitConfigurationException::class);
        $this->expectExceptionMessage('Time window must be a positive integer representing seconds, got: -30');

        new RateLimiter(10, -30);
    }

    #[Test]
    public function constructorThrowsExceptionForExcessiveWindowSeconds(): void
    {
        $this->expectException(InvalidRateLimitConfigurationException::class);
        $this->expectExceptionMessage('Cannot exceed 86,400 (24 hours) for practical reasons');

        new RateLimiter(10, 86401);
    }

    #[Test]
    public function constructorAcceptsValidParameters(): void
    {
        $rateLimiter = new RateLimiter(100, 3600);
        $this->assertInstanceOf(RateLimiter::class, $rateLimiter);
    }

    #[Test]
    public function constructorAcceptsBoundaryValues(): void
    {
        // Test minimum valid values
        $rateLimiter1 = new RateLimiter(1, 1);
        $this->assertInstanceOf(RateLimiter::class, $rateLimiter1);

        // Test maximum valid values
        $rateLimiter2 = new RateLimiter(1000000, 86400);
        $this->assertInstanceOf(RateLimiter::class, $rateLimiter2);
    }

    #[Test]
    public function isAllowedThrowsExceptionForEmptyKey(): void
    {
        $rateLimiter = new RateLimiter(10, 60);

        $this->expectException(InvalidRateLimitConfigurationException::class);
        $this->expectExceptionMessage(TestConstants::ERROR_RATE_LIMIT_KEY_EMPTY);

        $rateLimiter->isAllowed('');
    }

    #[Test]
    public function isAllowedThrowsExceptionForWhitespaceOnlyKey(): void
    {
        $rateLimiter = new RateLimiter(10, 60);

        $this->expectException(InvalidRateLimitConfigurationException::class);
        $this->expectExceptionMessage(TestConstants::ERROR_RATE_LIMIT_KEY_EMPTY);

        $rateLimiter->isAllowed('   ');
    }

    #[Test]
    public function isAllowedThrowsExceptionForTooLongKey(): void
    {
        $rateLimiter = new RateLimiter(10, 60);
        $longKey = str_repeat('a', 251);

        $this->expectException(InvalidRateLimitConfigurationException::class);
        $this->expectExceptionMessage('Rate limiting key length (251) exceeds maximum (250 characters)');

        $rateLimiter->isAllowed($longKey);
    }

    #[Test]
    public function isAllowedThrowsExceptionForKeyWithControlCharacters(): void
    {
        $rateLimiter = new RateLimiter(10, 60);

        $this->expectException(InvalidRateLimitConfigurationException::class);
        $this->expectExceptionMessage('Rate limiting key cannot contain control characters');

        $rateLimiter->isAllowed("test\x00key");
    }

    #[Test]
    public function isAllowedAcceptsValidKey(): void
    {
        $rateLimiter = new RateLimiter(10, 60);
        $result = $rateLimiter->isAllowed('valid_key_123');

        $this->assertTrue($result);
    }

    #[Test]
    public function getTimeUntilResetThrowsExceptionForInvalidKey(): void
    {
        $rateLimiter = new RateLimiter(10, 60);

        $this->expectException(InvalidRateLimitConfigurationException::class);
        $this->expectExceptionMessage(TestConstants::ERROR_RATE_LIMIT_KEY_EMPTY);

        $rateLimiter->getTimeUntilReset('');
    }

    #[Test]
    public function getStatsThrowsExceptionForInvalidKey(): void
    {
        $rateLimiter = new RateLimiter(10, 60);

        $this->expectException(InvalidRateLimitConfigurationException::class);
        $this->expectExceptionMessage(TestConstants::ERROR_RATE_LIMIT_KEY_EMPTY);

        $rateLimiter->getStats('');
    }

    #[Test]
    public function getRemainingRequestsThrowsExceptionForInvalidKey(): void
    {
        $rateLimiter = new RateLimiter(10, 60);

        $this->expectException(InvalidRateLimitConfigurationException::class);
        $this->expectExceptionMessage(TestConstants::ERROR_RATE_LIMIT_KEY_EMPTY);

        $rateLimiter->getRemainingRequests('');
    }

    #[Test]
    public function clearKeyThrowsExceptionForInvalidKey(): void
    {
        $this->expectException(InvalidRateLimitConfigurationException::class);
        $this->expectExceptionMessage(TestConstants::ERROR_RATE_LIMIT_KEY_EMPTY);

        RateLimiter::clearKey('');
    }

    #[Test]
    public function setCleanupIntervalThrowsExceptionForZeroSeconds(): void
    {
        $this->expectException(InvalidRateLimitConfigurationException::class);
        $this->expectExceptionMessage('Cleanup interval must be a positive integer, got: 0');

        RateLimiter::setCleanupInterval(0);
    }

    #[Test]
    public function setCleanupIntervalThrowsExceptionForNegativeSeconds(): void
    {
        $this->expectException(InvalidRateLimitConfigurationException::class);
        $this->expectExceptionMessage('Cleanup interval must be a positive integer, got: -100');

        RateLimiter::setCleanupInterval(-100);
    }

    #[Test]
    public function setCleanupIntervalThrowsExceptionForTooSmallValue(): void
    {
        $this->expectException(InvalidRateLimitConfigurationException::class);
        $this->expectExceptionMessage(
            'Cleanup interval (30 seconds) is too short, minimum is 60 seconds'
        );

        RateLimiter::setCleanupInterval(30);
    }

    #[Test]
    public function setCleanupIntervalThrowsExceptionForExcessiveValue(): void
    {
        $this->expectException(InvalidRateLimitConfigurationException::class);
        $this->expectExceptionMessage(
            'Cannot exceed 604,800 seconds (1 week) for practical reasons'
        );

        RateLimiter::setCleanupInterval(604801);
    }

    #[Test]
    public function setCleanupIntervalAcceptsValidValues(): void
    {
        // Test minimum valid value
        RateLimiter::setCleanupInterval(60);
        $stats = RateLimiter::getMemoryStats();
        $this->assertSame(60, $stats['cleanup_interval']);

        // Test maximum valid value
        RateLimiter::setCleanupInterval(604800);
        $stats = RateLimiter::getMemoryStats();
        $this->assertSame(604800, $stats['cleanup_interval']);

        // Test middle value
        RateLimiter::setCleanupInterval(1800);
        $stats = RateLimiter::getMemoryStats();
        $this->assertSame(1800, $stats['cleanup_interval']);
    }

    #[Test]
    public function keyValidationWorksConsistentlyAcrossAllMethods(): void
    {
        $rateLimiter = new RateLimiter(10, 60);
        $invalidKey = str_repeat('x', 251);

        // Test all methods that should validate keys
        $methods = [
            'isAllowed',
            'getTimeUntilReset',
            'getStats',
            'getRemainingRequests'
        ];

        foreach ($methods as $method) {
            try {
                $rateLimiter->$method($invalidKey);
                $this->fail(sprintf(
                    'Method %s should have thrown InvalidArgumentException for invalid key',
                    $method
                ));
            } catch (InvalidRateLimitConfigurationException $e) {
                $this->assertStringContainsString(
                    'Rate limiting key length',
                    $e->getMessage()
                );
            }
        }

        // Test static method
        try {
            RateLimiter::clearKey($invalidKey);
            $this->fail('clearKey should have thrown InvalidArgumentException for invalid key');
        } catch (InvalidRateLimitConfigurationException $invalidArgumentException) {
            $this->assertStringContainsString(
                'Rate limiting key length',
                $invalidArgumentException->getMessage()
            );
        }
    }

    #[Test]
    public function validKeysWorkCorrectlyAfterValidation(): void
    {
        $rateLimiter = new RateLimiter(5, 60);
        $validKey = 'user_123_action_login';

        // Should not throw exceptions
        $this->assertTrue($rateLimiter->isAllowed($validKey));
        $this->assertIsInt($rateLimiter->getTimeUntilReset($validKey));
        $this->assertIsArray($rateLimiter->getStats($validKey));
        $this->assertIsInt($rateLimiter->getRemainingRequests($validKey));

        // This should also not throw
        RateLimiter::clearKey($validKey);
    }

    #[Test]
    public function boundaryKeyLengthsWork(): void
    {
        $rateLimiter = new RateLimiter(10, 60);

        // Test exactly 250 characters (should work)
        $maxValidKey = str_repeat('a', 250);
        $this->assertTrue($rateLimiter->isAllowed($maxValidKey));

        // Test exactly 251 characters (should fail)
        $tooLongKey = str_repeat('a', 251);
        $this->expectException(InvalidRateLimitConfigurationException::class);
        $rateLimiter->isAllowed($tooLongKey);
    }

    #[Test]
    public function controlCharacterDetectionWorks(): void
    {
        $rateLimiter = new RateLimiter(10, 60);

        $controlChars = [
            "\x00", // null
            "\x01", // start of heading
            "\x1F", // unit separator
            "\x7F", // delete
        ];

        foreach ($controlChars as $char) {
            try {
                $rateLimiter->isAllowed(sprintf('test%skey', $char));
                $this->fail("Should have thrown exception for control character: " . ord($char));
            } catch (InvalidRateLimitConfigurationException $e) {
                $this->assertStringContainsString(
                    'Rate limiting key cannot contain control characters',
                    $e->getMessage()
                );
            }
        }
    }

    #[Test]
    public function validSpecialCharactersAreAllowed(): void
    {
        $rateLimiter = new RateLimiter(10, 60);

        $validKeys = [
            'user-123',
            'action_login',
            'key.with.dots',
            'key@domain.com',
            'key+suffix',
            'key=value',
            'key:value',
            'key;semicolon',
            'key,comma',
            'key space',
            'key[bracket]',
            'key{brace}',
            'key(paren)',
            'key#hash',
            'key%percent',
            'key^caret',
            'key&ampersand',
            'key*asterisk',
            'key!exclamation',
            'key?question',
            'key~tilde',
            'key`backtick',
            'key|pipe',
            'key\\backslash',
            'key/slash',
            'key"quote',
            "key'apostrophe",
            'key<less>',
            'key$dollar',
        ];

        foreach ($validKeys as $key) {
            $this->assertTrue($rateLimiter->isAllowed($key), 'Key should be valid: ' . $key);
        }
    }
}
