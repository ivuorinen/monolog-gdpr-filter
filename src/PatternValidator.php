<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter;

use Error;
use Ivuorinen\MonologGdprFilter\Exceptions\InvalidRegexPatternException;

/**
 * Validates regex patterns for safety and correctness.
 *
 * This class provides pattern validation with ReDoS (Regular Expression Denial of Service)
 * protection and caching for improved performance.
 *
 * @api
 */
final class PatternValidator
{
    /**
     * Instance cache for compiled regex patterns.
     * @var array<string, bool>
     */
    private array $instanceCache = [];

    /**
     * Static cache for compiled regex patterns (for backward compatibility).
     * @var array<string, bool>
     */
    private static array $validPatternCache = [];

    /**
     * Dangerous pattern checks.
     * @var list<non-empty-string>
     */
    private static array $dangerousPatterns = [
        // Nested quantifiers (classic ReDoS patterns)
        '/\([^)]*\+[^)]*\)\+/',     // (a+)+ pattern
        '/\([^)]*\*[^)]*\)\*/',     // (a*)* pattern
        '/\([^)]*\+[^)]*\)\*/',     // (a+)* pattern
        '/\([^)]*\*[^)]*\)\+/',     // (a*)+ pattern

        // Alternation with overlapping patterns
        '/\([^|)]*\|[^|)]*\)\*/',   // (a|a)* pattern
        '/\([^|)]*\|[^|)]*\)\+/',   // (a|a)+ pattern

        // Complex nested structures
        '/\(\([^)]*\+[^)]*\)[^)]*\)\+/',  // ((a+)...)+ pattern

        // Character classes with nested quantifiers
        '/\[[^\]]*\]\*\*/',         // [a-z]** pattern
        '/\[[^\]]*\]\+\+/',         // [a-z]++ pattern
        '/\([^)]*\[[^\]]*\][^)]*\)\*/', // ([a-z])* pattern
        '/\([^)]*\[[^\]]*\][^)]*\)\+/', // ([a-z])+ pattern

        // Lookahead/lookbehind with quantifiers
        '/\(\?\=[^)]*\)\([^)]*\)\+/', // (?=...)(...)+
        '/\(\?\<[^)]*\)\([^)]*\)\+/', // (?<...)(...)+

        // Word boundaries with dangerous quantifiers
        '/\\\\w\+\*/',              // \w+* pattern
        '/\\\\w\*\+/',              // \w*+ pattern

        // Dot with dangerous quantifiers
        '/\.\*\*/',                 // .** pattern
        '/\.\+\+/',                 // .++ pattern
        '/\(\.\*\)\+/',             // (.*)+ pattern
        '/\(\.\+\)\*/',             // (.+)* pattern

        // Legacy dangerous patterns (keeping for backward compatibility)
        '/\(\?.*\*.*\+/',           // (?:...*...)+
        '/\(.*\*.*\).*\*/',         // (...*...).*

        // Overlapping alternation patterns - catastrophic backtracking
        '/\(\.\*\s*\|\s*\.\*\)/',   // (.*|.*) pattern - identical alternations
        '/\(\.\+\s*\|\s*\.\+\)/',   // (.+|.+) pattern - identical alternations

        // Multiple alternations with overlapping/expanding strings causing exponential backtracking
        // Matches patterns like (a|ab|abc|abcd)* where alternatives overlap/extend each other
        '/\([a-zA-Z0-9]+(\s*\|\s*[a-zA-Z0-9]+){2,}\)\*/',
        '/\([a-zA-Z0-9]+(\s*\|\s*[a-zA-Z0-9]+){2,}\)\+/',
    ];

    /**
     * Create a new PatternValidator instance.
     */
    public function __construct()
    {
        // Instance cache starts empty
    }

    /**
     * Static factory method.
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Clear the instance cache.
     */
    public function clearInstanceCache(): void
    {
        $this->instanceCache = [];
    }

    /**
     * Validate that a regex pattern is safe and well-formed.
     */
    public function validate(string $pattern): bool
    {
        // Check instance cache first
        if (isset($this->instanceCache[$pattern])) {
            return $this->instanceCache[$pattern];
        }

        $isValid = $this->performValidation($pattern);
        $this->instanceCache[$pattern] = $isValid;

        return $isValid;
    }

