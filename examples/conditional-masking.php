<?php

/**
 * Conditional Masking Examples
 * 
 * This file demonstrates various ways to use conditional masking
 * to apply GDPR processing only when certain conditions are met.
 */

require_once '../vendor/autoload.php';

use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Level;

// Example 1: Level-based conditional masking
// Only mask sensitive data in ERROR and CRITICAL logs
echo "=== Example 1: Level-based Conditional Masking ===\n";

$levelBasedProcessor = new GdprProcessor(
    ['/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '***EMAIL***'],
    [],
    [],
    null,
    100,
    [],
    [
        'error_levels_only' => GdprProcessor::createLevelBasedRule(['Error', 'Critical'])
    ]
);

$logger = new Logger('example');
$logger->pushHandler(new StreamHandler('php://stdout', Level::Debug));
$logger->pushProcessor($levelBasedProcessor);

$logger->info('User john@example.com logged in successfully');  // Email NOT masked
$logger->error('Failed login attempt for admin@company.com');   // Email WILL be masked

echo "\n";

// Example 2: Channel-based conditional masking
// Only mask data in security and audit channels
echo "=== Example 2: Channel-based Conditional Masking ===\n";

$channelBasedProcessor = new GdprProcessor(
    ['/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '***EMAIL***'],
    [],
    [],
    null,
    100,
    [],
    [
        'security_channels' => GdprProcessor::createChannelBasedRule(['security', 'audit'])
    ]
);

$securityLogger = new Logger('security');
$securityLogger->pushHandler(new StreamHandler('php://stdout', Level::Debug));
$securityLogger->pushProcessor($channelBasedProcessor);

$appLogger = new Logger('application');
$appLogger->pushHandler(new StreamHandler('php://stdout', Level::Debug));
$appLogger->pushProcessor($channelBasedProcessor);

$securityLogger->info('Security event: user@example.com accessed admin panel'); // WILL be masked
$appLogger->info('Application event: user@example.com placed order');            // NOT masked

echo "\n";

// Example 3: Context-based conditional masking
// Only mask when specific fields are present in context
echo "=== Example 3: Context-based Conditional Masking ===\n";

$contextBasedProcessor = new GdprProcessor(
    ['/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '***EMAIL***'],
    [],
    [],
    null,
    100,
    [],
    [
        'gdpr_consent_required' => GdprProcessor::createContextFieldRule('user.gdpr_consent')
    ]
);

$contextLogger = new Logger('context');
$contextLogger->pushHandler(new StreamHandler('php://stdout', Level::Debug));
$contextLogger->pushProcessor($contextBasedProcessor);

// This will be masked because gdpr_consent field is present
$contextLogger->info('User action performed', [
    'email' => 'user@example.com',
    'user' => ['id' => 123, 'gdpr_consent' => true]
]);

// This will NOT be masked because gdpr_consent field is missing
$contextLogger->info('System action performed', [
    'email' => 'system@example.com',
    'user' => ['id' => 1]
]);

echo "\n";

// Example 4: Environment-based conditional masking
// Only mask in production environment
echo "=== Example 4: Environment-based Conditional Masking ===\n";

$envBasedProcessor = new GdprProcessor(
    ['/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '***EMAIL***'],
    [],
    [],
    null,
    100,
    [],
    [
        'production_only' => GdprProcessor::createContextValueRule('env', 'production')
    ]
);

$envLogger = new Logger('env');
$envLogger->pushHandler(new StreamHandler('php://stdout', Level::Debug));
$envLogger->pushProcessor($envBasedProcessor);

// This will be masked because env=production
$envLogger->info('Production log entry', [
    'email' => 'prod@example.com',
    'env' => 'production'
]);

// This will NOT be masked because env=development
$envLogger->info('Development log entry', [
    'email' => 'dev@example.com',
    'env' => 'development'
]);

echo "\n";

// Example 5: Multiple conditional rules (AND logic)
// Only mask when ALL conditions are met
echo "=== Example 5: Multiple Conditional Rules ===\n";

$multiRuleProcessor = new GdprProcessor(
    ['/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '***EMAIL***'],
    [],
    [],
    null,
    100,
    [],
    [
        'error_level' => GdprProcessor::createLevelBasedRule(['Error', 'Critical']),
        'production_env' => GdprProcessor::createContextValueRule('env', 'production'),
        'security_channel' => GdprProcessor::createChannelBasedRule(['security'])
    ]
);

$multiLogger = new Logger('security');
$multiLogger->pushHandler(new StreamHandler('php://stdout', Level::Debug));
$multiLogger->pushProcessor($multiRuleProcessor);

// This WILL be masked - all conditions met: Error level + production env + security channel
$multiLogger->error('Security error in production', [
    'email' => 'admin@example.com',
    'env' => 'production'
]);

// This will NOT be masked - wrong level (Info instead of Error)
$multiLogger->info('Security info in production', [
    'email' => 'admin@example.com',
    'env' => 'production'
]);

echo "\n";

// Example 6: Custom conditional rule
// Create a custom rule based on complex logic
echo "=== Example 6: Custom Conditional Rule ===\n";

$customRule = function (Monolog\LogRecord $record): bool {
    // Only mask for high-privilege users (user_id > 1000) during business hours
    $context = $record->context;
    $isHighPrivilegeUser = isset($context['user_id']) && $context['user_id'] > 1000;
    $isBusinessHours = (int)date('H') >= 9 && (int)date('H') <= 17;
    
    return $isHighPrivilegeUser && $isBusinessHours;
};

$customProcessor = new GdprProcessor(
    ['/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '***EMAIL***'],
    [],
    [],
    null,
    100,
    [],
    [
        'high_privilege_business_hours' => $customRule
    ]
);

$customLogger = new Logger('custom');
$customLogger->pushHandler(new StreamHandler('php://stdout', Level::Debug));
$customLogger->pushProcessor($customProcessor);

// This will be masked if user_id > 1000 AND it's business hours
$customLogger->info('High privilege user action', [
    'email' => 'admin@example.com',
    'user_id' => 1001,
    'action' => 'delete_user'
]);

// This will NOT be masked (user_id <= 1000)
$customLogger->info('Regular user action', [
    'email' => 'user@example.com',
    'user_id' => 500,
    'action' => 'view_profile'
]);

echo "\n";

// Example 7: Combining conditional masking with data type masking
echo "=== Example 7: Conditional + Data Type Masking ===\n";

$combinedProcessor = new GdprProcessor(
    ['/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '***EMAIL***'],
    [],
    [],
    null,
    100,
    [
        'integer' => '***INT***',
        'string' => '***STRING***'
    ],
    [
        'error_level' => GdprProcessor::createLevelBasedRule(['Error'])
    ]
);

$combinedLogger = new Logger('combined');
$combinedLogger->pushHandler(new StreamHandler('php://stdout', Level::Debug));
$combinedLogger->pushProcessor($combinedProcessor);

// ERROR level: both regex patterns AND data type masking will be applied
$combinedLogger->error('Error occurred', [
    'email' => 'error@example.com',    // Will be masked by regex
    'user_id' => 12345,                // Will be masked by data type rule
    'message' => 'Something went wrong' // Will be masked by data type rule
]);

// INFO level: no masking will be applied due to conditional rule
$combinedLogger->info('Info message', [
    'email' => 'info@example.com',     // Will NOT be masked
    'user_id' => 67890,                // Will NOT be masked
    'message' => 'Everything is fine'  // Will NOT be masked
]);

echo "\nConditional masking examples completed.\n";