<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Builder;

use Ivuorinen\MonologGdprFilter\ArrayAccessor\ArrayAccessorFactory;
use Ivuorinen\MonologGdprFilter\Builder\Traits\CallbackConfigurationTrait;
use Ivuorinen\MonologGdprFilter\Builder\Traits\FieldPathConfigurationTrait;
use Ivuorinen\MonologGdprFilter\Builder\Traits\PatternConfigurationTrait;
use Ivuorinen\MonologGdprFilter\Builder\Traits\PluginConfigurationTrait;
use Ivuorinen\MonologGdprFilter\GdprProcessor;

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
    use PatternConfigurationTrait;
    use FieldPathConfigurationTrait;
    use CallbackConfigurationTrait;
    use PluginConfigurationTrait;

    /**
     * @var callable(string,mixed,mixed):void|null
     */
    private $auditLogger = null;

    private int $maxDepth = 100;

    private ?ArrayAccessorFactory $arrayAccessorFactory = null;

    /**
     * Create a new builder instance.
     */
    public static function create(): self
    {
        return new self();
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
     * Set the array accessor factory.
     */
    public function withArrayAccessorFactory(ArrayAccessorFactory $factory): self
    {
        $this->arrayAccessorFactory = $factory;
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
}
