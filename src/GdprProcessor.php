<?php

namespace Ivuorinen\MonologGdprFilter;

use Ivuorinen\MonologGdprFilter\ArrayAccessor\ArrayAccessorFactory;
use Ivuorinen\MonologGdprFilter\Exceptions\PatternValidationException;
use Ivuorinen\MonologGdprFilter\Exceptions\InvalidRegexPatternException;
use Ivuorinen\MonologGdprFilter\Factory\AuditLoggerFactory;
use Closure;
use Throwable;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * GdprProcessor is a Monolog processor that masks sensitive information in log messages
 * according to specified regex patterns and field paths.
 *
 * This class serves as a Monolog adapter, delegating actual masking work to MaskingOrchestrator.
 *
 * @psalm-api
 */
class GdprProcessor implements ProcessorInterface
{
    private readonly MaskingOrchestrator $orchestrator;

    /**
     * @var callable(string,mixed,mixed):void|null
     */
    private $auditLogger;

    /**
     * @param array<string,string> $patterns Regex pattern => replacement
     * @param array<string,FieldMaskConfig|string> $fieldPaths Dot-notation path => FieldMaskConfig
     * @param array<string,callable(mixed):string> $customCallbacks Dot-notation path => callback(value): string
     * @param callable(string,mixed,mixed):void|null $auditLogger Opt. audit logger callback:
     *                                   fn(string $path, mixed $original, mixed $masked)
     * @param int $maxDepth Maximum recursion depth for nested structures (default: 100)
     * @param array<string,string> $dataTypeMasks Type-based masking: type => mask pattern
     * @param array<string,callable(LogRecord):bool> $conditionalRules Conditional masking rules:
     *                                   rule_name => condition_callback
     * @param ArrayAccessorFactory|null $arrayAccessorFactory Factory for creating array accessors
     *
     * @throws \InvalidArgumentException When any parameter is invalid
     */
    public function __construct(
        private readonly array $patterns,
        array $fieldPaths = [],
        array $customCallbacks = [],
        $auditLogger = null,
        int $maxDepth = 100,
        array $dataTypeMasks = [],
        private readonly array $conditionalRules = [],
        ?ArrayAccessorFactory $arrayAccessorFactory = null
    ) {
        $this->auditLogger = $auditLogger;

        // Validate all constructor parameters using InputValidator
        InputValidator::validateAll(
            $patterns,
            $fieldPaths,
            $customCallbacks,
            $auditLogger,
            $maxDepth,
            $dataTypeMasks,
            $conditionalRules
        );

        // Pre-validate and cache patterns for better performance
        /** @psalm-suppress DeprecatedMethod - Internal use of caching mechanism */
        PatternValidator::cachePatterns($patterns);

        // Create orchestrator to handle actual masking work
        $this->orchestrator = new MaskingOrchestrator(
            $patterns,
            $fieldPaths,
            $customCallbacks,
            $auditLogger,
            $maxDepth,
            $dataTypeMasks,
            $arrayAccessorFactory
        );
    }

    /**
     * Create a rate-limited audit logger wrapper.
     *
     * @param callable(string,mixed,mixed):void $auditLogger The underlying audit logger
     * @param string $profile Rate limiting profile: 'strict', 'default', 'relaxed', or 'testing'
     *
     * @deprecated Use AuditLoggerFactory::create()->createRateLimited() instead
     */
    public static function createRateLimitedAuditLogger(
        callable $auditLogger,
        string $profile = 'default'
    ): RateLimitedAuditLogger {
        return AuditLoggerFactory::create()->createRateLimited($auditLogger, $profile);
    }

    /**
     * Create a simple audit logger that logs to an array (useful for testing).
     *
     * @param array<array-key, mixed> $logStorage Reference to array for storing logs
     * @psalm-param array<array{path: string, original: mixed, masked: mixed}> $logStorage
     * @psalm-param-out array<array{path: string, original: mixed, masked: mixed, timestamp: int<1, max>}> $logStorage
     * @phpstan-param-out array<array-key, mixed> $logStorage
     * @param bool $rateLimited Whether to apply rate limiting (default: false for testing)
     *
     * @psalm-return RateLimitedAuditLogger|Closure(string, mixed, mixed):void
     *
     * @deprecated Use AuditLoggerFactory::create()->createArrayLogger() instead
     */
    public static function createArrayAuditLogger(
        array &$logStorage,
        bool $rateLimited = false
    ): Closure|RateLimitedAuditLogger {
        return AuditLoggerFactory::create()->createArrayLogger($logStorage, $rateLimited);
    }

