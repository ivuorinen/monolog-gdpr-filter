<?php

namespace Ivuorinen\MonologGdprFilter;

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
        private readonly int $maxDepth = 100,
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

        // Initialize JSON masker with recursive mask callback
        /** @psalm-suppress InvalidArgument - recursiveMask is intentionally impure due to audit logging */
        $this->jsonMasker = new JsonMasker(
            $this->recursiveMask(...),
            $auditLogger
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
            $processedFields = array_merge($processedFields, $this->maskFieldPaths($accessor));
        }

        if ($this->customCallbacks !== []) {
            $processedFields = array_merge($processedFields, $this->processCustomCallbacks($accessor));
        }

        if ($this->fieldPaths !== [] || $this->customCallbacks !== []) {
            $context = $accessor->all();
            // Apply data type masking to the entire context after field/callback processing
            $context = $this->dataTypeMasker->applyToContext(
                $context,
                $processedFields,
                '',
                $this->recursiveMask(...)
            );
        } else {
            $context = $this->recursiveMask($context, 0);
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
     * Mask field paths in the context using the configured field masks.
     *
     * @param Dot<array-key, mixed> $accessor
     *
     * @return string[] Array of processed field paths
     *
     * @psalm-return list<string>
     */
    private function maskFieldPaths(Dot $accessor): array
    {
        $processedFields = [];
        foreach ($this->fieldPaths as $path => $config) {
            if (!$accessor->has($path)) {
                continue;
            }

            $value = $accessor->get($path, "");
            $action = $this->maskValue($path, $value, $config);
            if ($action['remove'] ?? false) {
                $accessor->delete($path);
                $this->logAudit($path, $value, null);
                $processedFields[] = $path;
                continue;
            }

            $masked = $action['masked'];
            if ($masked !== null && $masked !== $value) {
                $accessor->set($path, $masked);
                $this->logAudit($path, $value, $masked);
            }

            $processedFields[] = $path;
        }

        return $processedFields;
    }

    /**
     * Process custom callbacks on context fields.
     *
     * @param Dot<array-key, mixed> $accessor
     *
     * @return string[] Array of processed field paths
     *
     * @psalm-return list<string>
     */
    private function processCustomCallbacks(Dot $accessor): array
    {
        $processedFields = [];
        foreach ($this->customCallbacks as $path => $callback) {
            if (!$accessor->has($path)) {
                continue;
            }

            $value = $accessor->get($path);
            try {
                $masked = $callback($value);
                if ($masked !== $value) {
                    $accessor->set($path, $masked);
                    $this->logAudit($path, $value, $masked);
                }

                $processedFields[] = $path;
            } catch (Throwable $e) {
                // Log callback error but continue processing
                $sanitized = SecuritySanitizer::sanitizeErrorMessage($e->getMessage());
                $errorMsg = 'Callback failed: ' . $sanitized;
                $this->logAudit($path . '_callback_error', $value, $errorMsg);
                $processedFields[] = $path;
            }
        }

        return $processedFields;
    }

    /**
     * Mask a single value according to config or callback
     * Returns an array: ['masked' => value|null, 'remove' => bool]
     *
     * @psalm-return array{masked: string|null, remove: bool}
     */
    private function maskValue(string $path, mixed $value, FieldMaskConfig|string|null $config): array
    {
        $result = ['masked' => null, 'remove' => false];
        if (array_key_exists($path, $this->customCallbacks)) {
            $callback = $this->customCallbacks[$path];
            $result['masked'] = $callback($value);
            return $result;
        }

        if ($config instanceof FieldMaskConfig) {
            switch ($config->type) {
                case FieldMaskConfig::MASK_REGEX:
                    $result['masked'] = $this->regExpMessage((string) $value);
                    break;
                case FieldMaskConfig::REMOVE:
                    $result['masked'] = null;
                    $result['remove'] = true;
                    break;
                case FieldMaskConfig::REPLACE:
                    $result['masked'] = $config->replacement;
                    break;
                default:
                    // Return the type as string for unknown types
                    $result['masked'] = $config->type;
                    break;
            }
        } else {
            // Backward compatibility: treat string as replacement
            $result['masked'] = $config;
        }

        return $result;
    }

    /**
     * Audit logger helper
     *
     * @param string      $path     Dot-notation path of the field
     * @param mixed       $original Original value before masking
     * @param null|string $masked   Masked value after processing, or null if removed
     */
    private function logAudit(string $path, mixed $original, string|null $masked): void
    {
        if (is_callable($this->auditLogger) && $original !== $masked) {
            // Only log if the value was actually changed
            call_user_func($this->auditLogger, $path, $original, $masked);
        }
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
        if (is_string($data)) {
            return $this->regExpMessage($data);
        }

        // At this point, we know it's an array due to the string check above

        // Prevent excessive recursion depth
        if ($currentDepth >= $this->maxDepth) {
            if ($this->auditLogger !== null) {
                ($this->auditLogger)(
                    'max_depth_reached',
                    $currentDepth,
                    sprintf('Recursion depth limit (%d) reached', $this->maxDepth)
                );
            }

            return $data; // Return unmodified data when depth limit is reached
        }

        // Early return for empty arrays to save processing
        if ($data === []) {
            return $data;
        }

        // Memory-efficient processing: process in chunks for very large arrays
        $arraySize = count($data);
        $chunkSize = 1000; // Process in chunks of 1000 items

        if ($arraySize > $chunkSize) {
            return $this->processLargeArray($data, $currentDepth, $chunkSize);
        }

        // Standard processing for smaller arrays
        return $this->processStandardArray($data, $currentDepth);
    }

    /**
     * Process a large array in chunks to reduce memory pressure.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private function processLargeArray(array $data, int $currentDepth, int $chunkSize): array
    {
        $result = [];
        $chunks = array_chunk($data, $chunkSize, true);
        $arraySize = count($data);

        foreach ($chunks as $chunk) {
            foreach ($chunk as $key => $value) {
                $result[$key] = $this->processValue($value, $currentDepth);
            }

            // Optional: Force garbage collection after each chunk for memory management
            if ($arraySize > 10000) {
                gc_collect_cycles();
            }
        }

        return $result;
    }

    /**
     * Process a standard-sized array.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private function processStandardArray(array $data, int $currentDepth): array
    {
        foreach ($data as $key => $value) {
            $data[$key] = $this->processValue($value, $currentDepth);
        }

        return $data;
    }

    /**
     * Process a single value (string, array, or other type).
     */
    private function processValue(mixed $value, int $currentDepth): mixed
    {
        if (is_string($value)) {
            // For strings: apply regex patterns first, then data type masking if unchanged
            $regexResult = $this->regExpMessage($value);
            if ($regexResult !== $value) {
                // Regex patterns matched and changed the value
                return $regexResult;
            }

            // No regex match, apply data type masking if configured
            return $this->dataTypeMasker->applyMasking($value, $this->recursiveMask(...));
        }

        if (is_array($value)) {
            // For arrays: apply data type masking if configured, otherwise recurse
            $masked = $this->dataTypeMasker->applyMasking($value, $this->recursiveMask(...));
            if ($masked !== $value) {
                return $masked;
            }

            return $this->recursiveMask($value, $currentDepth + 1);
        }

        // For other non-strings: apply data type masking if configured
        return $this->dataTypeMasker->applyMasking($value, $this->recursiveMask(...));
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
    }
}
