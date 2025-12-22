<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Builder\Traits;

use Ivuorinen\MonologGdprFilter\FieldMaskConfig;

/**
 * Provides field path configuration methods for GdprProcessorBuilder.
 *
 * Handles field path management for masking specific fields in log context
 * using dot notation (e.g., "user.email").
 */
trait FieldPathConfigurationTrait
{
    /**
     * @var array<string,FieldMaskConfig|string>
     */
    private array $fieldPaths = [];

    /**
     * Add a field path to mask.
     *
     * @param string $path Dot-notation path
     * @param FieldMaskConfig|string $config Mask configuration or replacement string
     */
    public function addFieldPath(string $path, FieldMaskConfig|string $config): self
    {
        $this->fieldPaths[$path] = $config;
        return $this;
    }

    /**
     * Add multiple field paths.
     *
     * @param array<string,FieldMaskConfig|string> $fieldPaths Path => config
     */
    public function addFieldPaths(array $fieldPaths): self
    {
        $this->fieldPaths = array_merge($this->fieldPaths, $fieldPaths);
        return $this;
    }

    /**
     * Set all field paths (replaces existing).
     *
     * @param array<string,FieldMaskConfig|string> $fieldPaths Path => config
     */
    public function setFieldPaths(array $fieldPaths): self
    {
        $this->fieldPaths = $fieldPaths;
        return $this;
    }

    /**
     * Get the current field paths configuration.
     *
     * @return array<string,FieldMaskConfig|string>
     */
    public function getFieldPaths(): array
    {
        return $this->fieldPaths;
    }
}
