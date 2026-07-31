<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter;

use Ivuorinen\MonologGdprFilter\Contracts\ArrayAccessorInterface;
use Ivuorinen\MonologGdprFilter\MaskConstants as Mask;
use Throwable;

/**
 * Handles context field processing operations for GDPR masking.
 *
 * This class extracts field-level masking logic from GdprProcessor
 * to reduce the main class's method count and improve separation of concerns.
 *
 * @internal This class is for internal use within the GDPR processor
 */
class ContextProcessor
{
    /**
     * @param array<string,FieldMaskConfig|string> $fieldPaths Dot-notation path => FieldMaskConfig
     * @param array<string,callable(mixed):string> $customCallbacks Dot-notation path => callback
     * @param callable(string,mixed,mixed):void|null $auditLogger Optional audit logger callback
     * @param \Closure(string):string $regexProcessor Function to process strings with regex patterns
     */
    public function __construct(
        private readonly array $fieldPaths,
        private readonly array $customCallbacks,
        private $auditLogger,
        private readonly \Closure $regexProcessor
    ) {
    }

    /**
     * Mask field paths in the context using the configured field masks.
     *
     * @param ArrayAccessorInterface $accessor
     * @return string[] Array of processed field paths
     * @psalm-return list<string>
     */
    public function maskFieldPaths(ArrayAccessorInterface $accessor): array
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
     * @param ArrayAccessorInterface $accessor
     * @param list<string> $alreadyProcessed Paths already handled by maskFieldPaths(),
     *                                       which runs the callback itself for any path
     *                                       configured in both places.
     * @return string[] Array of processed field paths
     * @psalm-return list<string>
     */
    public function processCustomCallbacks(ArrayAccessorInterface $accessor, array $alreadyProcessed = []): array
    {
        $processedFields = [];
        foreach ($this->customCallbacks as $path => $callback) {
            if (!$accessor->has($path) || in_array($path, $alreadyProcessed, true)) {
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
     * Mask a single value according to config or callback.
     * Returns an array: ['masked' => value|null, 'remove' => bool]
     *
     * @psalm-return array{masked: null|string, remove: bool}
     * @psalm-param mixed $value
     *
     * @return (bool|null|string)[]
     */
    public function maskValue(string $path, mixed $value, FieldMaskConfig|string|null $config): array
    {
        $result = ['masked' => null, 'remove' => false];
        if (array_key_exists($path, $this->customCallbacks)) {
            $callback = $this->customCallbacks[$path];

            try {
                $result['masked'] = $callback($value);
            } catch (Throwable $e) {
                // A throwing callback must not abort logging; mirror processCustomCallbacks.
                $sanitized = SecuritySanitizer::sanitizeErrorMessage($e->getMessage());
                $this->logAudit($path . '_callback_error', $value, 'Callback failed: ' . $sanitized);
                $result['masked'] = null;
            }

            return $result;
        }

        if ($config instanceof FieldMaskConfig) {
            switch ($config->type) {
                case FieldMaskConfig::MASK_REGEX:
                    $result['masked'] = $this->applyRegexMask($path, $config, $value);
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
     * Apply a MASK_REGEX config to a value.
     *
     * Honours a per-field pattern supplied via FieldMaskConfig::regexMask(); without
     * that the field would silently fall back to the processor-wide patterns and the
     * caller's custom pattern would be ignored. Arrays and objects are JSON-encoded
     * rather than cast, since (string) on an array yields the literal "Array".
     */
    private function applyRegexMask(string $path, FieldMaskConfig $config, mixed $value): string
    {
        $subject = match (true) {
            is_string($value) => $value,
            is_array($value), is_object($value) => $this->encodeForMasking($value),
            default => (string) $value,
        };

        $pattern = $config->getRegexPattern();
        if ($pattern === null || $pattern === '') {
            return ($this->regexProcessor)($subject);
        }

        $replacement = $config->getReplacement() ?? Mask::MASK_MASKED;
        $masked = preg_replace($pattern, $replacement, $subject);

        if ($masked === null) {
            // PCRE failed (bad UTF-8, backtrack limit, ...). This field was explicitly
            // configured for masking, so returning $subject would emit the raw value.
            // Fail closed by redacting it wholesale, and record why.
            $this->logAudit(
                $path . '_regex_mask_error',
                $value,
                'Masking failed, value redacted: ' . preg_last_error_msg()
            );

            return $replacement;
        }

        return $masked;
    }

    /**
     * JSON-encode a structured value so a regex can be applied to it.
     *
     * Returns an empty string when encoding fails (e.g. a resource or malformed UTF-8),
     * so the caller masks an empty value rather than leaking the original.
     */
    private function encodeForMasking(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);

        return $encoded === false ? '' : $encoded;
    }

    /**
     * Audit logger helper.
     *
     * @param string      $path     Dot-notation path of the field
     * @param mixed       $original Original value before masking
     * @param null|string $masked   Masked value after processing, or null if removed
     */
    public function logAudit(string $path, mixed $original, string|null $masked): void
    {
        if (is_callable($this->auditLogger) && $original !== $masked) {
            // Only log if the value was actually changed
            call_user_func($this->auditLogger, $path, $original, $masked);
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
