<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter;

use JsonException;

/**
 * Handles JSON structure detection and masking within log messages.
 *
 * This class provides methods to find JSON structures in strings,
 * parse them, apply masking, and re-encode them.
 */
final class JsonMasker
{
    /**
     * @param callable(array<mixed>|string, int=):array<mixed>|string $recursiveMaskCallback
     * @param callable(string, mixed, mixed):void|null $auditLogger
     */
    public function __construct(
        private $recursiveMaskCallback,
        private $auditLogger = null
    ) {}

    /**
     * Find and process JSON structures in the message.
     */
    public function processMessage(string $message): string
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
                    $processed = $this->processCandidate($jsonCandidate);
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
    public function extractBalancedStructure(string $message, int $startPos): ?string
    {
        $length = strlen($message);
        $startChar = $message[$startPos];
        $endChar = $startChar === '{' ? '}' : ']';
        $level = 0;
        $inString = false;
        $escaped = false;

        for ($i = $startPos; $i < $length; $i++) {
            $char = $message[$i];

            if ($this->isEscapedCharacter($escaped)) {
                $escaped = false;
                continue;
            }

            if ($this->isEscapeStart($char, $inString)) {
                $escaped = true;
                continue;
            }

            if ($char === '"') {
                $inString = !$inString;
                continue;
            }

            if ($inString) {
                continue;
            }

            $balancedEnd = $this->processStructureChar($char, $startChar, $endChar, $level, $message, $startPos, $i);
            if ($balancedEnd !== null) {
                return $balancedEnd;
            }
        }

        // No balanced structure found
        return null;
    }

    /**
     * Check if current character is escaped.
     */
    private function isEscapedCharacter(bool $escaped): bool
    {
        return $escaped;
    }

    /**
     * Check if current character starts an escape sequence.
     */
    private function isEscapeStart(string $char, bool $inString): bool
    {
        return $char === '\\' && $inString;
    }

    /**
     * Process a structure character (bracket or brace) and update nesting level.
     *
     * @return string|null Returns the extracted structure if complete, null otherwise
     */
    private function processStructureChar(
        string $char,
        string $startChar,
        string $endChar,
        int &$level,
        string $message,
        int $startPos,
        int $currentPos
    ): ?string {
        if ($char === $startChar) {
            $level++;
        } elseif ($char === $endChar) {
            $level--;

            if ($level === 0) {
                // Found complete balanced structure
                return substr($message, $startPos, $currentPos - $startPos + 1);
            }
        }

        return null;
    }

    /**
     * Process a potential JSON candidate string.
     */
    public function processCandidate(string $potentialJson): string
    {
        try {
            // Try to parse as JSON
            $decoded = json_decode($potentialJson, true, 512, JSON_THROW_ON_ERROR);

            // If successfully decoded, apply masking and re-encode
            if ($decoded !== null) {
                $masked = ($this->recursiveMaskCallback)($decoded, 0);
                $reEncoded = $this->encodePreservingEmptyObjects($masked, $potentialJson);

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
    public function encodePreservingEmptyObjects(array|string $data, string $originalJson): string|false
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
        return $this->fixEmptyObjects($encoded, $originalJson);
    }

    /**
     * Fix empty arrays that should be empty objects in the encoded JSON.
     */
    public function fixEmptyObjects(string $encoded, string $original): string
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
}
