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
 */
final class PatternValidator
{
    /**
     * Static cache for compiled regex patterns to improve performance.
     * @var array<string, bool>
     */
    private static array $validPatternCache = [];

    /**
     * Clear the pattern validation cache (useful for testing).
     */
    public static function clearCache(): void
    {
        self::$validPatternCache = [];
    }

    /**
     * Validate that a regex pattern is safe and well-formed.
     * This helps prevent regex injection and ReDoS attacks.
     */
    public static function isValid(string $pattern): bool
    {
        // Check cache first
        if (isset(self::$validPatternCache[$pattern])) {
            return self::$validPatternCache[$pattern];
        }

        // Check for basic regex structure
        if (strlen($pattern) < 3) {
            self::$validPatternCache[$pattern] = false;
            return false;
        }

        // Must start and end with delimiters
        $firstChar = $pattern[0];
        $lastDelimPos = strrpos($pattern, $firstChar);
        if ($lastDelimPos === false || $lastDelimPos === 0) {
            self::$validPatternCache[$pattern] = false;
            return false;
        }

        // Enhanced ReDoS protection - check for potentially dangerous patterns
        if (self::hasDangerousPattern($pattern)) {
            self::$validPatternCache[$pattern] = false;
            return false;
        }

        // Test if the pattern is valid by trying to compile it
        set_error_handler(
            /**
             * @return true
             */
            static fn(): bool => true
        );

        try {
            /** @psalm-suppress ArgumentTypeCoercion */
            $result = preg_match($pattern, '');
            $isValid = $result !== false;
            self::$validPatternCache[$pattern] = $isValid;
            return $isValid;
        } catch (Error) {
            self::$validPatternCache[$pattern] = false;
            return false;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Check if a pattern contains dangerous constructs that could cause ReDoS.
     */
    private static function hasDangerousPattern(string $pattern): bool
    {
        $dangerousPatterns = [
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

        foreach ($dangerousPatterns as $dangerousPattern) {
            if (preg_match($dangerousPattern, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pre-validate patterns during construction for better runtime performance.
     *
     * @param array<string, string> $patterns
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
     */
    public static function getCache(): array
    {
        return self::$validPatternCache;
    }
}