    /**
     * Process a log record to mask sensitive information.
     *
     * @param LogRecord $record The log record to process
     * @return LogRecord The processed log record with masked message and context
     */
    #[\Override]
    public function __invoke(LogRecord $record): LogRecord
    {
        // Check conditional rules first - if any rule returns false, skip masking
        if (!$this->shouldApplyMasking($record)) {
            return $record;
        }

        // Delegate to orchestrator
        $result = $this->orchestrator->process($record->message, $record->context);

        return $record->with(message: $result['message'], context: $result['context']);
    }

    /**
     * Check if masking should be applied based on conditional rules.
     */
    private function shouldApplyMasking(LogRecord $record): bool
    {
        // If no conditional rules are defined, always apply masking
        if ($this->conditionalRules === []) {
            return true;
        }

        // All conditional rules must return true for masking to be applied
        foreach ($this->conditionalRules as $ruleName => $ruleCallback) {
            try {
                if (!$ruleCallback($record)) {
                    // Log which rule prevented masking
                    if ($this->auditLogger !== null) {
                        ($this->auditLogger)(
                            'conditional_skip',
                            $ruleName,
                            'Masking skipped due to conditional rule'
                        );
                    }

                    return false;
                }
            } catch (Throwable $e) {
                // If a rule throws an exception, log it and default to applying masking
                if ($this->auditLogger !== null) {
                    $sanitized = SecuritySanitizer::sanitizeErrorMessage($e->getMessage());
                    $errorMsg = 'Rule error: ' . $sanitized;
                    ($this->auditLogger)('conditional_error', $ruleName, $errorMsg);
                }

                continue;
            }
        }

        return true;
    }

    /**
     * Mask a string using all regex patterns with optimized caching and batch processing.
     * Also handles JSON strings within the message.
     */
    public function regExpMessage(string $message = ''): string
    {
        return $this->orchestrator->regExpMessage($message);
    }

    /**
     * Recursively mask all string values in an array using regex patterns with depth limiting
     * and memory-efficient processing for large nested structures.
     *
     * @param array<mixed>|string $data
     * @param int $currentDepth Current recursion depth
     * @return array<mixed>|string
     */
    public function recursiveMask(array|string $data, int $currentDepth = 0): array|string
    {
        return $this->orchestrator->recursiveMask($data, $currentDepth);
    }

    /**
     * Mask a string using all regex patterns at once.
     */
    public function maskMessage(string $value = ''): string
    {
        $keys = array_keys($this->patterns);
        $values = array_values($this->patterns);

        try {
            /** @psalm-suppress ArgumentTypeCoercion */
            $result = preg_replace($keys, $values, $value);
            if ($result === null) {
                $error = preg_last_error_msg();
                if ($this->auditLogger !== null) {
                    ($this->auditLogger)('preg_replace_batch_error', $value, 'Error: ' . $error);
                }

                return $value;
            }

            return $result;
        } catch (\Error $error) {
            if ($this->auditLogger !== null) {
                ($this->auditLogger)('regex_batch_error', implode(', ', $keys), $error->getMessage());
            }

            return $value;
        }
    }

    /**
     * Set the audit logger callable.
     *
     * @param callable(string,mixed,mixed):void|null $auditLogger
     */
    public function setAuditLogger(?callable $auditLogger): void
    {
        $this->auditLogger = $auditLogger;
        $this->orchestrator->setAuditLogger($auditLogger);
    }

    /**
     * Get the underlying orchestrator for direct access.
     */
    public function getOrchestrator(): MaskingOrchestrator
    {
        return $this->orchestrator;
    }

    /**
     * Validate an array of patterns for security and syntax.
     *
     * @param array<string, string> $patterns Array of regex pattern => replacement
     *
     * @throws \Ivuorinen\MonologGdprFilter\Exceptions\PatternValidationException When patterns are invalid
     */
    public static function validatePatternsArray(array $patterns): void
    {
        try {
            /** @psalm-suppress DeprecatedMethod - Wrapper for deprecated validation */
            PatternValidator::validateAll($patterns);
        } catch (InvalidRegexPatternException $e) {
            throw PatternValidationException::forMultiplePatterns(
                ['validation_error' => $e->getMessage()],
                $e
            );
        }
    }

    /**
     * Get default GDPR regex patterns for common sensitive data types.
     *
     * @return array<string, string>
     */
    public static function getDefaultPatterns(): array
    {
        return DefaultPatterns::get();
    }
}
