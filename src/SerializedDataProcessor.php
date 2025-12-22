<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter;

/**
 * Processes serialized data formats like print_r, var_export, and serialize output.
 *
 * Detects and masks sensitive data within:
 * - print_r() output: Array (key => value) format
 * - var_export() output: array('key' => 'value') format
 * - serialize() output: a:1:{s:3:"key";s:5:"value";}
 * - json_encode() output: {"key":"value"}
 *
 * @api
 */
final class SerializedDataProcessor
{
    /**
     * @var callable(string):string
     */
    private $stringMasker;

    /**
     * @var callable(string,mixed,mixed):void|null
     */
    private $auditLogger;

    /**
     * @param callable(string):string $stringMasker Function to mask strings
     * @param callable(string,mixed,mixed):void|null $auditLogger Optional audit logger
     */
    public function __construct(
        callable $stringMasker,
        ?callable $auditLogger = null
    ) {
        $this->stringMasker = $stringMasker;
        $this->auditLogger = $auditLogger;
    }

    /**
     * Process a message that may contain serialized data.
     *
     * Automatically detects the format and applies masking.
     *
     * @param string $message The message to process
     * @return string The processed message with masked data
     */
    public function process(string $message): string
    {
        if ($message === '') {
            return $message;
        }

        // Try to detect and process JSON embedded in the message
        $message = $this->processEmbeddedJson($message);

        // Try to detect and process print_r output
        $message = $this->processPrintROutput($message);

        // Try to detect and process var_export output
        $message = $this->processVarExportOutput($message);

        // Try to detect and process serialize output
        $message = $this->processSerializeOutput($message);

        return $message;
    }

    /**
     * Process embedded JSON strings in the message.
     */
    private function processEmbeddedJson(string $message): string
    {
        // Match JSON objects and arrays
        $pattern = '/(\{(?:[^{}]|(?1))*\}|\[(?:[^\[\]]|(?1))*\])/';

        return (string) preg_replace_callback($pattern, function (array $matches): string {
            $json = $matches[0];

            // Try to decode
            $decoded = json_decode($json, true);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                // Not valid JSON, return as-is
                return $json;
            }

            // Process the decoded data
            $masked = $this->maskRecursive($decoded, 'json');

            // Re-encode
            $result = json_encode($masked, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return $result === false ? $json : $result;
        }, $message);
    }

    /**
     * Process print_r output format.
     *
     * Example format:
     * Array
     * (
     *     [key] => value
     *     [nested] => Array
     *         (
     *             [inner] => data
     *         )
     * )
     */
    private function processPrintROutput(string $message): string
    {
        // Check if message contains print_r style output
        if (!preg_match('/Array\s*\(\s*\[/s', $message)) {
            return $message;
        }

        // Process key => value pairs in print_r format
        // Match: [key] => string_value (including multi-line values)
        $pattern = '/(\[\S+\])\s*=>\s*([^\n\[]+)/';

        return (string) preg_replace_callback($pattern, function (array $matches): string {
            $key = trim($matches[1], '[]');
            $value = trim($matches[2]);

            // Skip if value looks like "Array" (nested structure)
            if ($value === 'Array') {
                return $matches[0];
            }

            $masked = ($this->stringMasker)($value);

            if ($masked !== $value) {
                $this->logAudit("print_r.{$key}", $value, $masked);
                return "[{$key}] => {$masked}";
            }

            return $matches[0];
        }, $message);
    }

    /**
     * Process var_export output format.
     *
     * Example format:
     * array (
     *   'key' => 'value',
     *   'nested' => array (
     *     'inner' => 'data',
     *   ),
     * )
     */
    private function processVarExportOutput(string $message): string
    {
        // Check if message contains var_export style output
        if (!preg_match('/array\s*\(\s*[\'"]?\w+[\'"]?\s*=>/s', $message)) {
            return $message;
        }

        // Process 'key' => 'value' pairs
        $pattern = "/(['\"])(\w+)\\1\s*=>\s*(['\"])([^'\"]+)\\3/";

        return (string) preg_replace_callback($pattern, function (array $matches): string {
            $keyQuote = $matches[1];
            $key = $matches[2];
            $valueQuote = $matches[3];
            $value = $matches[4];

            $masked = ($this->stringMasker)($value);

            if ($masked !== $value) {
                $this->logAudit("var_export.{$key}", $value, $masked);
                return "{$keyQuote}{$key}{$keyQuote} => {$valueQuote}{$masked}{$valueQuote}";
            }

            return $matches[0];
        }, $message);
    }

    /**
     * Process PHP serialize() output format.
     *
     * Example format: a:1:{s:3:"key";s:5:"value";}
     */
    private function processSerializeOutput(string $message): string
    {
        // Check if message contains serialize style output
        if (!preg_match('/[aOCs]:\d+:/s', $message)) {
            return $message;
        }

        // Match serialized strings: s:length:"value";
        $pattern = '/s:(\d+):"([^"]*)";/';

        return (string) preg_replace_callback($pattern, function (array $matches): string {
            $originalLength = (int) $matches[1];
            $value = $matches[2];

            // Verify the length matches
            if (strlen($value) !== $originalLength) {
                return $matches[0];
            }

            $masked = ($this->stringMasker)($value);

            if ($masked !== $value) {
                $newLength = strlen($masked);
                $this->logAudit('serialize.string', $value, $masked);
                return "s:{$newLength}:\"{$masked}\";";
            }

            return $matches[0];
        }, $message);
    }

    /**
     * Recursively mask values in an array.
     *
     * @param mixed $data The data to mask
     * @param string $path Current path for audit logging
     * @return mixed The masked data
     */
    private function maskRecursive(mixed $data, string $path): mixed
    {
        if (is_string($data)) {
            $masked = ($this->stringMasker)($data);
            if ($masked !== $data) {
                $this->logAudit($path, $data, $masked);
            }
            return $masked;
        }

        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                $newPath = $path . '.' . $key;
                $result[$key] = $this->maskRecursive($value, $newPath);
            }
            return $result;
        }

        return $data;
    }

    /**
     * Log audit event if logger is configured.
     */
    private function logAudit(string $path, string $original, string $masked): void
    {
        if ($this->auditLogger !== null) {
            ($this->auditLogger)($path, $original, $masked);
        }
    }

    /**
     * Set the audit logger.
     *
     * @param callable(string,mixed,mixed):void|null $auditLogger
     */
    public function setAuditLogger(?callable $auditLogger): void
    {
        $this->auditLogger = $auditLogger;
    }
}
