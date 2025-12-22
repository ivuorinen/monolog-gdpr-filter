<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Builder\Traits;

use Ivuorinen\MonologGdprFilter\DefaultPatterns;

/**
 * Provides pattern configuration methods for GdprProcessorBuilder.
 *
 * Handles regex pattern management including adding, setting, and retrieving patterns
 * used for masking sensitive data in log records.
 */
trait PatternConfigurationTrait
{
    /**
     * @var array<string,string>
     */
    private array $patterns = [];

    /**
     * Add a regex pattern.
     *
     * @param string $pattern Regex pattern
     * @param string $replacement Replacement string
     */
    public function addPattern(string $pattern, string $replacement): self
    {
        $this->patterns[$pattern] = $replacement;
        return $this;
    }

    /**
     * Add multiple patterns.
     *
     * @param array<string,string> $patterns Regex pattern => replacement
     */
    public function addPatterns(array $patterns): self
    {
        $this->patterns = array_merge($this->patterns, $patterns);
        return $this;
    }

    /**
     * Set all patterns (replaces existing).
     *
     * @param array<string,string> $patterns Regex pattern => replacement
     */
    public function setPatterns(array $patterns): self
    {
        $this->patterns = $patterns;
        return $this;
    }

    /**
     * Get the current patterns configuration.
     *
     * @return array<string,string>
     */
    public function getPatterns(): array
    {
        return $this->patterns;
    }

    /**
     * Start with default GDPR patterns.
     */
    public function withDefaultPatterns(): self
    {
        $this->patterns = array_merge($this->patterns, DefaultPatterns::get());
        return $this;
    }
}
