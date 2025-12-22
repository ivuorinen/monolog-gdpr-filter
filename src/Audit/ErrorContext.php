<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Audit;

use Throwable;

/**
 * Standardized error information for audit logging.
 *
 * Captures error details in a structured format while ensuring
 * sensitive information is sanitized before logging.
 *
 * @api
 */
final readonly class ErrorContext
{
    /**
     * @param string $errorType The type/class of error that occurred
     * @param string $message Sanitized error message (sensitive data removed)
     * @param int $code Error code if available
     * @param string|null $file File where error occurred (optional)
     * @param int|null $line Line number where error occurred (optional)
     * @param array<string, mixed> $metadata Additional error metadata
     */
    public function __construct(
        public string $errorType,
        public string $message,
        public int $code = 0,
        public ?string $file = null,
        public ?int $line = null,
        public array $metadata = [],
    ) {
    }

    /**
     * Create an ErrorContext from a Throwable.
     *
     * @param Throwable $throwable The exception/error to capture
     * @param bool $includeSensitive Whether to include potentially sensitive details
     */
    public static function fromThrowable(
        Throwable $throwable,
        bool $includeSensitive = false
    ): self {
        $message = $includeSensitive
            ? $throwable->getMessage()
            : self::sanitizeMessage($throwable->getMessage());

        $metadata = [];
        if ($includeSensitive) {
            $metadata['trace'] = array_slice($throwable->getTrace(), 0, 5);
        }

        return new self(
            errorType: $throwable::class,
            message: $message,
            code: (int) $throwable->getCode(),
            file: $includeSensitive ? $throwable->getFile() : null,
            line: $includeSensitive ? $throwable->getLine() : null,
            metadata: $metadata,
        );
    }

    /**
     * Create an ErrorContext for a generic error.
     *
     * @param string $errorType The type of error
     * @param string $message The error message
     * @param array<string, mixed> $metadata Additional context
     */
    public static function create(
        string $errorType,
        string $message,
        array $metadata = []
    ): self {
        return new self(
            errorType: $errorType,
            message: self::sanitizeMessage($message),
            metadata: $metadata,
        );
    }

    /**
     * Sanitize an error message to remove potentially sensitive information.
     *
     * @param string $message The original error message
     */
    private static function sanitizeMessage(string $message): string
    {
        $patterns = [
            // Passwords and secrets
            '/password[=:]\s*[^\s,;]+/i' => 'password=[REDACTED]',
            '/secret[=:]\s*[^\s,;]+/i' => 'secret=[REDACTED]',
            '/api[_-]?key[=:]\s*[^\s,;]+/i' => 'api_key=[REDACTED]',
            '/token[=:]\s*[^\s,;]+/i' => 'token=[REDACTED]',
            '/bearer\s+\S+/i' => 'bearer [REDACTED]',

            // Connection strings
            '/:[^@]+@/' => ':[REDACTED]@',
            '/user[=:]\s*[^\s,;@]+/i' => 'user=[REDACTED]',
            '/host[=:]\s*[^\s,;]+/i' => 'host=[REDACTED]',

            // File paths (partial - keep filename)
            '/\/(?:var|home|etc|usr|opt)\/[^\s:]+/' => '/[PATH_REDACTED]',
        ];

        $sanitized = $message;
        foreach ($patterns as $pattern => $replacement) {
            $result = preg_replace($pattern, $replacement, $sanitized);
            if ($result !== null) {
                $sanitized = $result;
            }
        }

        return $sanitized;
    }

    /**
     * Convert to array for serialization/logging.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'error_type' => $this->errorType,
            'message' => $this->message,
            'code' => $this->code,
        ];

        if ($this->file !== null) {
            $data['file'] = $this->file;
        }

        if ($this->line !== null) {
            $data['line'] = $this->line;
        }

        if ($this->metadata !== []) {
            $data['metadata'] = $this->metadata;
        }

        return $data;
    }
}
