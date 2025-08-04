<?php

/**
 * @noinspection GrazieInspection
 * @noinspection PhpMultipleClassDeclarationsInspection
 */

declare(strict_types=1);

namespace Tests;

use DateTimeImmutable;
use Monolog\JsonSerializableDateTimeImmutable;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use ReflectionMethod;
use Stringable;

trait TestHelpers
{
    private const GDPR_REPLACEMENT = '[GDPR]';

    private const TEST_HETU = '131052-308T';

    private const TEST_CC = '1234567812345678';

    public const TEST_EMAIL = 'john.doe@example.com';

    public const MASKED_EMAIL = '***EMAIL***';

    public const MASKED_SECRET = '***MASKED***';

    public const USER_REGISTERED = 'User registered';

    private const INVALID_REGEX = '/[invalid/';

    // ]'/' this should fix the issue with the regex breaking highlighting in the test
    /**
     * @source \Monolog\LogRecord::__construct
     */
    protected function logEntry(
        int|string|Level $level = Level::Warning,
        string|Stringable $message = "test",
        array $context = [],
        string $channel = "test",
        DateTimeImmutable $datetime = new JsonSerializableDateTimeImmutable(true),
        array $extra = [],
    ): LogRecord {
        return new LogRecord(
            datetime: $datetime,
            channel: $channel,
            level: Logger::toMonologLevel($level),
            message: (string) $message,
            context: $context,
            extra: $extra,
        );
    }

    protected function getReflection(
        object|string $object,
        string $methodName = '',
    ): ReflectionMethod {
        if (($methodName === '' || $methodName === '0') && is_string($object)) {
            $method = new ReflectionMethod($object);
        } else {
            $method = new ReflectionMethod($object, $methodName);
        }

        /**
         * @noinspection PhpExpressionResultUnusedInspection
         * @psalm-suppress UnusedMethodCall
         */
        $method->setAccessible(true);
        return $method;
    }

    /**
     * Returns a reflection of the given class.
     *
     * @api
     * @noinspection PhpUnused
     */
    protected function noOperation(): void
    {
        // This method intentionally left blank.
        // It can be used to indicate a no-operation in tests.
    }
}
