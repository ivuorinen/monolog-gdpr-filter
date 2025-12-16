<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Strategies;

use Ivuorinen\MonologGdprFilter\Exceptions\MaskingOperationFailedException;
use Monolog\LogRecord;
use Throwable;

/**
 * Masking strategy that uses custom callbacks for field-specific masking.
 *
 * This strategy allows wrapping legacy custom callback functions as proper
 * strategy implementations, enabling gradual migration to the strategy pattern.
 *
 * @api
 */
final class CallbackMaskingStrategy extends AbstractMaskingStrategy
{
    /** @var callable(mixed): mixed */
    private $callback;

    /**
     * @param string $fieldPath The field path this callback applies to
     * @param callable(mixed): mixed $callback The masking callback function
     * @param int $priority Strategy priority (default: 50)
     * @param bool $exactMatch Whether to require exact path match (default: true)
     */
    public function __construct(
        private readonly string $fieldPath,
        callable $callback,
        int $priority = 50,
        private readonly bool $exactMatch = true
    ) {
        parent::__construct($priority, [
            'field_path' => $fieldPath,
            'exact_match' => $exactMatch,
        ]);

        $this->callback = $callback;
    }

    /**
     * Create a strategy for multiple field paths with the same callback.
     *
     * @param array<string> $fieldPaths Array of field paths
     * @param callable(mixed): mixed $callback The masking callback
     * @param int $priority Strategy priority
     * @return array<self> Array of CallbackMaskingStrategy instances
     */
    public static function forPaths(
        array $fieldPaths,
        callable $callback,
        int $priority = 50
    ): array {
        return array_map(
            fn(string $path): self => new self($path, $callback, $priority),
            $fieldPaths
        );
    }

    /**
     * Create a strategy that always returns a constant value.
     *
     * @param string $fieldPath The field path
     * @param string $replacementValue The constant replacement value
     * @param int $priority Strategy priority
     */
    public static function constant(
        string $fieldPath,
        string $replacementValue,
        int $priority = 50
    ): self {
        return new self(
            $fieldPath,
            fn(mixed $value): string => $replacementValue,
            $priority
        );
    }

    /**
     * Create a strategy that hashes the original value.
     *
     * @param string $fieldPath The field path
     * @param string $algorithm Hash algorithm (default: 'sha256')
     * @param int $truncateLength Truncate hash to this length (0 = no truncation)
     * @param int $priority Strategy priority
     */
    public static function hash(
        string $fieldPath,
        string $algorithm = 'sha256',
        int $truncateLength = 8,
        int $priority = 50
    ): self {
        return new self(
            $fieldPath,
            function (mixed $value) use ($algorithm, $truncateLength): string {
                $stringValue = is_scalar($value) ? (string) $value : json_encode($value);
                $hash = hash($algorithm, $stringValue === false ? '' : $stringValue);
                return $truncateLength > 0
                    ? substr($hash, 0, $truncateLength) . '...'
                    : $hash;
            },
            $priority
        );
    }

    /**
     * Create a strategy that partially masks a value (e.g., email@****.com).
     *
     * @param string $fieldPath The field path
     * @param int $visibleStart Characters to show at start
     * @param int $visibleEnd Characters to show at end
     * @param string $maskChar Character to use for masking
     * @param int $priority Strategy priority
     */
    public static function partial(
        string $fieldPath,
        int $visibleStart = 2,
        int $visibleEnd = 2,
        string $maskChar = '*',
        int $priority = 50
    ): self {
        return new self(
            $fieldPath,
            function (mixed $value) use ($visibleStart, $visibleEnd, $maskChar): string {
                $str = is_scalar($value) ? (string) $value : '[OBJECT]';
                $len = strlen($str);

                if ($len <= $visibleStart + $visibleEnd) {
                    return str_repeat($maskChar, $len);
                }

                $start = substr($str, 0, $visibleStart);
                $end = substr($str, -$visibleEnd);
                $masked = str_repeat($maskChar, $len - $visibleStart - $visibleEnd);

                return $start . $masked . $end;
            },
            $priority
        );
    }

    #[\Override]
    public function mask(mixed $value, string $path, LogRecord $logRecord): mixed
    {
        try {
            return ($this->callback)($value);
        } catch (Throwable $e) {
            throw MaskingOperationFailedException::customCallbackFailed(
                $path,
                $value,
                'Callback threw exception: ' . $e->getMessage()
            );
        }
    }

    #[\Override]
    public function shouldApply(mixed $value, string $path, LogRecord $logRecord): bool
    {
        if ($this->exactMatch) {
            return $path === $this->fieldPath;
        }

        return $this->pathMatches($path, $this->fieldPath);
    }

    #[\Override]
    public function getName(): string
    {
        return sprintf('Callback Masking (%s)', $this->fieldPath);
    }

    #[\Override]
    public function validate(): bool
    {
        if ($this->fieldPath === '' || $this->fieldPath === '0') {
            return false;
        }

        return is_callable($this->callback);
    }

    /**
     * Get the field path this strategy applies to.
     */
    public function getFieldPath(): string
    {
        return $this->fieldPath;
    }

    /**
     * Check if this strategy uses exact matching.
     */
    public function isExactMatch(): bool
    {
        return $this->exactMatch;
    }

    #[\Override]
    public function getConfiguration(): array
    {
        return [
            'field_path' => $this->fieldPath,
            'exact_match' => $this->exactMatch,
            'priority' => $this->priority,
        ];
    }
}
