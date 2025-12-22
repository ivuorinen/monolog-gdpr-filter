<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Builder\Traits;

use Ivuorinen\MonologGdprFilter\Contracts\MaskingPluginInterface;

/**
 * Provides plugin configuration methods for GdprProcessorBuilder.
 *
 * Handles registration and management of masking plugins that can extend
 * the processor's functionality with custom patterns and field paths.
 */
trait PluginConfigurationTrait
{
    /**
     * @var list<MaskingPluginInterface>
     */
    private array $plugins = [];

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
     * Get registered plugins.
     *
     * @return list<MaskingPluginInterface>
     */
    public function getPlugins(): array
    {
        return $this->plugins;
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
}
