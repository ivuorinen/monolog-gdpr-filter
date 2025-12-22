<?php

declare(strict_types=1);

namespace Tests;

use Tests\TestConstants;
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
 * @psalm-suppress DeprecatedMethod - Tests for deprecated static API
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
        $this->assertTrue(PatternValidator::isValid(TestConstants::PATTERN_DIGITS));
        $this->assertTrue(PatternValidator::isValid('/[a-z]+/i'));
        $this->assertTrue(PatternValidator::isValid(TestConstants::PATTERN_VALID_SIMPLE));
    }

    #[Test]
    public function isValidReturnsFalseForInvalidPattern(): void
    {
        $this->assertFalse(PatternValidator::isValid('invalid'));
        $this->assertFalse(PatternValidator::isValid(TestConstants::PATTERN_INVALID_UNCLOSED));
        $this->assertFalse(PatternValidator::isValid('//'));
    }

    #[Test]
    public function isValidReturnsFalseForDangerousPatterns(): void
    {
        $this->assertFalse(PatternValidator::isValid(TestConstants::PATTERN_RECURSIVE));
        $this->assertFalse(PatternValidator::isValid(TestConstants::PATTERN_NAMED_RECURSION));
    }

    #[Test]
    public function isValidDetectsRecursivePatterns(): void
    {
        // hasDangerousPattern is private, test via isValid
        $this->assertFalse(PatternValidator::isValid(TestConstants::PATTERN_RECURSIVE));
        $this->assertFalse(PatternValidator::isValid(TestConstants::PATTERN_NAMED_RECURSION));
        $this->assertFalse(PatternValidator::isValid('/\x{10000000}/'));
    }

    #[Test]
    public function isValidDetectsNestedQuantifiers(): void
    {
        // hasDangerousPattern is private, test via isValid
        $this->assertFalse(PatternValidator::isValid(TestConstants::PATTERN_REDOS_VULNERABLE));
        $this->assertFalse(PatternValidator::isValid('/(a*)*/'));
        $this->assertFalse(PatternValidator::isValid('/([a-zA-Z]+)*/'));
    }

    #[Test]
    public function isValidAcceptsSafePatterns(): void
    {
        // hasDangerousPattern is private, test via isValid
        $this->assertTrue(PatternValidator::isValid(TestConstants::PATTERN_SSN_FORMAT));
        $this->assertTrue(PatternValidator::isValid(TestConstants::PATTERN_SAFE));
        $this->assertTrue(PatternValidator::isValid(TestConstants::PATTERN_VALID_SIMPLE));
    }

    #[Test]
    public function cachePatternsCachesValidPatterns(): void
    {
        $patterns = [
            TestConstants::PATTERN_DIGITS => 'mask1',
            TestConstants::PATTERN_SAFE => 'mask2',
        ];

        PatternValidator::cachePatterns($patterns);
        $cache = PatternValidator::getCache();

        $this->assertArrayHasKey(TestConstants::PATTERN_DIGITS, $cache);
        $this->assertArrayHasKey(TestConstants::PATTERN_SAFE, $cache);
        $this->assertTrue($cache[TestConstants::PATTERN_DIGITS]);
        $this->assertTrue($cache[TestConstants::PATTERN_SAFE]);
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
            TestConstants::PATTERN_SSN_FORMAT => 'SSN',
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

        PatternValidator::validateAll([TestConstants::PATTERN_RECURSIVE => 'mask']);
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
        PatternValidator::cachePatterns([TestConstants::PATTERN_DIGITS => 'mask']);
        $this->assertNotEmpty(PatternValidator::getCache());

        PatternValidator::clearCache();
        $this->assertEmpty(PatternValidator::getCache());
    }

    #[Test]
    public function isValidUsesCacheOnSecondCall(): void
    {
        $pattern = TestConstants::PATTERN_DIGITS;

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
     * @psalm-return array{
     *     'simple digits': array{pattern: TestConstants::PATTERN_DIGITS},
     *     'email pattern': array{pattern: '/[a-z]+@[a-z]+\.[a-z]+/'},
     *     'phone pattern': array{pattern: '/\+?\d{1,3}[\s-]?\d{3}[\s-]?\d{4}/'},
     *     'ssn pattern': array{pattern: TestConstants::PATTERN_SSN_FORMAT},
     *     'word boundary': array{pattern: '/\b\w+\b/'},
     *     'case insensitive': array{pattern: '/test/i'},
     *     multiline: array{pattern: '/^test$/m'},
     *     unicode: array{pattern: '/\p{L}+/u'}
     * }
     */
    public static function validPatternProvider(): array
    {
        return [
            'simple digits' => ['pattern' => TestConstants::PATTERN_DIGITS],
            'email pattern' => ['pattern' => '/[a-z]+@[a-z]+\.[a-z]+/'],
            'phone pattern' => ['pattern' => '/\+?\d{1,3}[\s-]?\d{3}[\s-]?\d{4}/'],
            'ssn pattern' => ['pattern' => TestConstants::PATTERN_SSN_FORMAT],
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
     * @psalm-return array{
     *     'no delimiters': array{pattern: 'test'},
     *     unclosed: array{pattern: TestConstants::PATTERN_INVALID_UNCLOSED},
     *     empty: array{pattern: '//'},
     *     'invalid bracket': array{pattern: '/[invalid/'},
     *     recursive: array{pattern: TestConstants::PATTERN_RECURSIVE},
     *     'named recursion': array{pattern: TestConstants::PATTERN_NAMED_RECURSION},
     *     'nested quantifiers': array{pattern: TestConstants::PATTERN_REDOS_VULNERABLE},
     *     'invalid unicode': array{pattern: '/\x{10000000}/'}
     * }
     */
    public static function invalidPatternProvider(): array
    {
        return [
            'no delimiters' => ['pattern' => 'test'],
            'unclosed' => ['pattern' => TestConstants::PATTERN_INVALID_UNCLOSED],
            'empty' => ['pattern' => '//'],
            'invalid bracket' => ['pattern' => '/[invalid/'],
            'recursive' => ['pattern' => TestConstants::PATTERN_RECURSIVE],
            'named recursion' => ['pattern' => TestConstants::PATTERN_NAMED_RECURSION],
            'nested quantifiers' => ['pattern' => TestConstants::PATTERN_REDOS_VULNERABLE],
            'invalid unicode' => ['pattern' => '/\x{10000000}/'],
        ];
    }

    // =========================================================================
    // INSTANCE METHOD TESTS
    // =========================================================================

    #[Test]
    public function createReturnsNewInstance(): void
    {
        $validator = PatternValidator::create();

        $this->assertInstanceOf(PatternValidator::class, $validator);
    }

    #[Test]
    public function validateReturnsTrueForValidPattern(): void
    {
        $validator = new PatternValidator();

        $this->assertTrue($validator->validate(TestConstants::PATTERN_DIGITS));
        $this->assertTrue($validator->validate('/[a-z]+/i'));
        $this->assertTrue($validator->validate(TestConstants::PATTERN_VALID_SIMPLE));
    }

    #[Test]
    public function validateReturnsFalseForInvalidPattern(): void
    {
        $validator = new PatternValidator();

        $this->assertFalse($validator->validate('invalid'));
        $this->assertFalse($validator->validate(TestConstants::PATTERN_INVALID_UNCLOSED));
        $this->assertFalse($validator->validate('//'));
    }

    #[Test]
    public function validateReturnsFalseForDangerousPatterns(): void
    {
        $validator = new PatternValidator();

        $this->assertFalse($validator->validate(TestConstants::PATTERN_RECURSIVE));
        $this->assertFalse($validator->validate(TestConstants::PATTERN_NAMED_RECURSION));
        $this->assertFalse($validator->validate(TestConstants::PATTERN_REDOS_VULNERABLE));
    }

    #[Test]
    public function validateUsesCacheOnSecondCall(): void
    {
        $validator = new PatternValidator();
        $pattern = TestConstants::PATTERN_DIGITS;

        // First call should cache
        $result1 = $validator->validate($pattern);

        // Second call should use cache
        $result2 = $validator->validate($pattern);

        $this->assertTrue($result1);
        $this->assertTrue($result2);
        $this->assertArrayHasKey($pattern, $validator->getInstanceCache());
    }

    #[Test]
    public function clearInstanceCacheRemovesAllCachedPatterns(): void
    {
        $validator = new PatternValidator();

        $validator->validate(TestConstants::PATTERN_DIGITS);
        $this->assertNotEmpty($validator->getInstanceCache());

        $validator->clearInstanceCache();
        $this->assertEmpty($validator->getInstanceCache());
    }

    #[Test]
    public function cacheAllPatternsCachesValidPatterns(): void
    {
        $validator = new PatternValidator();
        $patterns = [
            TestConstants::PATTERN_DIGITS => 'mask1',
            TestConstants::PATTERN_SAFE => 'mask2',
        ];

        $validator->cacheAllPatterns($patterns);
        $cache = $validator->getInstanceCache();

        $this->assertArrayHasKey(TestConstants::PATTERN_DIGITS, $cache);
        $this->assertArrayHasKey(TestConstants::PATTERN_SAFE, $cache);
        $this->assertTrue($cache[TestConstants::PATTERN_DIGITS]);
        $this->assertTrue($cache[TestConstants::PATTERN_SAFE]);
    }

    #[Test]
    public function cacheAllPatternsCachesBothValidAndInvalid(): void
    {
        $validator = new PatternValidator();
        $patterns = [
            '/valid/' => 'mask1',
            'invalid' => 'mask2',
        ];

        $validator->cacheAllPatterns($patterns);
        $cache = $validator->getInstanceCache();

        $this->assertTrue($cache['/valid/']);
        $this->assertFalse($cache['invalid']);
    }

    #[Test]
    public function validateAllPatternsThrowsForInvalidPattern(): void
    {
        $validator = new PatternValidator();

        $this->expectException(InvalidRegexPatternException::class);
        $this->expectExceptionMessage('Pattern failed validation or is potentially unsafe');

        $validator->validateAllPatterns(['invalid_pattern' => 'mask']);
    }

    #[Test]
    public function validateAllPatternsPassesForValidPatterns(): void
    {
        $validator = new PatternValidator();
        $patterns = [
            TestConstants::PATTERN_SSN_FORMAT => 'SSN',
            '/[a-z]+@[a-z]+\.[a-z]+/' => 'Email',
        ];

        // Should not throw
        $validator->validateAllPatterns($patterns);
        $this->assertTrue(true);
    }

    #[Test]
    public function validateAllPatternsThrowsForDangerousPattern(): void
    {
        $validator = new PatternValidator();

        $this->expectException(InvalidRegexPatternException::class);

        $validator->validateAllPatterns([TestConstants::PATTERN_RECURSIVE => 'mask']);
    }

    #[Test]
    public function getInstanceCacheReturnsEmptyArrayInitially(): void
    {
        $validator = new PatternValidator();
        $cache = $validator->getInstanceCache();

        $this->assertIsArray($cache);
        $this->assertEmpty($cache);
    }

    #[Test]
    public function instanceCachesAreIndependent(): void
    {
        $validator1 = new PatternValidator();
        $validator2 = new PatternValidator();

        $validator1->validate(TestConstants::PATTERN_DIGITS);

        $this->assertNotEmpty($validator1->getInstanceCache());
        $this->assertEmpty($validator2->getInstanceCache());
    }

    #[Test]
    #[DataProvider('validPatternProvider')]
    public function validateAcceptsVariousValidPatterns(string $pattern): void
    {
        $validator = new PatternValidator();

        $this->assertTrue($validator->validate($pattern));
    }

    #[Test]
    #[DataProvider('invalidPatternProvider')]
    public function validateRejectsVariousInvalidPatterns(string $pattern): void
    {
        $validator = new PatternValidator();

        $this->assertFalse($validator->validate($pattern));
    }
}
