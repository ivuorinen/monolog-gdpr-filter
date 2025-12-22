<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\ArrayAccessor;

use Ivuorinen\MonologGdprFilter\Contracts\ArrayAccessorInterface;

/**
 * Factory for creating ArrayAccessor instances.
 *
 * This factory allows dependency injection of the accessor creation logic,
 * enabling easy swapping of implementations for testing or alternative libraries.
 *
 * @api
 */
class ArrayAccessorFactory
{
    /**
     * @var class-string<ArrayAccessorInterface>|callable(array<string, mixed>): ArrayAccessorInterface
     */
    private $accessorClass;

    /**
     * @param class-string<ArrayAccessorInterface>|callable(array<string, mixed>): ArrayAccessorInterface|null $accessorClass
     */
    public function __construct(string|callable|null $accessorClass = null)
    {
        $this->accessorClass = $accessorClass ?? DotArrayAccessor::class;
    }

    /**
     * Create a new ArrayAccessor instance for the given data.
     *
     * @param array<string, mixed> $data Data array to wrap
     */
    public function create(array $data): ArrayAccessorInterface
    {
        if (is_callable($this->accessorClass)) {
            return ($this->accessorClass)($data);
        }

        $class = $this->accessorClass;

        return new $class($data);
    }

    /**
     * Create a factory with the default Dot implementation.
     */
    public static function default(): self
    {
        return new self(DotArrayAccessor::class);
    }

    /**
     * Create a factory with a custom accessor class.
     *
     * @param class-string<ArrayAccessorInterface> $accessorClass
     */
    public static function withClass(string $accessorClass): self
    {
        return new self($accessorClass);
    }

    /**
     * Create a factory with a custom callable.
     *
     * @param callable(array<string, mixed>): ArrayAccessorInterface $factory
     */
    public static function withCallable(callable $factory): self
    {
        return new self($factory);
    }
}
