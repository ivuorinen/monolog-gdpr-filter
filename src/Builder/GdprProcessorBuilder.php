<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Builder;

use Ivuorinen\MonologGdprFilter\ArrayAccessor\ArrayAccessorFactory;
use Ivuorinen\MonologGdprFilter\Contracts\MaskingPluginInterface;
use Ivuorinen\MonologGdprFilter\DefaultPatterns;
use Ivuorinen\MonologGdprFilter\FieldMaskConfig;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Monolog\LogRecord;

/**
 * Fluent builder for GdprProcessor configuration.
 *
 * Provides a clean, chainable API for configuring GdprProcessor instances
 * with support for plugins, patterns, field paths, and callbacks.
 *
 * @api
 */
final class GdprProcessorBuilder
{
    /**
     * @var array<string,string>
     */
    private array $patterns = [];

    /**
     * @var array<string,FieldMaskConfig|string>
     */
    private array $fieldPaths = [];

    /**
     * @var array<string,callable(mixed):string>
     */
    private array $customCallbacks = [];

    /**
     * @var callable(string,mixed,mixed):void|null
     */
    private $auditLogger = null;

    private int $maxDepth = 100;

    /**
     * @var array<string,string>
     */
    private array $dataTypeMasks = [];

    /**
     * @var array<string,callable(LogRecord):bool>
     */
    private array $conditionalRules = [];

    private ?ArrayAccessorFactory $arrayAccessorFactory = null;

    /**
     * @var list<MaskingPluginInterface>
     */
    private array $plugins = [];

    /**
     * Create a new builder instance.
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Start with default GDPR patterns.
     */
    public function withDefaultPatterns(): self
    {
        $this->patterns = array_merge($this->patterns, DefaultPatterns::get());
        return $this;
    }

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
     * Add a custom callback for a field path.
     *
     * @param string $path Dot-notation path
     * @param callable(mixed):string $callback Transformation callback
     */
    public function addCallback(string $path, callable $callback): self
    {
        $this->customCallbacks[$path] = $callback;
        return $this;
    }

    /**
     * Add multiple custom callbacks.
     *
     * @param array<string,callable(mixed):string> $callbacks Path => callback
     */
    public function addCallbacks(array $callbacks): self
    {
        $this->customCallbacks = array_merge($this->customCallbacks, $callbacks);
        return $this;
    }

    /**
     * Set the audit logger.
     *
     * @param callable(string,mixed,mixed):void $auditLogger Audit logger callback
     */
    public function withAuditLogger(callable $auditLogger): self
    {
        $this->auditLogger = $auditLogger;
        return $this;
    }

    /**
     * Set the maximum recursion depth.
     */
    public function withMaxDepth(int $maxDepth): self
    {
        $this->maxDepth = $maxDepth;
        return $this;
    }

    /**
     * Add a data type mask.
     *
     * @param string $type Data type (e.g., 'integer', 'double', 'boolean')
     * @param string $mask Replacement mask
     */
    public function addDataTypeMask(string $type, string $mask): self
    {
        $this->dataTypeMasks[$type] = $mask;
        return $this;
    }

    /**
     * Add multiple data type masks.
     *
     * @param array<string,string> $masks Type => mask
     */
    public function addDataTypeMasks(array $masks): self
    {
        $this->dataTypeMasks = array_merge($this->dataTypeMasks, $masks);
        return $this;
    }

    /**
     * Add a conditional masking rule.
     *
     * @param string $name Rule name
     * @param callable(LogRecord):bool $condition Condition callback
     */
    public function addConditionalRule(string $name, callable $condition): self
    {
        $this->conditionalRules[$name] = $condition;
        return $this;
    }

    /**
     * Add multiple conditional rules.
     *
     * @param array<string,callable(LogRecord):bool> $rules Name => condition
     */
    public function addConditionalRules(array $rules): self
    {
        $this->conditionalRules = array_merge($this->conditionalRules, $rules);
        return $this;
    }

    /**
     * Set the array accessor factory.
     */
    public function withArrayAccessorFactory(ArrayAccessorFactory $factory): self
    {
        $this->arrayAccessorFactory = $factory;
        return $this;
    }

    /**
     * Register a masking plugin.
     */
    public function addPlugin(MaskingPluginInterface $plugin): self
    {
        $this->plugins[] = $plugin;
        return $this;
    }

    /**
     * Register multiple masking plugins.
     *
     * @param list<MaskingPluginInterface> $plugins
     */
    public function addPlugins(array $plugins): self
    {
        foreach ($plugins as $plugin) {
            $this->plugins[] = $plugin;
        }
        return $this;
    }

    /**
     * Build the GdprProcessor with all configured options.
     *
     * @throws \InvalidArgumentException When configuration is invalid
     */
    public function build(): GdprProcessor
    {
        // Apply plugin configurations
        $this->applyPluginConfigurations();

        return new GdprProcessor(
            $this->patterns,
            $this->fieldPaths,
            $this->customCallbacks,
            $this->auditLogger,
            $this->maxDepth,
            $this->dataTypeMasks,
            $this->conditionalRules,
            $this->arrayAccessorFactory
        );
    }

    /**
     * Build a GdprProcessor wrapped with plugin hooks.
     *
     * Returns a PluginAwareProcessor if plugins are registered,
     * otherwise returns a standard GdprProcessor.
     *
     * @throws \InvalidArgumentException When configuration is invalid
     */
    public function buildWithPlugins(): GdprProcessor|PluginAwareProcessor
    {
        $processor = $this->build();

        if ($this->plugins === []) {
            return $processor;
        }

        // Sort plugins by priority
        usort($this->plugins, fn($a, $b): int => $a->getPriority() <=> $b->getPriority());

        return new PluginAwareProcessor($processor, $this->plugins);
    }

    /**
     * Apply plugin patterns and field paths to the builder configuration.
     */
    private function applyPluginConfigurations(): void
    {
        // Sort plugins by priority before applying
        usort($this->plugins, fn($a, $b): int => $a->getPriority() <=> $b->getPriority());

        foreach ($this->plugins as $plugin) {
            $this->patterns = array_merge($this->patterns, $plugin->getPatterns());
            $this->fieldPaths = array_merge($this->fieldPaths, $plugin->getFieldPaths());
        }
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
     * Get the current field paths configuration.
     *
     * @return array<string,FieldMaskConfig|string>
     */
    public function getFieldPaths(): array
    {
        return $this->fieldPaths;
    }

    /**
     * Get registered plugins.
     *
     * @return list<MaskingPluginInterface>
     */
    public function getPlugins(): array
    {
        return $this->plugins;
    }
}
