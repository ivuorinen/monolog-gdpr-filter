<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter;

/**
 * FieldMaskConfig: configuration for masking/removal per field path.
 *
 * @psalm-api
 */
final readonly class FieldMaskConfig
{
    public const MASK_REGEX = 'mask_regex';

    public const REMOVE = 'remove';

    public const REPLACE = 'replace';

    public function __construct(
        public string $type,
        public ?string $replacement = null
    ) {
    }

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
     */
    public static function regexMask(string $pattern, string $replacement = '***MASKED***'): self
    {
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
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['type'] ?? self::REPLACE,
            $data['replacement'] ?? null
        );
    }
}
