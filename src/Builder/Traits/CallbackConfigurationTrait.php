<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Builder\Traits;

use Monolog\LogRecord;

/**
 * Provides callback configuration methods for GdprProcessorBuilder.
 *
 * Handles custom callbacks, data type masks, and conditional masking rules
 * for advanced masking scenarios.
 */
trait CallbackConfigurationTrait
{
    /**
     * @var array<string,callable(mixed):string>
     */
    private array $customCallbacks = [];

    /**
     * @var array<string,string>
     */
    private array $dataTypeMasks = [];

    /**
     * @var array<string,callable(LogRecord):bool>
     */
    private array $conditionalRules = [];

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
}