    /**
     * Pre-validate patterns for better runtime performance.
     *
     * @param array<string, string> $patterns
     */
    public function cacheAllPatterns(array $patterns): void
    {
        foreach (array_keys($patterns) as $pattern) {
            if (!isset($this->instanceCache[$pattern])) {
                $this->instanceCache[$pattern] = $this->validate($pattern);
            }
        }
    }

    /**
     * Validate all patterns for security before use.
     *
     * @param array<string, string> $patterns
     * @throws InvalidRegexPatternException If any pattern is invalid or unsafe
     */
    public function validateAllPatterns(array $patterns): void
    {
        foreach (array_keys($patterns) as $pattern) {
            if (!$this->validate($pattern)) {
                throw InvalidRegexPatternException::forPattern(
                    $pattern,
                    'Pattern failed validation or is potentially unsafe'
                );
            }
        }
    }

    /**
     * Get the instance cache.
     *
     * @return array<string, bool>
     */
    public function getInstanceCache(): array
    {
        return $this->instanceCache;
    }

    /**
     * Perform the actual validation logic.
     */
    private function performValidation(string $pattern): bool
    {
        // Check for basic regex structure
        $firstChar = $pattern[0];
        $lastDelimPos = strrpos($pattern, $firstChar);

        // Consolidated validation checks - return false if any basic check fails
        if (
            strlen($pattern) < 3
            || $lastDelimPos === false
            || $lastDelimPos === 0
            || $this->checkDangerousPattern($pattern)
        ) {
            return false;
        }

        // Test if the pattern is valid by trying to compile it
        return $this->testPatternCompilation($pattern);
    }

    /**
     * Check if a pattern contains dangerous constructs that could cause ReDoS.
     */
    private function checkDangerousPattern(string $pattern): bool
    {
        foreach (self::$dangerousPatterns as $dangerousPattern) {
            if (preg_match($dangerousPattern, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Test if the pattern compiles successfully.
     */
    private function testPatternCompilation(string $pattern): bool
    {
        set_error_handler(
            /**
             * @return true
             */
            static fn(): bool => true
        );

        try {
            /** @psalm-suppress ArgumentTypeCoercion */
            $result = preg_match($pattern, '');
            return $result !== false;
        } catch (Error) {
            return false;
        } finally {
            restore_error_handler();
        }
    }

    // =========================================================================
    // DEPRECATED STATIC METHODS - Use instance methods instead
    // =========================================================================

    /**
     * Clear the pattern validation cache (useful for testing).
     *
     * @deprecated Use instance method clearInstanceCache() instead
     */
    public static function clearCache(): void
    {
        self::$validPatternCache = [];
    }

    /**
     * Validate that a regex pattern is safe and well-formed.
     * This helps prevent regex injection and ReDoS attacks.
     *
     * @deprecated Use instance method validate() instead
     */
    public static function isValid(string $pattern): bool
    {
        // Check cache first
        if (isset(self::$validPatternCache[$pattern])) {
            return self::$validPatternCache[$pattern];
        }

        $validator = new self();
        $isValid = $validator->performValidation($pattern);

        self::$validPatternCache[$pattern] = $isValid;
        return $isValid;
    }

    /**
     * Pre-validate patterns during construction for better runtime performance.
     *
     * @param array<string, string> $patterns
     * @deprecated Use instance method cacheAllPatterns() instead
     */
    public static function cachePatterns(array $patterns): void
    {
        foreach (array_keys($patterns) as $pattern) {
            if (!isset(self::$validPatternCache[$pattern])) {
                self::$validPatternCache[$pattern] = self::isValid($pattern);
            }
        }
    }

    /**
     * Validate all patterns for security before use.
     * This method can be called to validate patterns before creating a processor.
     *
     * @param array<string, string> $patterns
     * @throws InvalidRegexPatternException If any pattern is invalid or unsafe
     * @deprecated Use instance method validateAllPatterns() instead
     */
    public static function validateAll(array $patterns): void
    {
        foreach (array_keys($patterns) as $pattern) {
            if (!self::isValid($pattern)) {
                throw InvalidRegexPatternException::forPattern(
                    $pattern,
                    'Pattern failed validation or is potentially unsafe'
                );
            }
        }
    }

    /**
     * Get the current pattern cache.
     *
     * @return array<string, bool>
     * @deprecated Use instance method getInstanceCache() instead
     */
    public static function getCache(): array
    {
        return self::$validPatternCache;
    }
}
