<?php

use Ivuorinen\MonologGdprFilter\GdprProcessor;

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
    'auto_register' => env('GDPR_AUTO_REGISTER', true),

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
    'max_depth' => env('GDPR_MAX_DEPTH', 100),

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
        'enabled' => env('GDPR_AUDIT_ENABLED', false),
        'channel' => env('GDPR_AUDIT_CHANNEL', 'gdpr-audit'),
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
        'chunk_size' => env('GDPR_CHUNK_SIZE', 1000),
        'garbage_collection_threshold' => env('GDPR_GC_THRESHOLD', 10000),
    ],
];