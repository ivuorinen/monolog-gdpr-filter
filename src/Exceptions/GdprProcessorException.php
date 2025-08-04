<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter\Exceptions;

use Throwable;
use Exception;

/**
 * Base exception class for all GDPR processor related errors.
 *
 * This serves as the parent class for all specific GDPR processing exceptions,
 * allowing consumers to catch all GDPR-related errors with a single catch block.
 *
 * @api
 */
class GdprProcessorException extends Exception
{
    /**
     * Create a new GDPR processor exception.
     *
     * @param string $message The exception message
     * @param int $code The exception code (default: 0)
     * @param Throwable|null $previous Previous exception for chaining
     */
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Create an exception with additional context information.
     *
     * @param string $message The base exception message
     * @param array<string, mixed> $context Additional context data
     * @param int $code The exception code (default: 0)
     * @param Throwable|null $previous Previous exception for chaining
     */
    public static function withContext(
        string $message,
        array $context,
        int $code = 0,
        ?Throwable $previous = null
    ): static {
        $contextString = '';
        if ($context !== []) {
            $contextParts = [];
            foreach ($context as $key => $value) {
                $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
                $contextParts[] = $key . ': ' . ($encoded === false ? '[unserializable]' : $encoded);
            }

            $contextString = ' [Context: ' . implode(', ', $contextParts) . ']';
        }

        /** @psalm-suppress UnsafeInstantiation */
        return new static($message . $contextString, $code, $previous);
    }
}
