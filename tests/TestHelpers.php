<?php

/**
 * @noinspection GrazieInspection
 * @noinspection PhpMultipleClassDeclarationsInspection
 */

declare(strict_types=1);

namespace Tests;

use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\DefaultPatterns;
use Ivuorinen\MonologGdprFilter\MaskConstants;
use Ivuorinen\MonologGdprFilter\RateLimiter;
use Ivuorinen\MonologGdprFilter\PatternValidator;
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
    public const MASKED_EMAIL = MaskConstants::MASK_EMAIL;
    public const MASKED_SECRET = MaskConstants::MASK_MASKED;
    public const USER_REGISTERED = 'User registered';

    private const INVALID_REGEX = '/[invalid/';
    // ]'/' this should fix the issue with the regex breaking highlighting in the test

    // Additional test data constants
    public const TEST_US_SSN = '123-45-6789';
    public const TEST_CREDIT_CARD_FORMATTED = '1234-5678-9012-3456';
    public const TEST_PHONE_US = '+1-555-123-4567';
    public const TEST_PHONE_INTL = '+358 40 1234567';
    public const TEST_IP_ADDRESS = '192.168.1.1';
    public const TEST_IBAN = 'FI2112345600000785';
    public const TEST_IBAN_FORMATTED = 'FI21 1234 5600 0007 85';
    public const TEST_MAC_ADDRESS = '00:1A:2B:3C:4D:5E';
    public const TEST_BEARER_TOKEN = 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9';
    public const TEST_API_KEY = 'sk_test_4eC39HqLyjWDarj';
    public const TEST_PASSPORT = 'A123456';
    public const TEST_DOB = '1990-12-31';

    /**
     * @source \Monolog\LogRecord::__construct
     * @param array<mixed> $context
     * @param array<mixed> $extra
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

    /**
     * Create a LogRecord with simplified parameters.
     *
     * @param array<mixed> $context
     * @param array<mixed> $extra
     */
    protected function createLogRecord(
        string $message = 'Test message',
        array $context = [],
        Level $level = Level::Info,
        string $channel = 'test',
        ?DateTimeImmutable $datetime = null,
        array $extra = []
    ): LogRecord {
        return new LogRecord(
            datetime: $datetime ?? new DateTimeImmutable(),
            channel: $channel,
            level: $level,
            message: $message,
            context: $context,
            extra: $extra
        );
    }

    /**
     * Create a GdprProcessor with common defaults.
     *
     * @param array<string, string> $patterns
     * @param array<string, \Ivuorinen\MonologGdprFilter\FieldMaskConfig|string> $fieldPaths
     * @param array<string, callable> $customCallbacks
     * @param array<string, string> $dataTypeMasks
     * @param array<string, callable> $conditionalRules
     */
    protected function createProcessor(
        array $patterns = [],
        array $fieldPaths = [],
        array $customCallbacks = [],
        ?callable $auditLogger = null,
        int $maxDepth = 100,
        array $dataTypeMasks = [],
        array $conditionalRules = []
    ): GdprProcessor {
        return new GdprProcessor(
            $patterns,
            $fieldPaths,
            $customCallbacks,
            $auditLogger,
            $maxDepth,
            $dataTypeMasks,
            $conditionalRules
        );
    }

    /**
     * Create a GdprProcessor with default patterns.
     *
     * @param array<string, \Ivuorinen\MonologGdprFilter\FieldMaskConfig|string> $fieldPaths
     * @param array<string, callable> $customCallbacks
     */
    protected function createProcessorWithDefaults(
        array $fieldPaths = [],
        array $customCallbacks = []
    ): GdprProcessor {
        return new GdprProcessor(
            DefaultPatterns::get(),
            $fieldPaths,
            $customCallbacks
        );
    }

    /**
     * Create an audit logger that stores calls in an array.
     *
     * @param array<array{path: string, original: mixed, masked: mixed}> $storage
     *
     * @psalm-return \Closure(string, mixed, mixed):void
     */
    protected function createAuditLogger(array &$storage): \Closure
    {
        return function (string $path, mixed $original, mixed $masked) use (&$storage): void {
            $storage[] = [
                'path' => $path,
                'original' => $original,
                'masked' => $masked,
            ];
        };
    }

    /**
     * Clear RateLimiter state for clean tests.
     */
    protected function clearRateLimiter(): void
    {
        RateLimiter::clearAll();
    }

    /**
     * Clear PatternValidator cache for clean tests.
     */
    protected function clearPatternCache(): void
    {
        PatternValidator::clearCache();
    }

    /**
     * Get common test pattern for email masking.
     *
     * @return array<string, string>
     */
    protected function getEmailPattern(): array
    {
        return ['/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '***EMAIL***'];
    }

    /**
     * Get common test pattern for SSN masking.
     *
     * @return array<string, string>
     */
    protected function getSsnPattern(): array
    {
        return ['/\b\d{3}-\d{2}-\d{4}\b/' => '***SSN***'];
    }

    /**
     * Get common test pattern for credit card masking.
     *
     * @return array<string, string>
     */
    protected function getCreditCardPattern(): array
    {
        return ['/\b\d{4}[-\s]?\d{4}[-\s]?\d{4}[-\s]?\d{4}\b/' => '***CARD***'];
    }

    /**
     * Get all common test patterns.
     *
     * @return string[]
     *
     * @psalm-return array{'/b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+.[A-Z|a-z]{2,}b/': '***EMAIL***', '/bd{3}-d{2}-d{4}b/': '***SSN***', '/bd{4}[-s]?d{4}[-s]?d{4}[-s]?d{4}b/': '***CARD***'}
     */
    protected function getCommonPatterns(): array
    {
        return array_merge(
            $this->getEmailPattern(),
            $this->getSsnPattern(),
            $this->getCreditCardPattern()
        );
    }

    /**
     * Assert that a message contains masked value and not original.
     */
    protected function assertMasked(
        string $maskedValue,
        string $originalValue,
        string $actualMessage
    ): void {
        $this->assertStringContainsString($maskedValue, $actualMessage);
        $this->assertStringNotContainsString($originalValue, $actualMessage);
    }

    /**
     * Measure execution time and memory of a callable.
     *
     * @return array{duration_ms: float, memory_kb: float, result: mixed}
     */
    protected function measurePerformance(callable $callable): array
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        $result = $callable();

        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        return [
            'duration_ms' => ($endTime - $startTime) * 1000,
            'memory_kb' => ($endMemory - $startMemory) / 1024,
            'result' => $result,
        ];
    }
}
