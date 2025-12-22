<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Anonymization;

/**
 * K-Anonymity implementation for GDPR compliance.
 *
 * K-anonymity is a privacy model ensuring that each record in a dataset
 * is indistinguishable from at least k-1 other records with respect to
 * certain identifying attributes (quasi-identifiers).
 *
 * Common use cases:
 * - Age generalization (25 -> "20-29")
 * - Location generalization (specific address -> region)
 * - Date generalization (specific date -> month/year)
 *
 * @api
 */
final class KAnonymizer
{
    /**
     * @var array<string,GeneralizationStrategy>
     */
    private array $strategies = [];

    /**
     * @var callable(string,mixed,mixed):void|null
     */
    private $auditLogger;

    /**
     * @param callable(string,mixed,mixed):void|null $auditLogger Optional audit logger
     */
    public function __construct(?callable $auditLogger = null)
    {
        $this->auditLogger = $auditLogger;
    }

    /**
     * Register a generalization strategy for a field.
     */
    public function registerStrategy(string $field, GeneralizationStrategy $strategy): self
    {
        $this->strategies[$field] = $strategy;
        return $this;
    }

    /**
     * Register an age generalization strategy.
     *
     * @param int $rangeSize Size of age ranges (e.g., 10 for 20-29, 30-39)
     */
    public function registerAgeStrategy(string $field, int $rangeSize = 10): self
    {
        $this->strategies[$field] = new GeneralizationStrategy(
            static function (mixed $value) use ($rangeSize): string {
                $age = (int) $value;
                $lowerBound = (int) floor($age / $rangeSize) * $rangeSize;
                $upperBound = $lowerBound + $rangeSize - 1;
                return "{$lowerBound}-{$upperBound}";
            },
            'age'
        );
        return $this;
    }

    /**
     * Register a date generalization strategy.
     *
     * @param string $precision 'year', 'month', 'quarter'
     */
    public function registerDateStrategy(string $field, string $precision = 'month'): self
    {
        $this->strategies[$field] = new GeneralizationStrategy(
            static function (mixed $value) use ($precision): string {
                if (!$value instanceof \DateTimeInterface) {
                    $value = new \DateTimeImmutable((string) $value);
                }

                return match ($precision) {
                    'year' => $value->format('Y'),
                    'quarter' => $value->format('Y') . '-Q' . (int) ceil((int) $value->format('n') / 3),
                    default => $value->format('Y-m'),
                };
            },
            'date'
        );
        return $this;
    }

    /**
     * Register a location/ZIP code generalization strategy.
     *
     * @param int $prefixLength Number of characters to keep
     */
    public function registerLocationStrategy(string $field, int $prefixLength = 3): self
    {
        $this->strategies[$field] = new GeneralizationStrategy(
            static function (mixed $value) use ($prefixLength): string {
                $value = (string) $value;
                if (strlen($value) <= $prefixLength) {
                    return $value;
                }
                return substr($value, 0, $prefixLength) . str_repeat('*', strlen($value) - $prefixLength);
            },
            'location'
        );
        return $this;
    }

    /**
     * Register a numeric range generalization strategy.
     *
     * @param int $rangeSize Size of numeric ranges
     */
    public function registerNumericRangeStrategy(string $field, int $rangeSize = 10): self
    {
        $this->strategies[$field] = new GeneralizationStrategy(
            static function (mixed $value) use ($rangeSize): string {
                $num = (int) $value;
                $lowerBound = (int) floor($num / $rangeSize) * $rangeSize;
                $upperBound = $lowerBound + $rangeSize - 1;
                return "{$lowerBound}-{$upperBound}";
            },
            'numeric_range'
        );
        return $this;
    }

    /**
     * Register a custom generalization strategy.
     *
     * @param callable(mixed):string $generalizer
     */
    public function registerCustomStrategy(string $field, callable $generalizer): self
    {
        $this->strategies[$field] = new GeneralizationStrategy($generalizer, 'custom');
        return $this;
    }

    /**
     * Anonymize a single record.
     *
     * @param array<string,mixed> $record The record to anonymize
     * @return array<string,mixed> The anonymized record
     */
    public function anonymize(array $record): array
    {
        foreach ($this->strategies as $field => $strategy) {
            if (isset($record[$field])) {
                $original = $record[$field];
                $record[$field] = $strategy->generalize($original);

                if ($this->auditLogger !== null && $record[$field] !== $original) {
                    ($this->auditLogger)(
                        "k-anonymity.{$field}",
                        $original,
                        $record[$field]
                    );
                }
            }
        }

        return $record;
    }

    /**
     * Anonymize a batch of records.
     *
     * @param list<array<string,mixed>> $records
     * @return list<array<string,mixed>>
     */
    public function anonymizeBatch(array $records): array
    {
        return array_map($this->anonymize(...), $records);
    }

    /**
     * Get registered strategies.
     *
     * @return array<string,GeneralizationStrategy>
     */
    public function getStrategies(): array
    {
        return $this->strategies;
    }

    /**
     * Set the audit logger.
     *
     * @param callable(string,mixed,mixed):void|null $auditLogger
     */
    public function setAuditLogger(?callable $auditLogger): void
    {
        $this->auditLogger = $auditLogger;
    }

    /**
     * Create a pre-configured anonymizer for common GDPR scenarios.
     */
    public static function createGdprDefault(?callable $auditLogger = null): self
    {
        return (new self($auditLogger))
            ->registerAgeStrategy('age')
            ->registerDateStrategy('birth_date', 'year')
            ->registerDateStrategy('created_at', 'month')
            ->registerLocationStrategy('zip_code', 3)
            ->registerLocationStrategy('postal_code', 3);
    }
}
