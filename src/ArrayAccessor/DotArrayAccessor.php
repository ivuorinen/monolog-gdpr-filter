<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\ArrayAccessor;

use Adbar\Dot;
use Ivuorinen\MonologGdprFilter\Contracts\ArrayAccessorInterface;

/**
 * ArrayAccessor implementation using Adbar\Dot library.
 *
 * This class wraps the Adbar\Dot library to implement ArrayAccessorInterface,
 * allowing the library to be swapped without affecting consuming code.
 *
 * @api
 */
final class DotArrayAccessor implements ArrayAccessorInterface
{
    /** @var Dot<array-key, mixed> */
    private readonly Dot $dot;

    /**
     * @param array<string, mixed> $data Initial data array
     */
    public function __construct(array $data = [])
    {
        $this->dot = new Dot($data);
    }

    /**
     * Create accessor from an existing array.
     *
     * @param array<string, mixed> $data Data array
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    #[\Override]
    public function has(string $path): bool
    {
        return $this->dot->has($path);
    }

    #[\Override]
    public function get(string $path, mixed $default = null): mixed
    {
        return $this->dot->get($path, $default);
    }

    #[\Override]
    public function set(string $path, mixed $value): void
    {
        $this->dot->set($path, $value);
    }

    #[\Override]
    public function delete(string $path): void
    {
        $this->dot->delete($path);
    }

    #[\Override]
    public function all(): array
    {
        return $this->dot->all();
    }

    /**
     * Get the underlying Dot instance for advanced operations.
     *
     * @return Dot<array-key, mixed>
     */
    public function getDot(): Dot
    {
        return $this->dot;
    }
}
