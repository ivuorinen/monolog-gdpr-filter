<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Recovery;

/**
 * Defines how the processor should behave when masking operations fail.
 *
 * @api
 */
enum FailureMode: string
{
    /**
     * Fail open: On failure, return the original value unmasked.
     *
     * Use this when availability is more important than privacy,
     * but be aware this may expose sensitive data in error scenarios.
     */
    case FAIL_OPEN = 'fail_open';

    /**
     * Fail closed: On failure, return a completely masked/redacted value.
     *
     * Use this when privacy is critical and you'd rather lose data
     * than risk exposing sensitive information.
     */
    case FAIL_CLOSED = 'fail_closed';

    /**
     * Fail safe: On failure, apply a conservative fallback mask.
     *
     * This is the recommended default. It attempts to provide useful
     * information while still protecting potentially sensitive data.
     */
    case FAIL_SAFE = 'fail_safe';

    /**
     * Get a human-readable description of this failure mode.
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::FAIL_OPEN => 'Return original value on failure (risky)',
            self::FAIL_CLOSED => 'Return fully redacted value on failure (strict)',
            self::FAIL_SAFE => 'Apply conservative fallback mask on failure (balanced)',
        };
    }

    /**
     * Get the recommended failure mode for production environments.
     */
    public static function recommended(): self
    {
        return self::FAIL_SAFE;
    }
}
