<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter;

use Ivuorinen\MonologGdprFilter\MaskConstants as Mask;

/**
 * Sanitizes error messages to prevent information disclosure.
 *
 * This class removes sensitive information from error messages
 * before they are logged to prevent security vulnerabilities.
 */
final class SecuritySanitizer
{
    /**
     * Sanitize error messages to prevent information disclosure.
     *
     * @param string $message The original error message
     * @return string The sanitized error message
     */
    public static function sanitizeErrorMessage(string $message): string
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
            '/postgresql:\/\/[^@]*@[\w\.-]+:\d+/i' => 'postgresql://***:***@***:***',

            // JWT secrets and other secrets (enhanced to catch more patterns)
            '/secret[_-]?key[=:\s]+\S+/i' => 'secret_key=***',
            '/jwt[_-]?secret[=:\s]+\S+/i' => 'jwt_secret=***',
            '/\bsuper_secret_\w+/i' => Mask::MASK_SECRET,

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

    /** @psalm-suppress UnusedConstructor */
    private function __construct()
    {}
}
