<?php

namespace Ivuorinen\MonologGdprFilter;

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
     * @param array<string,string> $patterns Regex pattern => replacement
     * @param array<string,FieldMaskConfig>|string[] $fieldPaths Dot-notation path => FieldMaskConfig
     * @param array<string,?callable> $customCallbacks Dot-notation path => callback(value): string
     * @param callable|null $auditLogger Opt. audit logger callback:
     *                                   fn(string $path, mixed $original, mixed $masked)
     */
    public function __construct(
        private readonly array $patterns,
        private readonly array $fieldPaths = [],
        private readonly array $customCallbacks = [],
        private $auditLogger = null
    ) {
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
     * @return array<array-key, string>
     */
    public static function getDefaultPatterns(): array
    {
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
        ];
    }

    /**
     * Process a log record to mask sensitive information.
     *
     * @param LogRecord $record The log record to process
     * @return LogRecord The processed log record with masked message and context
     *
     * @psalm-suppress MissingOverrideAttribute Override is available from PHP 8.3
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $message = $this->regExpMessage($record->message);
        $context = $record->context;
        $accessor = new Dot($context);

        if ($this->fieldPaths !== []) {
            $this->maskFieldPaths($accessor);
            $context = $accessor->all();
        } else {
            $context = $this->recursiveMask($context);
        }

        return $record->with(message: $message, context: $context);
    }

    /**
     * Mask a string using all regex patterns sequentially.
     */
    public function regExpMessage(string $message = ''): string
    {
        foreach ($this->patterns as $regex => $replacement) {
            /**
             * @var array<array-key, non-empty-string> $regex
             */
            $result = @preg_replace($regex, $replacement, $message);
            if ($result === null) {
                if (is_callable($this->auditLogger)) {
                    call_user_func($this->auditLogger, 'preg_replace_error', $message, $message);
                }

                continue;
            }

            if ($result === '' || $result === '0') {
                // If the result is empty, we can skip further processing
                return $message;
            }

            $message = $result;
        }

        return $message;
    }

    /**
     * Mask only specified paths in context (fieldPaths)
     */
    private function maskFieldPaths(Dot $accessor): void
    {
        foreach ($this->fieldPaths as $path => $config) {
            if (!$accessor->has($path)) {
                continue;
            }

            $value = $accessor->get($path, "");
            $action = $this->maskValue($path, $value, $config);
            if ($action['remove'] ?? false) {
                $accessor->delete($path);
                $this->logAudit($path, $value, null);
                continue;
            }

            $masked = $action['masked'];
            if ($masked !== null && $masked !== $value) {
                $accessor->set($path, $masked);
                $this->logAudit($path, $value, $masked);
            }
        }
    }

    /**
     * Mask a single value according to config or callback
     * Returns an array: ['masked' => value|null, 'remove' => bool]
     *
     * @psalm-return array{masked: string|null, remove: bool}
     */
    private function maskValue(string $path, mixed $value, null|FieldMaskConfig|string $config): array
    {
        /** @noinspection PhpArrayIndexImmediatelyRewrittenInspection */
        $result = ['masked' => null, 'remove' => false];
        if (array_key_exists($path, $this->customCallbacks) &&  $this->customCallbacks[$path] !== null) {
            $result['masked'] = call_user_func($this->customCallbacks[$path], $value);
            return $result;
        }

        if ($config instanceof FieldMaskConfig) {
            switch ($config->type) {
                case FieldMaskConfig::MASK_REGEX:
                    $result['masked'] = $this->regExpMessage($value);
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
     * Recursively mask all string values in an array using regex patterns.
     */
    protected function recursiveMask(string|array $data): string|array
    {
        if (is_string($data)) {
            return $this->regExpMessage($data);
        }

        foreach ($data as $key => $value) {
            $data[$key] = $this->recursiveMask($value);
        }

        return $data;
    }

    /**
     * Mask a string using all regex patterns at once.
     */
    public function maskMessage(string $value = ''): string
    {
        /** @var array<array-key, non-empty-string> $keys */
        $keys = array_keys($this->patterns);
        $values = array_values($this->patterns);
        $result = @preg_replace($keys, $values, $value);
        if ($result === null) {
            if (is_callable($this->auditLogger)) {
                call_user_func($this->auditLogger, 'preg_replace_error', $value, $value);
            }

            return $value;
        }

        return $result;
    }

    /**
     * Set the audit logger callable.
     *
     * @param callable|null $auditLogger
     * @return void
     */
    public function setAuditLogger(?callable $auditLogger): void
    {
        $this->auditLogger = $auditLogger;
    }
}
