<?php

namespace Ivuorinen\MonologGdprFilter;

use Ivuorinen\MonologGdprFilter\Exceptions\PatternValidationException;
use Ivuorinen\MonologGdprFilter\Exceptions\InvalidRegexPatternException;
use Closure;
use Throwable;
use Error;
use Adbar\Dot;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * GdprProcessor is a Monolog processor that masks sensitive information in log messages
 * according to specified regex patterns and field paths.
 *
 * @psalm-api
 */
class GdprProcessor implements ProcessorInterface
{
    private readonly DataTypeMasker $dataTypeMasker;
    private readonly JsonMasker $jsonMasker;
    private readonly ContextProcessor $contextProcessor;
    private readonly RecursiveProcessor $recursiveProcessor;

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
     *
     * @throws \InvalidArgumentException When any parameter is invalid
     */
    public function __construct(
        private readonly array $patterns,
        private readonly array $fieldPaths = [],
        private readonly array $customCallbacks = [],
        private $auditLogger = null,
        int $maxDepth = 100,
        array $dataTypeMasks = [],
        private readonly array $conditionalRules = []
    ) {
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
        PatternValidator::cachePatterns($patterns);

        // Initialize data type masker
        $this->dataTypeMasker = new DataTypeMasker($dataTypeMasks, $auditLogger);

        // Initialize recursive processor for data structure processing
        $this->recursiveProcessor = new RecursiveProcessor(
            $this->regExpMessage(...),
            $this->dataTypeMasker,
            $auditLogger,
            $maxDepth
        );

        // Initialize JSON masker with recursive mask callback
        /** @psalm-suppress InvalidArgument - recursiveMask is intentionally impure due to audit logging */
        $this->jsonMasker = new JsonMasker(
            $this->recursiveProcessor->recursiveMask(...),
            $auditLogger
        );

        // Initialize context processor for field-level operations
        $this->contextProcessor = new ContextProcessor(
            $fieldPaths,
            $customCallbacks,
            $auditLogger,
            $this->regExpMessage(...)
        );
    }



    /**
     * Create a rate-limited audit logger wrapper.
     *
     * @param callable(string,mixed,mixed):void $auditLogger The underlying audit logger
     * @param string $profile Rate limiting profile: 'strict', 'default', 'relaxed', or 'testing'
     */
    public static function createRateLimitedAuditLogger(
        callable $auditLogger,
        string $profile = 'default'
    ): RateLimitedAuditLogger {
        return RateLimitedAuditLogger::create($auditLogger, $profile);
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
     *
     * @psalm-return RateLimitedAuditLogger|Closure(string, mixed, mixed):void
     * @psalm-suppress ReferenceConstraintViolation - The closure always sets timestamp, but Psalm can't infer this through RateLimitedAuditLogger wrapper
     */
    public static function createArrayAuditLogger(
        array &$logStorage,
        bool $rateLimited = false
    ): Closure|RateLimitedAuditLogger {
        $baseLogger = function (string $path, mixed $original, mixed $masked) use (&$logStorage): void {
            $logStorage[] = [
                'path' => $path,
                'original' => $original,
                'masked' => $masked,
                'timestamp' => time()
            ];
        };

        return $rateLimited
            ? self::createRateLimitedAuditLogger($baseLogger, 'testing')
            : $baseLogger;
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

        $message = $this->regExpMessage($record->message);
        $context = $record->context;
        $accessor = new Dot($context);
        $processedFields = [];

        if ($this->fieldPaths !== []) {
            $processedFields = array_merge($processedFields, $this->contextProcessor->maskFieldPaths($accessor));
        }

        if ($this->customCallbacks !== []) {
            $processedFields = array_merge(
                $processedFields,
                $this->contextProcessor->processCustomCallbacks($accessor)
            );
        }

        if ($this->fieldPaths !== [] || $this->customCallbacks !== []) {
            $context = $accessor->all();
            // Apply data type masking to the entire context after field/callback processing
            $context = $this->dataTypeMasker->applyToContext(
                $context,
                $processedFields,
                '',
                $this->recursiveProcessor->recursiveMask(...)
            );
        } else {
            $context = $this->recursiveProcessor->recursiveMask($context, 0);
        }

        return $record->with(message: $message, context: $context);
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
        // Early return for empty messages
        if ($message === '') {
            return $message;
        }

        // Track original message for empty result protection
        $originalMessage = $message;

        // Handle JSON strings and regular patterns in a coordinated way
        $message = $this->maskMessageWithJsonSupport($message);

        return $message === '' || $message === '0' ? $originalMessage : $message;
    }

    /**
     * Mask message content, handling both JSON structures and regular patterns.
     */
    private function maskMessageWithJsonSupport(string $message): string
    {
        // Use JsonMasker to process JSON structures
        $result = $this->jsonMasker->processMessage($message);

        // Now apply regular patterns to the entire result
        foreach ($this->patterns as $regex => $replacement) {
            try {
                /** @psalm-suppress ArgumentTypeCoercion */
                $newResult = preg_replace($regex, $replacement, $result, -1, $count);

                if ($newResult === null) {
                    $error = preg_last_error_msg();

                    if ($this->auditLogger !== null) {
                        ($this->auditLogger)('preg_replace_error', $result, 'Error: ' . $error);
                    }

                    continue;
                }

                if ($count > 0) {
                    $result = $newResult;
                }
            } catch (Error $e) {
                if ($this->auditLogger !== null) {
                    ($this->auditLogger)('regex_error', $regex, $e->getMessage());
                }

                continue;
            }
        }

        return $result;
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
        return $this->recursiveProcessor->recursiveMask($data, $currentDepth);
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
        } catch (Error $error) {
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

        // Propagate to child processors
        $this->contextProcessor->setAuditLogger($auditLogger);
        $this->recursiveProcessor->setAuditLogger($auditLogger);
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
