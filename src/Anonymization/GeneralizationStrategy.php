<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Anonymization;

/**
 * Represents a generalization strategy for k-anonymity.
 *
 * @api
 */
final class GeneralizationStrategy
{
    /**
     * @var callable(mixed):string
     */
    private $generalizer;

    /**
     * @param callable(mixed):string $generalizer Function that generalizes a value
     * @param string $type Type identifier for the strategy
     */
    public function __construct(
        callable $generalizer,
        private readonly string $type = 'custom'
    ) {
        $this->generalizer = $generalizer;
    }

    /**
     * Apply the generalization to a value.
     *
     * @param mixed $value The value to generalize
     * @return string The generalized value
     */
    public function generalize(mixed $value): string
    {
        return ($this->generalizer)($value);
    }

    /**
     * Get the strategy type.
     */
    public function getType(): string
    {
        return $this->type;
    }
}
