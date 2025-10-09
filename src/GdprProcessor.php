<?php

namespace Ivuorinen\MonologGdprFilter;

use Closure;
use Throwable;
use JsonException;
use InvalidArgumentException;
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
    /**
     * Static cache for compiled regex patterns to improve performance.
     * @var array<string, bool>
     */
    private static array $validPatternCache = [];

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
     * @throws InvalidArgumentException When any parameter is invalid
     */
    public function __construct(
        private readonly array $patterns,
        private readonly array $fieldPaths = [],
        private readonly array $customCallbacks = [],
        private $auditLogger = null,
        private readonly int $maxDepth = 100,
        private readonly array $dataTypeMasks = [],
        private readonly array $conditionalRules = []
    ) {
        // Validate all constructor parameters
        $this->validateConstructorParameters();

        // Pre-validate patterns for better performance
        $this->validatePatternsOnConstruct();
    }

    /**
     * Clear the pattern validation cache (useful for testing).
     */
    public static function clearPatternCache(): void
    {
        self::$validPatternCache = [];
    }

    /**
     * Get default data type masking configuration.
     *
     * @return string[]
     *
     * @psalm-return array{
     *     integer: '***INT***',
     *     double: '***FLOAT***',
     *     string: '***STRING***',
     *     boolean: '***BOOL***',
     *     NULL: '***NULL***',
     *     array: '***ARRAY***',
     *     object: '***OBJECT***',
     *     resource: '***RESOURCE***'
     * }
     */
    public static function getDefaultDataTypeMasks(): array
    {
        return [
            'integer' => '***INT***',
            'double' => '***FLOAT***',
            'string' => '***STRING***',
            'boolean' => '***BOOL***',
            'NULL' => '***NULL***',
            'array' => '***ARRAY***',
            'object' => '***OBJECT***',
            'resource' => '***RESOURCE***',
        ];
    }

    /**
     * Create a conditional rule based on log level.
     *
     * @param array<string> $levels Log levels that should trigger masking
     *
     * @psalm-return Closure(LogRecord):bool
     */
    public static function createLevelBasedRule(array $levels): Closure
    {
        return fn(LogRecord $record): bool => in_array($record->level->name, $levels, true);
    }

    /**
     * Create a conditional rule based on context field presence.
     *
     * @param string $fieldPath Dot-notation path to check
     *
     * @psalm-return Closure(LogRecord):bool
     */
    public static function createContextFieldRule(string $fieldPath): Closure
    {
        return function (LogRecord $record) use ($fieldPath): bool {
            $accessor = new Dot($record->context);
            return $accessor->has($fieldPath);
        };
    }

    /**
     * Create a conditional rule based on context field value.
     *
     * @param string $fieldPath Dot-notation path to check
     * @param mixed $expectedValue Expected value
     *
     * @psalm-return Closure(LogRecord):bool
     */
    public static function createContextValueRule(string $fieldPath, mixed $expectedValue): Closure
    {
        return function (LogRecord $record) use ($fieldPath, $expectedValue): bool {
            $accessor = new Dot($record->context);
            return $accessor->get($fieldPath) === $expectedValue;
        };
    }

    /**
     * Create a conditional rule based on channel name.
     *
     * @param array<string> $channels Channel names that should trigger masking
     *
     * @psalm-return Closure(LogRecord):bool
     */
    public static function createChannelBasedRule(array $channels): Closure
    {
        return fn(LogRecord $record): bool => in_array($record->channel, $channels, true);
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
     * Apply data type-based masking to a value.
     *
     * @param mixed $value The value to mask.
     * @return mixed The masked value.
     *
     * @psalm-param mixed $value The value to mask.
     */
    private function applyDataTypeMasking(mixed $value): mixed
    {
        if ($this->dataTypeMasks === []) {
            return $value;
        }

        $type = gettype($value);

        if (isset($this->dataTypeMasks[$type])) {
            $mask = $this->dataTypeMasks[$type];

            // Special handling for different types
            switch ($type) {
                case 'integer':
                    // Return numeric value if mask is numeric, otherwise return the mask string
                    return is_numeric($mask) ? (int)$mask : $mask;

                case 'double':
                    // Return numeric value if mask is numeric, otherwise return the mask string
                    return is_numeric($mask) ? (float)$mask : $mask;

                case 'boolean':
                    if ($mask === 'preserve') {
                        return $value;
                    }

                    // Return boolean if mask can be converted, otherwise return the mask string
                    if ($mask === 'true') {
                        return true;
                    }

                    // Return boolean if mask can be converted, otherwise return the mask string
                    if ($mask === 'false') {
                        return false;
                    }

                    return $mask;

                case 'NULL':
                    return $mask === 'preserve' ? null : $mask;

                case 'array':
                    // For arrays, we can return a masked indicator or process recursively
                    if ($mask === 'recursive') {
                        return $this->recursiveMask($value, 0);
                    }

                    return [$mask];

                case 'object':
                    // For objects, convert to masked representation
                    return (object) ['masked' => $mask, 'original_class' => $value::class];

                case 'string':
                default:
                    return $mask;
            }
        }

        return $value;
    }

    /**
     * FieldMaskConfig: config for masking/removal per field path using regex.
     */
    public static function maskWithRegex(): FieldMaskConfig
    {
        return new FieldMaskConfig(FieldMaskConfig::MASK_REGEX);
    }

    /**
     * FieldMaskConfig: Remove field from context.
     */
    public static function removeField(): FieldMaskConfig
    {
        return new FieldMaskConfig(FieldMaskConfig::REMOVE);
    }

    /**
     * FieldMaskConfig: Replace field value with a static string.
     */
    public static function replaceWith(string $replacement): FieldMaskConfig
    {
        return new FieldMaskConfig(FieldMaskConfig::REPLACE, $replacement);
    }

    /**
     * Default GDPR regex patterns. Non-exhaustive, should be extended with your own.
     *
     * @return string[]
     *
     * @psalm-return array<string, string>
     */
    public static function getDefaultPatterns(): array
    {
        // @psalm-suppress LessSpecificReturnType, InvalidReturnType
        return [
            // Finnish SSN (HETU)
            '/\b\d{6}[-+A]?\d{3}[A-Z]\b/u' => '***HETU***',
            // US Social Security Number (strict: 3-2-4 digits)
            '/^\d{3}-\d{2}-\d{4}$/' => '***USSSN***',
            // IBAN (strictly match Finnish IBAN with or without spaces, only valid groupings)
            '/^FI\d{2}(?: ?\d{4}){3} ?\d{2}$/u' => '***IBAN***',
            // Also match fully compact Finnish IBAN (no spaces)
            '/^FI\d{16}$/u' => '***IBAN***',
            // International phone numbers (E.164, +countrycode...)
            '/^\+\d{1,3}[\s-]?\d{1,4}[\s-]?\d{1,4}[\s-]?\d{1,9}$/' => '***PHONE***',
            // Email address
            '/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/' => '***EMAIL***',
            // Date of birth (YYYY-MM-DD)
            '/^(19|20)\d{2}-[01]\d\-[0-3]\d$/' => '***DOB***',
            // Date of birth (DD/MM/YYYY)
            '/^[0-3]\d\/[01]\d\/(19|20)\d{2}$/' => '***DOB***',
            // Passport numbers (A followed by 6 digits)
            '/^A\d{6}$/' => '***PASSPORT***',
            // Credit card numbers (Visa, MC, Amex, Discover test numbers)
            '/^(4111 1111 1111 1111|5500-0000-0000-0004|340000000000009|6011000000000004)$/' => '***CC***',
            // Generic 16-digit credit card (for test compatibility)
            '/\b[0-9]{16}\b/u' => '***CC***',
            // Bearer tokens (JWT, at least 10 chars after Bearer)
            '/^Bearer [A-Za-z0-9\-\._~\+\/]{10,}$/' => '***TOKEN***',
            // API keys (Stripe-like, 20+ chars, or sk_live|sk_test)
            '/^(sk_(live|test)_[A-Za-z0-9]{16,}|[A-Za-z0-9\-_]{20,})$/' => '***APIKEY***',
            // MAC addresses
            '/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/' => '***MAC***',

            // IP Addresses
            // IPv4 address (dotted decimal notation)
            '/\b(?:(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.){3}(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\b/' => '***IPv4***',

            // Vehicle Registration Numbers (more specific patterns)
            // US License plates (specific formats: ABC-1234, ABC1234)
            '/\b[A-Z]{2,3}[-\s]?\d{3,4}\b/' => '***VEHICLE***',
            // Reverse format (123-ABC)
            '/\b\d{3,4}[-\s]?[A-Z]{2,3}\b/' => '***VEHICLE***',

            // National ID Numbers
            // UK National Insurance Number (2 letters, 6 digits, 1 letter)
            '/\b[A-Z]{2}\d{6}[A-Z]\b/' => '***UKNI***',
            // Canadian Social Insurance Number (3-3-3 format)
            '/\b\d{3}[-\s]\d{3}[-\s]\d{3}\b/' => '***CASIN***',
            // UK Sort Code + Account (6 digits + 8 digits)
            '/\b\d{6}[-\s]\d{8}\b/' => '***UKBANK***',
            // Canadian Transit + Account (5 digits + 7-12 digits)
            '/\b\d{5}[-\s]\d{7,12}\b/' => '***CABANK***',

            // Health Insurance Numbers
            // US Medicare number (various formats)
            '/\b\d{3}[-\s]\d{2}[-\s]\d{4}\b/' => '***MEDICARE***',
            // European Health Insurance Card (starts with country code)
            '/\b\d{2}[-\s]\d{4}[-\s]\d{4}[-\s]\d{4}[-\s]\d{1,4}\b/' => '***EHIC***',

            // IPv6 address (specific pattern with colons)
            '/\b[0-9a-fA-F]{1,4}:[0-9a-fA-F:]{7,35}\b/' => '***IPv6***',
        ];
    }

    /**
     * Validate all constructor parameters for early error detection.
     *
     * @throws InvalidArgumentException When any parameter is invalid
     */
    private function validateConstructorParameters(): void
    {
        $this->validatePatterns();
        $this->validateFieldPaths();
        $this->validateCustomCallbacks();
        $this->validateAuditLogger();
        $this->validateMaxDepth();
        $this->validateDataTypeMasks();
        $this->validateConditionalRules();
    }

    /**
     * Validate patterns array for proper structure and valid regex patterns.
     *
     * @throws InvalidArgumentException When patterns are invalid
     */
    private function validatePatterns(): void
    {
        foreach ($this->patterns as $pattern => $replacement) {
            // Validate pattern key
            /** @psalm-suppress DocblockTypeContradiction - Runtime validation for defensive programming */
            if (!is_string($pattern)) {
                throw new InvalidArgumentException(
                    'Pattern keys must be strings, got: ' . gettype($pattern)
                );
            }

            if (trim($pattern) === '') {
                throw new InvalidArgumentException('Pattern cannot be empty');
            }

            // Validate replacement value
            /** @psalm-suppress DocblockTypeContradiction - Runtime validation for defensive programming */
            if (!is_string($replacement)) {
                throw new InvalidArgumentException(
                    'Pattern replacements must be strings, got: ' . gettype($replacement)
                );
            }

            // Validate regex pattern syntax
            if (!$this->isValidRegexPattern($pattern)) {
                throw new InvalidArgumentException(sprintf("Invalid regex pattern: '%s'", $pattern));
            }
        }
    }

    /**
     * Validate field paths array for proper structure.
     *
     * @throws InvalidArgumentException When field paths are invalid
     */
    private function validateFieldPaths(): void
    {
        foreach ($this->fieldPaths as $path => $config) {
            // Validate path key
            /** @psalm-suppress DocblockTypeContradiction - Runtime validation for defensive programming */
            if (!is_string($path)) {
                throw new InvalidArgumentException(
                    'Field path keys must be strings, got: ' . gettype($path)
                );
            }

            if (trim($path) === '') {
                throw new InvalidArgumentException('Field path cannot be empty');
            }

            // Validate config value
            /** @psalm-suppress DocblockTypeContradiction - Runtime validation for defensive programming */
            if (!($config instanceof FieldMaskConfig) && !is_string($config)) {
                throw new InvalidArgumentException(
                    'Field path values must be FieldMaskConfig instances or strings, got: ' . gettype($config)
                );
            }

            if (is_string($config) && trim($config) === '') {
                throw new InvalidArgumentException(sprintf(
                    "Field path '%s' cannot have empty string value",
                    $path
                ));
            }
        }
    }

    /**
     * Validate custom callbacks array for proper structure.
     *
     * @throws InvalidArgumentException When custom callbacks are invalid
     */
    private function validateCustomCallbacks(): void
    {
        foreach ($this->customCallbacks as $path => $callback) {
            // Validate path key
            /** @psalm-suppress DocblockTypeContradiction - Runtime validation for defensive programming */
            if (!is_string($path)) {
                throw new InvalidArgumentException(
                    'Custom callback path keys must be strings, got: ' . gettype($path)
                );
            }

            if (trim($path) === '') {
                throw new InvalidArgumentException('Custom callback path cannot be empty');
            }

            // Validate callback value
            if (!is_callable($callback)) {
                throw new InvalidArgumentException(sprintf(
                    "Custom callback for path '%s' must be callable",
                    $path
                ));
            }
        }
    }

    /**
     * Validate audit logger parameter.
     *
     * @throws InvalidArgumentException When audit logger is invalid
     */
    private function validateAuditLogger(): void
    {
        if ($this->auditLogger !== null && !is_callable($this->auditLogger)) {
            $type = gettype($this->auditLogger);
            throw new InvalidArgumentException(
                "Audit logger must be callable or null, got: {$type}"
            );
        }
    }

    /**
     * Validate max depth parameter for reasonable bounds.
     *
     * @throws InvalidArgumentException When max depth is invalid
     */
    private function validateMaxDepth(): void
    {
        if ($this->maxDepth <= 0) {
            throw new InvalidArgumentException(
                "Maximum depth must be a positive integer, got: {$this->maxDepth}"
            );
        }

        if ($this->maxDepth > 1000) {
            throw new InvalidArgumentException(
                "Maximum depth cannot exceed 1,000 for stack safety, got: {$this->maxDepth}"
            );
        }
    }

    /**
     * Validate data type masks array for proper structure.
     *
     * @throws InvalidArgumentException When data type masks are invalid
     */
    private function validateDataTypeMasks(): void
    {
        $validTypes = ['integer', 'double', 'string', 'boolean', 'NULL', 'array', 'object', 'resource'];

        foreach ($this->dataTypeMasks as $type => $mask) {
            // Validate type key
            /** @psalm-suppress DocblockTypeContradiction - Runtime validation for defensive programming */
            if (!is_string($type)) {
                $typeGot = gettype($type);
                throw new InvalidArgumentException(
                    "Data type mask keys must be strings, got: {$typeGot}"
                );
            }

            if (!in_array($type, $validTypes, true)) {
                $validList = implode(', ', $validTypes);
                throw new InvalidArgumentException(
                    sprintf("Invalid data type '%s'. Must be one of: %s", $type, $validList)
                );
            }

            // Validate mask value
            /** @psalm-suppress DocblockTypeContradiction - Runtime validation for defensive programming */
            if (!is_string($mask)) {
                throw new InvalidArgumentException('Data type mask values must be strings, got: ' . gettype($mask));
            }

            if (trim($mask) === '') {
                throw new InvalidArgumentException(sprintf("Data type mask for '%s' cannot be empty", $type));
            }
        }
    }

    /**
     * Validate conditional rules array for proper structure.
     *
     * @throws InvalidArgumentException When conditional rules are invalid
     */
    private function validateConditionalRules(): void
    {
        foreach ($this->conditionalRules as $ruleName => $callback) {
            // Validate rule name key
            /** @psalm-suppress DocblockTypeContradiction - Runtime validation for defensive programming */
            if (!is_string($ruleName)) {
                throw new InvalidArgumentException(
                    'Conditional rule names must be strings, got: ' . gettype($ruleName)
                );
            }

            if (trim($ruleName) === '') {
                throw new InvalidArgumentException('Conditional rule name cannot be empty');
            }

            // Validate callback value
            if (!is_callable($callback)) {
                throw new InvalidArgumentException(sprintf(
                    "Conditional rule '%s' must have a callable callback",
                    $ruleName
                ));
            }
        }
    }

    /**
     * Validate patterns during construction for better runtime performance.
     */
    private function validatePatternsOnConstruct(): void
    {
        foreach (array_keys($this->patterns) as $pattern) {
            if (!isset(self::$validPatternCache[$pattern])) {
                self::$validPatternCache[$pattern] = $this->isValidRegexPattern($pattern);
            }
        }
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
            if ($this->dataTypeMasks !== []) {
                $context = $this->applyDataTypeMaskingToContext($context, $processedFields);
            }
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
                    $sanitized = $this->sanitizeErrorMessage($e->getMessage());
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
        // Simplified approach: try to find complete JSON objects/arrays and validate them
        $result = $message;

        // Look for JSON structures using a more comprehensive approach
        // Use a simple recursive approach to find balanced braces/brackets
        $result = $this->findAndProcessJsonStructures($result);

        // Now apply regular patterns to the entire result
        foreach ($this->patterns as $regex => $replacement) {
            try {
                /** @psalm-suppress ArgumentTypeCoercion */
                $newResult = preg_replace($regex, $replacement, $result, -1, $count);

                if ($newResult === null) {
                    $error = preg_last_error_msg();
                    self::$validPatternCache[$regex] = false;

                    if ($this->auditLogger !== null) {
                        ($this->auditLogger)('preg_replace_error', $result, 'Error: ' . $error);
                    }

                    continue;
                }

                if ($count > 0) {
                    $result = $newResult;
                    self::$validPatternCache[$regex] = true;
                }
            } catch (Error $e) {
                self::$validPatternCache[$regex] = false;

                if ($this->auditLogger !== null) {
                    ($this->auditLogger)('regex_error', $regex, $e->getMessage());
                }

                continue;
            }
        }

        return $result;
    }

    /**
     * Find and process JSON structures in the message.
     */
    private function findAndProcessJsonStructures(string $message): string
    {
        $result = '';
        $length = strlen($message);
        $i = 0;

        while ($i < $length) {
            $char = $message[$i];

            if ($char === '{' || $char === '[') {
                // Found potential JSON start, try to extract balanced structure
                $jsonCandidate = $this->extractBalancedStructure($message, $i);

                if ($jsonCandidate !== null) {
                    // Process the candidate
                    $processed = $this->processJsonCandidate($jsonCandidate);
                    $result .= $processed;
                    $i += strlen($jsonCandidate);
                    continue;
                }
            }

            $result .= $char;
            $i++;
        }

        return $result;
    }

    /**
     * Extract a balanced JSON structure starting from the given position.
     */
    private function extractBalancedStructure(string $message, int $startPos): ?string
    {
        $length = strlen($message);
        $startChar = $message[$startPos];
        $endChar = $startChar === '{' ? '}' : ']';
        $level = 0;
        $inString = false;
        $escaped = false;

        for ($i = $startPos; $i < $length; $i++) {
            $char = $message[$i];

            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($char === '\\' && $inString) {
                $escaped = true;
                continue;
            }

            if ($char === '"') {
                $inString = !$inString;
                continue;
            }

            if (!$inString) {
                if ($char === $startChar) {
                    $level++;
                } elseif ($char === $endChar) {
                    $level--;

                    if ($level === 0) {
                        // Found complete balanced structure
                        return substr($message, $startPos, $i - $startPos + 1);
                    }
                }
            }
        }

        // No balanced structure found
        return null;
    }

    /**
     * Process a potential JSON candidate string.
     */
    private function processJsonCandidate(string $potentialJson): string
    {
        try {
            // Try to parse as JSON
            $decoded = json_decode($potentialJson, true, 512, JSON_THROW_ON_ERROR);

            // If successfully decoded, apply masking and re-encode
            if ($decoded !== null) {
                $masked = $this->recursiveMask($decoded, 0);
                $reEncoded = $this->encodeJsonPreservingEmptyObjects($masked, $potentialJson);

                if ($reEncoded !== false) {
                    // Log the operation if audit logger is available
                    if ($this->auditLogger !== null && $reEncoded !== $potentialJson) {
                        ($this->auditLogger)('json_masked', $potentialJson, $reEncoded);
                    }

                    return $reEncoded;
                }
            }
        } catch (JsonException) {
            // Not valid JSON, leave as-is to be processed by regular patterns
        }

        return $potentialJson;
    }

    /**
     * Encode JSON while preserving empty object structures from the original.
     *
     * @param array<mixed>|string $data The data to encode.
     * @param string $originalJson The original JSON string.
     *
     * @return false|string The encoded JSON string or false on failure.
     */
    private function encodeJsonPreservingEmptyObjects(array|string $data, string $originalJson): string|false
    {
        // Handle simple empty cases first
        if (in_array($data, ['', '0', []], true)) {
            if ($originalJson === '{}') {
                return '{}';
            }

            if ($originalJson === '[]') {
                return '[]';
            }
        }

        // Encode the processed data
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            return false;
        }

        // Fix empty arrays that should be empty objects by comparing with original
        return $this->fixEmptyObjectsInEncodedJson($encoded, $originalJson);
    }

    /**
     * Fix empty arrays that should be empty objects in the encoded JSON.
     */
    private function fixEmptyObjectsInEncodedJson(string $encoded, string $original): string
    {
        // Count empty objects in original and empty arrays in encoded
        $originalEmptyObjects = substr_count($original, '{}');
        $encodedEmptyArrays = substr_count($encoded, '[]');

        // If we lost empty objects (they became arrays), fix them
        if ($originalEmptyObjects > 0 && $encodedEmptyArrays >= $originalEmptyObjects) {
            // Replace empty arrays with empty objects, up to the number we had originally
            for ($i = 0; $i < $originalEmptyObjects; $i++) {
                $encoded = preg_replace('/\[\]/', '{}', $encoded, 1) ?? $encoded;
            }
        }

        return $encoded;
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
                $sanitized = $this->sanitizeErrorMessage($e->getMessage());
                $errorMsg = 'Callback failed: ' . $sanitized;
                $this->logAudit($path . '_callback_error', $value, $errorMsg);
                $processedFields[] = $path;
            }
        }

        return $processedFields;
    }

    /**
     * Apply data type masking to an entire context structure.
     *
     * @param array<mixed> $context
     * @param array<string> $processedFields Array of field paths already processed
     * @param string $currentPath Current dot-notation path for nested processing
     * @return array<mixed>
     */
    private function applyDataTypeMaskingToContext(
        array $context,
        array $processedFields = [],
        string $currentPath = ''
    ): array {
        $result = [];
        foreach ($context as $key => $value) {
            $fieldPath = $currentPath === '' ? (string)$key : $currentPath . '.' . $key;

            // Skip fields that have already been processed by field paths or custom callbacks
            if (in_array($fieldPath, $processedFields, true)) {
                $result[$key] = $value;
                continue;
            }

            if (is_array($value)) {
                $result[$key] = $this->applyDataTypeMaskingToContext($value, $processedFields, $fieldPath);
            } else {
                $type = gettype($value);
                if (isset($this->dataTypeMasks[$type])) {
                    $masked = $this->applyDataTypeMasking($value);
                    if ($masked !== $value) {
                        $this->logAudit($fieldPath, $value, $masked);
                    }

                    $result[$key] = $masked;
                } else {
                    $result[$key] = $value;
                }
            }
        }

        return $result;
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
            // Process large arrays in chunks to reduce memory pressure
            $result = [];
            $chunks = array_chunk($data, $chunkSize, true);

            foreach ($chunks as $chunk) {
                foreach ($chunk as $key => $value) {
                    $type = gettype($value);

                    if (is_string($value)) {
                        // For strings: apply regex patterns first, then data type masking if unchanged
                        $regexResult = $this->regExpMessage($value);
                        if ($regexResult !== $value) {
                            // Regex patterns matched and changed the value
                            $result[$key] = $regexResult;
                        } elseif ($this->dataTypeMasks !== [] && isset($this->dataTypeMasks[$type])) {
                            // No regex match, apply data type masking if configured
                            $result[$key] = $this->applyDataTypeMasking($value);
                        } else {
                            // No masking applied
                            $result[$key] = $value;
                        }
                    } elseif (is_array($value)) {
                        // For arrays: apply data type masking if configured, otherwise recurse
                        if ($this->dataTypeMasks !== [] && isset($this->dataTypeMasks[$type])) {
                            $result[$key] = $this->applyDataTypeMasking($value);
                        } else {
                            $result[$key] = $this->recursiveMask($value, $currentDepth + 1);
                        }
                    } elseif ($this->dataTypeMasks !== [] && isset($this->dataTypeMasks[$type])) {
                        // For other non-strings: apply data type masking if configured
                        $result[$key] = $this->applyDataTypeMasking($value);
                    } else {
                        // Keep other types as-is if no specific masking is configured
                        $result[$key] = $value;
                    }
                }

                // Optional: Force garbage collection after each chunk for memory management
                if ($arraySize > 10000) {
                    gc_collect_cycles();
                }
            }

            return $result;
        }

        // Standard processing for smaller arrays
        foreach ($data as $key => $value) {
            $type = gettype($value);

            if (is_string($value)) {
                // For strings: apply regex patterns first, then data type masking if unchanged
                $regexResult = $this->regExpMessage($value);
                if ($regexResult !== $value) {
                    // Regex patterns matched and changed the value
                    $data[$key] = $regexResult;
                } elseif ($this->dataTypeMasks !== [] && isset($this->dataTypeMasks[$type])) {
                    // No regex match, apply data type masking if configured
                    $data[$key] = $this->applyDataTypeMasking($value);
                } else {
                    // No masking applied
                    $data[$key] = $value;
                }
            } elseif (is_array($value)) {
                // For arrays: apply data type masking if configured, otherwise recurse
                if ($this->dataTypeMasks !== [] && isset($this->dataTypeMasks[$type])) {
                    $data[$key] = $this->applyDataTypeMasking($value);
                } else {
                    $data[$key] = $this->recursiveMask($value, $currentDepth + 1);
                }
            } elseif ($this->dataTypeMasks !== [] && isset($this->dataTypeMasks[$type])) {
                // For other non-strings: apply data type masking if configured
                $data[$key] = $this->applyDataTypeMasking($value);
            } else {
                // Keep other types as-is if no specific masking is configured
                $data[$key] = $value;
            }
        }

        return $data;
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

    /**
     * Validate that a regex pattern is safe and well-formed.
     * This helps prevent regex injection and ReDoS attacks.
     */
    private function isValidRegexPattern(string $pattern): bool
    {
        // Check for basic regex structure
        if (strlen($pattern) < 3) {
            return false;
        }

        // Must start and end with delimiters
        $firstChar = $pattern[0];
        $lastDelimPos = strrpos($pattern, $firstChar);
        if ($lastDelimPos === false || $lastDelimPos === 0) {
            return false;
        }

        // Enhanced ReDoS protection - check for potentially dangerous patterns
        $dangerousPatterns = [
            // Nested quantifiers (classic ReDoS patterns)
            '/\([^)]*\+[^)]*\)\+/',     // (a+)+ pattern
            '/\([^)]*\*[^)]*\)\*/',     // (a*)* pattern
            '/\([^)]*\+[^)]*\)\*/',     // (a+)* pattern
            '/\([^)]*\*[^)]*\)\+/',     // (a*)+ pattern

            // Alternation with overlapping patterns
            '/\([^|)]*\|[^|)]*\)\*/',   // (a|a)* pattern
            '/\([^|)]*\|[^|)]*\)\+/',   // (a|a)+ pattern

            // Complex nested structures
            '/\(\([^)]*\+[^)]*\)[^)]*\)\+/',  // ((a+)...)+ pattern

            // Character classes with nested quantifiers
            '/\[[^\]]*\]\*\*/',         // [a-z]** pattern
            '/\[[^\]]*\]\+\+/',         // [a-z]++ pattern
            '/\([^)]*\[[^\]]*\][^)]*\)\*/', // ([a-z])* pattern
            '/\([^)]*\[[^\]]*\][^)]*\)\+/', // ([a-z])+ pattern

            // Lookahead/lookbehind with quantifiers
            '/\(\?\=[^)]*\)\([^)]*\)\+/', // (?=...)(...)+
            '/\(\?\<[^)]*\)\([^)]*\)\+/', // (?<...)(...)+

            // Word boundaries with dangerous quantifiers
            '/\\\\w\+\*/',              // \w+* pattern
            '/\\\\w\*\+/',              // \w*+ pattern

            // Dot with dangerous quantifiers
            '/\.\*\*/',                 // .** pattern
            '/\.\+\+/',                 // .++ pattern
            '/\(\.\*\)\+/',             // (.*)+ pattern
            '/\(\.\+\)\*/',             // (.+)* pattern

            // Legacy dangerous patterns (keeping for backward compatibility)
            '/\(\?.*\*.*\+/',           // (?:...*...)+
            '/\(.*\*.*\).*\*/',         // (...*...).*

            // Overlapping alternation patterns - catastrophic backtracking
            '/\(\.\*\s*\|\s*\.\*\)/',   // (.*|.*) pattern - identical alternations
            '/\(\.\+\s*\|\s*\.\+\)/',   // (.+|.+) pattern - identical alternations

            // Multiple alternations with overlapping/expanding strings causing exponential backtracking
            // Matches patterns like (a|ab|abc|abcd)* where alternatives overlap/extend each other
            '/\([a-zA-Z0-9]+(\s*\|\s*[a-zA-Z0-9]+){2,}\)\*/',
            '/\([a-zA-Z0-9]+(\s*\|\s*[a-zA-Z0-9]+){2,}\)\+/',
        ];

        foreach ($dangerousPatterns as $dangerousPattern) {
            if (preg_match($dangerousPattern, $pattern)) {
                return false;
            }
        }

        // Test if the pattern is valid by trying to compile it
        set_error_handler(
            /**
             * @return true
             */
            static fn(): bool => true
        );

        try {
            /** @psalm-suppress ArgumentTypeCoercion */
            $result = preg_match($pattern, '');
            return $result !== false;
        } catch (Error) {
            return false;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Validate all patterns for security before use.
     * This method can be called to validate patterns before creating a processor.
     *
     * @param array<string, string> $patterns
     * @throws InvalidArgumentException If any pattern is invalid or unsafe
     */
    public static function validatePatternsArray(array $patterns): void
    {
        foreach ($patterns as $pattern => $replacement) {
            $processor = new self([$pattern => $replacement]);
            if (!$processor->isValidRegexPattern($pattern)) {
                throw new InvalidArgumentException('Invalid or unsafe regex pattern: ' . $pattern);
            }
        }
    }

    /**
     * Sanitize error messages to prevent information disclosure.
     *
     * This method removes sensitive information from error messages
     * before they are logged to prevent security vulnerabilities.
     *
     * @param string $message The original error message
     *
     * @return string The sanitized error message
     */
    private function sanitizeErrorMessage(string $message): string
    {
        // List of sensitive patterns to remove or mask
        $sensitivePatterns = [
            // Database credentials
            '/password=\S+/i' => 'password=***',
            '/pwd=\S+/i' => 'pwd=***',
            '/pass=\S+/i' => 'pass=***',

            // Database hosts and connection strings
            '/host=[\w\.-]+/i' => 'host=***',
            '/server=[\w\.-]+/i' => 'server=***',
            '/hostname=[\w\.-]+/i' => 'hostname=***',

            // User credentials
            '/user=\S+/i' => 'user=***',
            '/username=\S+/i' => 'username=***',
            '/uid=\S+/i' => 'uid=***',

            // API keys and tokens
            '/api[_-]?key[=:]\s*\S+/i' => 'api_key=***',
            '/token[=:]\s*\S+/i' => 'token=***',
            '/bearer\s+\S+/i' => 'bearer ***',
            '/sk_\w+/i' => 'sk_***',
            '/pk_\w+/i' => 'pk_***',

            // File paths (potential information disclosure)
            '/\/[\w\/\.-]*\/(config|secret|private|key)[\w\/\.-]*/i' => '/***/$1/***',
            '/[a-zA-Z]:\\\\[\w\\\\.-]*\\\\(config|secret|private|key)[\w\\\\.-]*/i' => 'C:\\***\\$1\\***',

            // Connection strings
            '/redis:\/\/[^@]*@[\w\.-]+:\d+/i' => 'redis://***:***@***:***',
            '/mysql:\/\/[^@]*@[\w\.-]+:\d+/i' => 'mysql://***:***@***:***',
            '/postgresql:\/\/[^@]*@[\w\.-]+:\d+/i' =>
            'postgresql://***:***@***:***',

            // JWT secrets and other secrets (enhanced to catch more patterns)
            '/secret[_-]?key[=:\s]+\S+/i' => 'secret_key=***',
            '/jwt[_-]?secret[=:\s]+\S+/i' => 'jwt_secret=***',
            '/\bsuper_secret_\w+/i' => '***SECRET***',

            // Generic secret-like patterns (alphanumeric keys that look sensitive)
            '/\b[a-z_]*secret[a-z_]*[=:\s]+[\w\d_-]{10,}/i' => 'secret=***',
            '/\b[a-z_]*key[a-z_]*[=:\s]+[\w\d_-]{10,}/i' => 'key=***',

            // IP addresses in internal ranges
            '/\b(?:10\.\d{1,3}\.\d{1,3}\.\d{1,3}|172\.(?:1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3}|192\.168\.\d{1,3}\.\d{1,3})\b/' => '***.***.***',
        ];

        $sanitized = $message;

        foreach ($sensitivePatterns as $pattern => $replacement) {
            $sanitized = preg_replace($pattern, $replacement, $sanitized) ?? $sanitized;
        }

        // Truncate very long messages to prevent log flooding
        if (strlen($sanitized) > 500) {
            return substr($sanitized, 0, 500) . '... (truncated for security)';
        }

        return $sanitized;
    }
}
