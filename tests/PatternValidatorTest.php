<?php

declare(strict_types=1);

namespace Tests;

use Ivuorinen\MonologGdprFilter\Exceptions\InvalidRegexPatternException;
use Ivuorinen\MonologGdprFilter\PatternValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test PatternValidator functionality.
 *
 * @api
 */
#[CoversClass(PatternValidator::class)]
class PatternValidatorTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        // Clear pattern cache before each test
        PatternValidator::clearCache();
    }

    #[\Override]
    protected function tearDown(): void
    {
        // Clear pattern cache after each test
        PatternValidator::clearCache();
        parent::tearDown();
    }

    #[Test]
    public function isValidReturnsTrueForValidPattern(): void
    {
        $this->assertTrue(PatternValidator::isValid('/\d+/'));
        $this->assertTrue(PatternValidator::isValid('/[a-z]+/i'));
        $this->assertTrue(PatternValidator::isValid('/^test$/'));
    }

    #[Test]
    public function isValidReturnsFalseForInvalidPattern(): void
    {
        $this->assertFalse(PatternValidator::isValid('invalid'));
        $this->assertFalse(PatternValidator::isValid('/unclosed'));
        $this->assertFalse(PatternValidator::isValid('//'));
    }

    #[Test]
    public function isValidReturnsFalseForDangerousPatterns(): void
    {
        $this->assertFalse(PatternValidator::isValid('/(?R)/'));
        $this->assertFalse(PatternValidator::isValid('/(?P>name)/'));
    }

    #[Test]
    public function isValidDetectsRecursivePatterns(): void
    {
        // hasDangerousPattern is private, test via isValid
        $this->assertFalse(PatternValidator::isValid('/(?R)/'));
        $this->assertFalse(PatternValidator::isValid('/(?P>name)/'));
        $this->assertFalse(PatternValidator::isValid('/\x{10000000}/'));
    }

    #[Test]
    public function isValidDetectsNestedQuantifiers(): void
    {
        // hasDangerousPattern is private, test via isValid
        $this->assertFalse(PatternValidator::isValid('/^(a+)+$/'));
        $this->assertFalse(PatternValidator::isValid('/(a*)*/'));
        $this->assertFalse(PatternValidator::isValid('/([a-zA-Z]+)*/'));
    }

    #[Test]
    public function isValidAcceptsSafePatterns(): void
    {
        // hasDangerousPattern is private, test via isValid
        $this->assertTrue(PatternValidator::isValid('/\d{3}-\d{2}-\d{4}/'));
        $this->assertTrue(PatternValidator::isValid('/[a-z]+/'));
        $this->assertTrue(PatternValidator::isValid('/^test$/'));
    }

    #[Test]
    public function cachePatternsCachesValidPatterns(): void
    {
        $patterns = [
            '/\d+/' => 'mask1',
            '/[a-z]+/' => 'mask2',
        ];

        PatternValidator::cachePatterns($patterns);
        $cache = PatternValidator::getCache();

        $this->assertArrayHasKey('/\d+/', $cache);
        $this->assertArrayHasKey('/[a-z]+/', $cache);
        $this->assertTrue($cache['/\d+/']);
        $this->assertTrue($cache['/[a-z]+/']);
    }

    #[Test]
    public function cachePatternsCachesBothValidAndInvalidPatterns(): void
    {
        $patterns = [
            '/valid/' => 'mask1',
            'invalid' => 'mask2',
        ];

        PatternValidator::cachePatterns($patterns);
        $cache = PatternValidator::getCache();

        $this->assertArrayHasKey('/valid/', $cache);
        $this->assertArrayHasKey('invalid', $cache);
        $this->assertTrue($cache['/valid/']);
        $this->assertFalse($cache['invalid']);
    }

    #[Test]
    public function validateAllThrowsForInvalidPattern(): void
    {
        $this->expectException(InvalidRegexPatternException::class);
        $this->expectExceptionMessage('Pattern failed validation or is potentially unsafe');

        PatternValidator::validateAll(['invalid_pattern' => 'mask']);
    }

    #[Test]
    public function validateAllPassesForValidPatterns(): void
    {
        $patterns = [
            '/\d{3}-\d{2}-\d{4}/' => 'SSN',
            '/[a-z]+@[a-z]+\.[a-z]+/' => 'Email',
        ];

        // Should not throw
        PatternValidator::validateAll($patterns);
        $this->assertTrue(true);
    }

    #[Test]
    public function validateAllThrowsForDangerousPattern(): void
    {
        $this->expectException(InvalidRegexPatternException::class);

        PatternValidator::validateAll(['/(?R)/' => 'mask']);
    }

    #[Test]
    public function getCacheReturnsEmptyArrayInitially(): void
    {
        $cache = PatternValidator::getCache();

        $this->assertIsArray($cache);
        $this->assertEmpty($cache);
    }

    #[Test]
    public function clearCacheRemovesAllCachedPatterns(): void
    {
        PatternValidator::cachePatterns(['/\d+/' => 'mask']);
        $this->assertNotEmpty(PatternValidator::getCache());

        PatternValidator::clearCache();
        $this->assertEmpty(PatternValidator::getCache());
    }

    #[Test]
    public function isValidUsesCacheOnSecondCall(): void
    {
        $pattern = '/\d+/';

        // First call should cache
        $result1 = PatternValidator::isValid($pattern);

        // Second call should use cache
        $result2 = PatternValidator::isValid($pattern);

        $this->assertTrue($result1);
        $this->assertTrue($result2);
        $this->assertArrayHasKey($pattern, PatternValidator::getCache());
    }

    #[Test]
    #[DataProvider('validPatternProvider')]
    public function isValidAcceptsVariousValidPatterns(string $pattern): void
    {
        $this->assertTrue(PatternValidator::isValid($pattern));
    }

    /**
     * @return string[][]
     *
     * @psalm-return array{'simple digits': array{pattern: '/\d+/'}, 'email pattern': array{pattern: '/[a-z]+@[a-z]+\.[a-z]+/'}, 'phone pattern': array{pattern: '/\+?\d{1,3}[\s-]?\d{3}[\s-]?\d{4}/'}, 'ssn pattern': array{pattern: '/\d{3}-\d{2}-\d{4}/'}, 'word boundary': array{pattern: '/\b\w+\b/'}, 'case insensitive': array{pattern: '/test/i'}, multiline: array{pattern: '/^test$/m'}, unicode: array{pattern: '/\p{L}+/u'}}
     */
    public static function validPatternProvider(): array
    {
        return [
            'simple digits' => ['pattern' => '/\d+/'],
            'email pattern' => ['pattern' => '/[a-z]+@[a-z]+\.[a-z]+/'],
            'phone pattern' => ['pattern' => '/\+?\d{1,3}[\s-]?\d{3}[\s-]?\d{4}/'],
            'ssn pattern' => ['pattern' => '/\d{3}-\d{2}-\d{4}/'],
            'word boundary' => ['pattern' => '/\b\w+\b/'],
            'case insensitive' => ['pattern' => '/test/i'],
            'multiline' => ['pattern' => '/^test$/m'],
            'unicode' => ['pattern' => '/\p{L}+/u'],
        ];
    }

    #[Test]
    #[DataProvider('invalidPatternProvider')]
    public function isValidRejectsVariousInvalidPatterns(string $pattern): void
    {
        $this->assertFalse(PatternValidator::isValid($pattern));
    }

    /**
     * @return string[][]
     *
     * @psalm-return array{'no delimiters': array{pattern: 'test'}, unclosed: array{pattern: '/unclosed'}, empty: array{pattern: '//'}, 'invalid bracket': array{pattern: '/[invalid/'}, recursive: array{pattern: '/(?R)/'}, 'named recursion': array{pattern: '/(?P>name)/'}, 'nested quantifiers': array{pattern: '/^(a+)+$/'}, 'invalid unicode': array{pattern: '/\x{10000000}/'}}
     */
    public static function invalidPatternProvider(): array
    {
        return [
            'no delimiters' => ['pattern' => 'test'],
            'unclosed' => ['pattern' => '/unclosed'],
            'empty' => ['pattern' => '//'],
            'invalid bracket' => ['pattern' => '/[invalid/'],
            'recursive' => ['pattern' => '/(?R)/'],
            'named recursion' => ['pattern' => '/(?P>name)/'],
            'nested quantifiers' => ['pattern' => '/^(a+)+$/'],
            'invalid unicode' => ['pattern' => '/\x{10000000}/'],
        ];
    }
}
