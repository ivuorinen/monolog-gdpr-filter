<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Plugins;

use Ivuorinen\MonologGdprFilter\Contracts\MaskingPluginInterface;

/**
 * Abstract base class for masking plugins.
 *
 * Provides default no-op implementations for all plugin methods,
 * allowing plugins to override only the methods they need.
 *
 * @api
 */
abstract class AbstractMaskingPlugin implements MaskingPluginInterface
{
    /**
     * @param int $priority Plugin priority (lower = earlier execution, default: 100)
     */
    public function __construct(
        protected readonly int $priority = 100
    ) {
    }

    /**
     * @inheritDoc
     */
    public function preProcessContext(array $context): array
    {
        return $context;
    }

    /**
     * @inheritDoc
     */
    public function postProcessContext(array $context): array
    {
        return $context;
    }

    /**
     * @inheritDoc
     */
    public function preProcessMessage(string $message): string
    {
        return $message;
    }

    /**
     * @inheritDoc
     */
    public function postProcessMessage(string $message): string
    {
        return $message;
    }

    /**
     * @inheritDoc
     */
    public function getPatterns(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getFieldPaths(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getPriority(): int
    {
        return $this->priority;
    }
}
