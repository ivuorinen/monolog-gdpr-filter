<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Contracts;

/**
 * Interface for masking plugins that can extend GdprProcessor functionality.
 *
 * Plugins can hook into the masking process at various points to add
 * custom masking logic, transformations, or integrations.
 *
 * @api
 */
interface MaskingPluginInterface
{
    /**
     * Get the unique plugin identifier.
     */
    public function getName(): string;

    /**
     * Process context data before standard masking is applied.
     *
     * @param array<string,mixed> $context The context data
     * @return array<string,mixed> The modified context data
     */
    public function preProcessContext(array $context): array;

    /**
     * Process context data after standard masking is applied.
     *
     * @param array<string,mixed> $context The masked context data
     * @return array<string,mixed> The modified context data
     */
    public function postProcessContext(array $context): array;

    /**
     * Process message before standard masking is applied.
     *
     * @param string $message The original message
     * @return string The modified message
     */
    public function preProcessMessage(string $message): string;

    /**
     * Process message after standard masking is applied.
     *
     * @param string $message The masked message
     * @return string The modified message
     */
    public function postProcessMessage(string $message): string;

    /**
     * Get additional patterns to add to the processor.
     *
     * @return array<string,string> Regex pattern => replacement
     */
    public function getPatterns(): array;

    /**
     * Get additional field paths to mask.
     *
     * @return array<string,\Ivuorinen\MonologGdprFilter\FieldMaskConfig|string>
     */
    public function getFieldPaths(): array;

    /**
     * Get the plugin's priority (lower = earlier execution).
     */
    public function getPriority(): int;
}
