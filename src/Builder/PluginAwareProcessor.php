<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Builder;

use Ivuorinen\MonologGdprFilter\Contracts\MaskingPluginInterface;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Wrapper that adds plugin hook support to GdprProcessor.
 *
 * Executes plugin pre/post processing hooks around the standard
 * GdprProcessor masking operations.
 *
 * @api
 */
final class PluginAwareProcessor implements ProcessorInterface
{
    /**
     * @param GdprProcessor $processor The underlying processor
     * @param list<MaskingPluginInterface> $plugins Registered plugins (sorted by priority)
     */
    public function __construct(
        private readonly GdprProcessor $processor,
        private readonly array $plugins
    ) {
    }

    /**
     * Process a log record with plugin hooks.
     *
     * @param LogRecord $record The log record to process
     * @return LogRecord The processed log record
     */
    #[\Override]
    public function __invoke(LogRecord $record): LogRecord
    {
        // Pre-process message through plugins
        $message = $record->message;
        foreach ($this->plugins as $plugin) {
            $message = $plugin->preProcessMessage($message);
        }

        // Pre-process context through plugins
        $context = $record->context;
        foreach ($this->plugins as $plugin) {
            $context = $plugin->preProcessContext($context);
        }

        // Create modified record for main processor
        $modifiedRecord = $record->with(message: $message, context: $context);

        // Apply main processor
        $processedRecord = ($this->processor)($modifiedRecord);

        // Post-process message through plugins (reverse order)
        $message = $processedRecord->message;
        foreach (array_reverse($this->plugins) as $plugin) {
            $message = $plugin->postProcessMessage($message);
        }

        // Post-process context through plugins (reverse order)
        $context = $processedRecord->context;
        foreach (array_reverse($this->plugins) as $plugin) {
            $context = $plugin->postProcessContext($context);
        }

        return $processedRecord->with(message: $message, context: $context);
    }

    /**
     * Get the underlying GdprProcessor.
     */
    public function getProcessor(): GdprProcessor
    {
        return $this->processor;
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
     * Delegate regExpMessage to underlying processor.
     */
    public function regExpMessage(string $message = ''): string
    {
        return $this->processor->regExpMessage($message);
    }

    /**
     * Delegate recursiveMask to underlying processor.
     *
     * @param array<mixed>|string $data
     * @param int $currentDepth
     * @return array<mixed>|string
     */
    public function recursiveMask(array|string $data, int $currentDepth = 0): array|string
    {
        return $this->processor->recursiveMask($data, $currentDepth);
    }

    /**
     * Delegate setAuditLogger to underlying processor.
     *
     * @param callable(string,mixed,mixed):void|null $auditLogger
     */
    public function setAuditLogger(?callable $auditLogger): void
    {
        $this->processor->setAuditLogger($auditLogger);
    }
}
