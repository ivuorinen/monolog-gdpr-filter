<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter;

use InvalidArgumentException;

/**
 * FieldMaskConfig: configuration for masking/removal per field path.
 *
 * @api
 */
final readonly class FieldMaskConfig
{
    public const MASK_REGEX = 'mask_regex';

    public const REMOVE = 'remove';

    public const REPLACE = 'replace';

    public function __construct(
        public string $type,
        public ?string $replacement = null
    ) {}

    /**
     * Create a configuration for field removal.
     */
    public static function remove(): self
    {
        return new self(self::REMOVE);
    }

    /**
     * Create a configuration for static replacement.
     *
     * @param string $replacement The replacement value
     */
    public static function replace(string $replacement): self
    {
        return new self(self::REPLACE, $replacement);
    }

    /**
     * Create a configuration for regex-based masking.
     *
     * @param string $pattern The regex pattern
     * @param string $replacement The replacement string (default: '***MASKED***')
     *
     * @throws InvalidArgumentException When pattern is empty or invalid, or replacement is empty
     */
    public static function regexMask(string $pattern, string $replacement = '***MASKED***'): self
    {
        // Validate pattern is not empty
        if (trim($pattern) === '') {
            throw new InvalidArgumentException('Regex pattern cannot be empty');
        }

        // Validate replacement is not empty
        if (trim($replacement) === '') {
            throw new InvalidArgumentException('Replacement string cannot be empty');
        }

        // Validate regex pattern syntax
        if (!self::isValidRegexPattern($pattern)) {
            throw new InvalidArgumentException(sprintf("Invalid regex pattern: '%s'", $pattern));
        }

        return new self(self::MASK_REGEX, $pattern . '::' . $replacement);
    }

    /**
     * Check if this configuration should remove the field.
     */
    public function shouldRemove(): bool
    {
        return $this->type === self::REMOVE;
    }

    /**
     * Check if this configuration has a regex pattern.
     */
    public function hasRegexPattern(): bool
    {
        return $this->type === self::MASK_REGEX;
    }

    /**
     * Get the regex pattern from a regex mask configuration.
     *
     * @return string|null The regex pattern or null if not a regex mask
     */
    public function getRegexPattern(): ?string
    {
        if ($this->type !== self::MASK_REGEX || $this->replacement === null) {
            return null;
        }

        $parts = explode('::', $this->replacement, 2);
        return $parts[0] ?? null;
    }

    /**
     * Get the replacement value.
     *
     * @return string|null The replacement value
     */
    public function getReplacement(): ?string
    {
        if ($this->type === self::MASK_REGEX && $this->replacement !== null) {
            $parts = explode('::', $this->replacement, 2);
            return $parts[1] ?? '***MASKED***';
        }

        return $this->replacement;
    }

    /**
     * Convert to array representation.
     *
     * @return (null|string)[]
     *
     * @psalm-return array{type: string, replacement: null|string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'replacement' => $this->replacement,
        ];
    }

    /**
     * Create from array representation.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException When data contains invalid values
     */
    public static function fromArray(array $data): self
    {
        $type = $data['type'] ?? self::REPLACE;
        $replacement = $data['replacement'] ?? null;

        // Validate type
        $validTypes = [self::MASK_REGEX, self::REMOVE, self::REPLACE];
        if (!in_array($type, $validTypes, true)) {
            $validList = implode(', ', $validTypes);
            throw new InvalidArgumentException(
                sprintf("Invalid type '%s'. Must be one of: %s", $type, $validList)
            );
        }

        // Validate replacement for REPLACE type - only when explicitly provided
        if (
            $type === self::REPLACE &&
            array_key_exists('replacement', $data) &&
            ($replacement === null || trim($replacement) === '')
        ) {
            throw new InvalidArgumentException('Replacement value cannot be null or empty for REPLACE type');
        }

        return new self($type, $replacement);
    }

    /**
     * Validate if a regex pattern is syntactically correct.
     *
     * @param string $pattern The regex pattern to validate
     * @return bool True if valid, false otherwise
     */
    private static function isValidRegexPattern(string $pattern): bool
    {
        // Suppress warnings for invalid patterns
        $previousErrorReporting = error_reporting(E_ERROR);

        try {
            // Test the pattern by attempting to use it
            /** @psalm-suppress ArgumentTypeCoercion - Pattern validated by caller */
            $result = @preg_match($pattern, '');

            // Check if preg_match succeeded (returns 0 or 1) or failed (returns false)
            $isValid = $result !== false;

            // Additional check for PREG errors
            if ($isValid && preg_last_error() !== PREG_NO_ERROR) {
                $isValid = false;
            }

            // Additional validation for effectively empty patterns
            // Check for patterns that are effectively empty (like '//' or '/\s*/')
            // Extract the pattern content between delimiters
            if ($isValid && preg_match('/^(.)(.*?)\1[gimuxXs]*$/', $pattern, $matches)) {
                $patternContent = $matches[2];
                // Reject patterns that are empty or only whitespace-based
                if ($patternContent === '' || trim($patternContent) === '' || $patternContent === '\s*') {
                    $isValid = false;
                }
            }

            return $isValid;
        } finally {
            // Restore previous error reporting level
            error_reporting($previousErrorReporting);
        }
    }
}
