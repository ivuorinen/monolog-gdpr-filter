<?php

// Helper function for environment variables when Laravel is not available
if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return $_ENV[$key] ?? getenv($key) ?: $default;
    }
}

return [
    /*
    |--------------------------------------------------------------------------
    | Auto Registration
    |--------------------------------------------------------------------------
    |
    | Whether to automatically register the GDPR processor with Laravel's
    | logging system. If false, you'll need to manually register it.
    |
    */
    'auto_register' => filter_var(env('GDPR_AUTO_REGISTER', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Logging Channels
    |--------------------------------------------------------------------------
    |
    | Which logging channels should have GDPR processing applied.
    | Only used when auto_register is true.
    |
    */
    'channels' => [
        'single',
        'daily',
        'stack',
        // Add other channels as needed
    ],

    /*
    |--------------------------------------------------------------------------
    | GDPR Patterns
    |--------------------------------------------------------------------------
    |
    | Regex patterns for detecting and masking sensitive data.
    | Leave empty to use the default patterns, or add your own.
    |
    */
    'patterns' => [
        // Uncomment and customize as needed:
        // '/\bcustom-pattern\b/' => '***CUSTOM***',
    ],

    /*
    |--------------------------------------------------------------------------
    | Field Paths
    |--------------------------------------------------------------------------
    |
    | Dot-notation paths for field-specific masking/removal/replacement.
    | More efficient than regex patterns for known field locations.
    |
    */
    'field_paths' => [
        // Examples:
        // 'user.email' => '', // Mask with regex
        // 'user.ssn' => GdprProcessor::removeField(),
        // 'payment.card' => GdprProcessor::replaceWith('[CARD]'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Callbacks
    |--------------------------------------------------------------------------
    |
    | Custom masking functions for specific field paths.
    | Most flexible but slowest option.
    |
    */
    'custom_callbacks' => [
        // Examples:
        // 'user.name' => fn($value) => strtoupper($value),
        // 'metadata.ip' => fn($value) => hash('sha256', $value),
    ],

    /*
    |--------------------------------------------------------------------------
    | Recursion Depth Limit
    |--------------------------------------------------------------------------
    |
    | Maximum depth for recursive processing of nested arrays.
    | Prevents stack overflow on deeply nested data structures.
    |
    */
    'max_depth' => max(1, min(1000, (int) env('GDPR_MAX_DEPTH', 100))),

    /*
    |--------------------------------------------------------------------------
    | Audit Logging
    |--------------------------------------------------------------------------
    |
    | Configuration for audit logging of GDPR processing actions.
    | Useful for compliance tracking and debugging.
    |
    */
    'audit_logging' => [
        'enabled' => filter_var(env('GDPR_AUDIT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'channel' => trim((string) env('GDPR_AUDIT_CHANNEL', 'gdpr-audit')) ?: 'gdpr-audit',
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Settings
    |--------------------------------------------------------------------------
    |
    | Settings for optimizing performance with large datasets.
    |
    */
    'performance' => [
        'chunk_size' => max(100, min(10000, (int) env('GDPR_CHUNK_SIZE', 1000))),
        'garbage_collection_threshold' => max(1000, min(100000, (int) env('GDPR_GC_THRESHOLD', 10000))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Input Validation Settings
    |--------------------------------------------------------------------------
    |
    | Settings for input validation and security.
    |
    */
    'validation' => [
        'max_pattern_length' => max(10, min(1000, (int) env('GDPR_MAX_PATTERN_LENGTH', 500))),
        'max_field_path_length' => max(5, min(500, (int) env('GDPR_MAX_FIELD_PATH_LENGTH', 100))),
        'allow_empty_patterns' => filter_var(env('GDPR_ALLOW_EMPTY_PATTERNS', false), FILTER_VALIDATE_BOOLEAN),
        'strict_regex_validation' => filter_var(env('GDPR_STRICT_REGEX_VALIDATION', true), FILTER_VALIDATE_BOOLEAN),
    ],
];
