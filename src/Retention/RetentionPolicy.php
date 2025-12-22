<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Retention;

/**
 * Data retention policy configuration for GDPR compliance.
 *
 * Defines how long different types of data should be retained
 * and what actions to take when the retention period expires.
 *
 * @api
 */
final class RetentionPolicy
{
    public const ACTION_DELETE = 'delete';
    public const ACTION_ANONYMIZE = 'anonymize';
    public const ACTION_ARCHIVE = 'archive';

    /**
     * @param string $name Policy name
     * @param int $retentionDays Number of days to retain data
     * @param string $action Action to take when retention expires
     * @param list<string> $fields Fields this policy applies to (empty = all fields)
     */
    public function __construct(
        private readonly string $name,
        private readonly int $retentionDays,
        private readonly string $action = self::ACTION_DELETE,
        private readonly array $fields = []
    ) {
    }

    /**
     * Get the policy name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the retention period in days.
     */
    public function getRetentionDays(): int
    {
        return $this->retentionDays;
    }

    /**
     * Get the expiration action.
     */
    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * Get fields this policy applies to.
     *
     * @return list<string>
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * Check if a date is within the retention period.
     */
    public function isWithinRetention(\DateTimeInterface $date): bool
    {
        $now = new \DateTimeImmutable();
        $cutoff = $now->modify("-{$this->retentionDays} days");

        return $date >= $cutoff;
    }

    /**
     * Get the retention cutoff date.
     */
    public function getCutoffDate(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->modify("-{$this->retentionDays} days");
    }

    /**
     * Create a GDPR standard 30-day retention policy.
     */
    public static function gdpr30Days(string $name = 'gdpr_standard'): self
    {
        return new self($name, 30, self::ACTION_DELETE);
    }

    /**
     * Create a long-term archival policy (7 years).
     */
    public static function archival(string $name = 'archival'): self
    {
        return new self($name, 2555, self::ACTION_ARCHIVE); // ~7 years
    }

    /**
     * Create an anonymization policy.
     *
     * @param list<string> $fields Fields to anonymize
     */
    public static function anonymize(string $name, int $days, array $fields = []): self
    {
        return new self($name, $days, self::ACTION_ANONYMIZE, $fields);
    }
}
