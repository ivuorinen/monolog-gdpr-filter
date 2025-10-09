<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Strategies;

use Throwable;
use Error;
use Monolog\LogRecord;
use Ivuorinen\MonologGdprFilter\Exceptions\MaskingOperationFailedException;
use Ivuorinen\MonologGdprFilter\Exceptions\InvalidRegexPatternException;

/**
 * Regex-based masking strategy.
 *
 * Applies regex pattern matching to mask sensitive data based on patterns.
 * Supports multiple patterns with corresponding replacements.
 *
 * @api
 */
class RegexMaskingStrategy extends AbstractMaskingStrategy
{
    /**
     * @param array<string, string> $patterns Array of regex pattern => replacement pairs
     * @param array<string> $includePaths Optional field paths to include (empty = all paths)
     * @param array<string> $excludePaths Optional field paths to exclude
     * @param int $priority Strategy priority (default: 60)
     */
    public function __construct(
        private readonly array $patterns,
        private readonly array $includePaths = [],
        private readonly array $excludePaths = [],
        int $priority = 60
    ) {
        parent::__construct($priority, [
            'patterns' => $patterns,
            'include_paths' => $includePaths,
            'exclude_paths' => $excludePaths,
        ]);

        $this->validatePatterns();
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function mask(mixed $value, string $path, LogRecord $logRecord): mixed
    {
        try {
            $stringValue = $this->valueToString($value);
            $maskedString = $this->applyPatterns($stringValue);
            return $this->preserveValueType($value, $maskedString);
        } catch (Throwable $throwable) {
            throw MaskingOperationFailedException::regexMaskingFailed(
                implode(', ', array_keys($this->patterns)),
                $this->generateValuePreview($value),
                $throwable->getMessage(),
                $throwable
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function shouldApply(mixed $value, string $path, LogRecord $logRecord): bool
    {
        // Check exclude paths first
        foreach ($this->excludePaths as $excludePath) {
            if ($this->pathMatches($path, $excludePath)) {
                return false;
            }
        }

        // If include paths are specified, check them
        if ($this->includePaths !== []) {
            $included = false;
            foreach ($this->includePaths as $includePath) {
                if ($this->pathMatches($path, $includePath)) {
                    $included = true;
                    break;
                }
            }

            if (!$included) {
                return false;
            }
        }

        // Check if value contains any pattern matches
        try {
            $stringValue = $this->valueToString($value);
            return $this->hasPatternMatches($stringValue);
        } catch (MaskingOperationFailedException) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function getName(): string
    {
        $patternCount = count($this->patterns);
        return sprintf('Regex Pattern Masking (%d patterns)', $patternCount);
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function validate(): bool
    {
        if ($this->patterns === []) {
            return false;
        }

        try {
            $this->validatePatterns();
            return true;
        } catch (InvalidRegexPatternException) {
            return false;
        }
    }

    /**
     * Apply all regex patterns to a string value.
     *
     * @param string $value The string to process
     * @return string The processed string with patterns applied
     * @throws MaskingOperationFailedException
     */
    private function applyPatterns(string $value): string
    {
        $result = $value;

        foreach ($this->patterns as $pattern => $replacement) {
            try {
                /** @psalm-suppress ArgumentTypeCoercion - Pattern validated during construction */
                $processedResult = preg_replace($pattern, $replacement, $result);

                if ($processedResult === null) {
                    throw MaskingOperationFailedException::regexMaskingFailed(
                        $pattern,
                        $value,
                        'preg_replace returned null - possible PCRE error'
                    );
                }

                $result = $processedResult;
            } catch (Error $e) {
                throw MaskingOperationFailedException::regexMaskingFailed(
                    $pattern,
                    $value,
                    'Pattern execution failed: ' . $e->getMessage(),
                    $e
                );
            }
        }

        return $result;
    }

    /**
     * Check if a string contains any pattern matches.
     *
     * @param string $value The string to check
     * @return bool True if any patterns match
     */
    private function hasPatternMatches(string $value): bool
    {
        foreach (array_keys($this->patterns) as $pattern) {
            try {
                /** @psalm-suppress ArgumentTypeCoercion - Pattern validated during construction */
                if (preg_match($pattern, $value) === 1) {
                    return true;
                }
            } catch (Error) {
                // Skip invalid patterns during matching
                continue;
            }
        }

        return false;
    }

    /**
     * Validate all regex patterns.
     *
     * @throws InvalidRegexPatternException
     */
    private function validatePatterns(): void
    {
        foreach (array_keys($this->patterns) as $pattern) {
            $this->validateSinglePattern($pattern);
        }
    }

    /**
     * Validate a single regex pattern.
     *
     * @param string $pattern The pattern to validate
     *
     * @throws InvalidRegexPatternException
     */
    private function validateSinglePattern(string $pattern): void
    {
        // Test pattern compilation by attempting a match
        /** @psalm-suppress ArgumentTypeCoercion - Pattern validated during construction */
        $testResult = @preg_match($pattern, '');

        if ($testResult === false) {
            $error = preg_last_error();
            throw InvalidRegexPatternException::compilationFailed($pattern, $error);
        }

        // Basic ReDoS detection - look for potentially dangerous patterns
        if ($this->detectReDoSRisk($pattern)) {
            throw InvalidRegexPatternException::redosVulnerable(
                $pattern,
                'Pattern contains potentially catastrophic backtracking sequences'
            );
        }
    }

    /**
     * Basic ReDoS (Regular Expression Denial of Service) risk detection.
     *
     * @param string $pattern The pattern to analyze
     * @return bool True if pattern appears to have ReDoS risk
     */
    private function detectReDoSRisk(string $pattern): bool
    {
        // Look for common ReDoS patterns
        $riskyPatterns = [
            '/\([^)]*\+[^)]*\)[*+]/',           // (x+)+ or (x+)*
            '/\([^)]*\*[^)]*\)[*+]/',           // (x*)+ or (x*)*
            '/\([^)]*\+[^)]*\)\{[0-9,]+\}/',    // (x+){n,m}
            '/\([^)]*\*[^)]*\)\{[0-9,]+\}/',    // (x*){n,m}
            '/\(\.\*\s*\|\s*\.\*\)/',           // (.*|.*) - identical alternations
            '/\(\.\+\s*\|\s*\.\+\)/',           // (.+|.+) - identical alternations
            '/\([a-zA-Z0-9]+(\s*\|\s*[a-zA-Z0-9]+){2,}\)\*/', // Multiple overlapping alternations with *
            '/\([a-zA-Z0-9]+(\s*\|\s*[a-zA-Z0-9]+){2,}\)\+/', // Multiple overlapping alternations with +
        ];

        foreach ($riskyPatterns as $riskyPattern) {
            if (preg_match($riskyPattern, $pattern) === 1) {
                return true;
            }
        }

        return false;
    }
}
