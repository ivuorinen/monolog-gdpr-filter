<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Contracts;

/**
 * Interface for dot-notation array access.
 *
 * This abstraction allows swapping the underlying implementation
 * (e.g., Adbar\Dot) without modifying consuming code.
 *
 * @api
 */
interface ArrayAccessorInterface
{
    /**
     * Check if a key exists using dot notation.
     *
     * @param string $path Dot-notation path (e.g., "user.email")
     */
    public function has(string $path): bool;

    /**
     * Get a value using dot notation.
     *
     * @param string $path Dot-notation path (e.g., "user.email")
     * @param mixed $default Default value if path doesn't exist
     * @return mixed The value at the path or default
     */
    public function get(string $path, mixed $default = null): mixed;

    /**
     * Set a value using dot notation.
     *
     * @param string $path Dot-notation path (e.g., "user.email")
     * @param mixed $value Value to set
     */
    public function set(string $path, mixed $value): void;

    /**
     * Delete a value using dot notation.
     *
     * @param string $path Dot-notation path (e.g., "user.email")
     */
    public function delete(string $path): void;

    /**
     * Get all data as an array.
     *
     * @return array<string, mixed> The complete data array
     */
    public function all(): array;
}
