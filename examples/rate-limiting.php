<?php

use Monolog\LogRecord;

/**
 * Rate Limiting for Audit Logging Examples
 *
 * This file demonstrates how to use rate limiting to prevent
 * audit log flooding while maintaining system performance.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\RateLimitedAuditLogger;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Level;

// Example 1: Basic Rate-Limited Audit Logging
echo "=== Example 1: Basic Rate-Limited Audit Logging ===\n";

$auditLogs = [];
$baseAuditLogger = function (string $path, mixed $original, mixed $masked) use (&$auditLogs): void {
    $auditLogs[] = [
        'timestamp' => date('Y-m-d H:i:s'),
        'path' => $path,
        'original' => $original,
        'masked' => $masked
    ];
    echo sprintf('AUDIT: %s - %s -> %s%s', $path, $original, $masked, PHP_EOL);
};

// Wrap with rate limiting (100 per minute by default)
$rateLimitedLogger = new RateLimitedAuditLogger($baseAuditLogger, 5, 60); // 5 per minute for demo

$processor = new GdprProcessor(
    ['/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '***EMAIL***'],
    ['user.email' => 'masked@example.com'],
    [],
    $rateLimitedLogger
);

$logger = new Logger('rate-limited');
$logger->pushHandler(new StreamHandler('php://stdout', Level::Debug));
$logger->pushProcessor($processor);

// Simulate high-volume logging that would exceed rate limits
for ($i = 0; $i < 10; $i++) {
    $logger->info('User activity', [
        'user' => ['email' => sprintf('user%d@example.com', $i)],
        'action' => 'login'
    ]);
}

echo "\nTotal audit logs: " . count($auditLogs) . "\n";
echo "Expected: 5 regular logs + rate limit warnings\n\n";

// Example 2: Using Predefined Rate Limiting Profiles
echo "=== Example 2: Rate Limiting Profiles ===\n";

$auditLogs2 = [];
$baseLogger2 = GdprProcessor::createArrayAuditLogger($auditLogs2, false);

// Available profiles: 'strict', 'default', 'relaxed', 'testing'
$strictLogger = RateLimitedAuditLogger::create($baseLogger2, 'strict');    // 50/min
$relaxedLogger = RateLimitedAuditLogger::create($baseLogger2, 'relaxed');  // 200/min
$testingLogger = RateLimitedAuditLogger::create($baseLogger2, 'testing');  // 1000/min

echo "Strict profile: " . ($strictLogger->isOperationAllowed('general_operations') ? 'Available' : 'Rate limited') . "\n";
echo "Relaxed profile: " . ($relaxedLogger->isOperationAllowed('general_operations') ? 'Available' : 'Rate limited') . "\n";
echo "Testing profile: " . ($testingLogger->isOperationAllowed('general_operations') ? 'Available' : 'Rate limited') . "\n\n";

// Example 3: Using GdprProcessor Helper Methods
echo "=== Example 3: GdprProcessor Helper Methods ===\n";

$auditLogs3 = [];
// Create rate-limited logger using GdprProcessor helper
$rateLimitedAuditLogger = GdprProcessor::createRateLimitedAuditLogger(
    GdprProcessor::createArrayAuditLogger($auditLogs3, false),
    'default'
);

$processor3 = new GdprProcessor(
    ['/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '***EMAIL***'],
    ['sensitive_data' => '***REDACTED***'],
    [],
    $rateLimitedAuditLogger
);

// Process some logs
for ($i = 0; $i < 3; $i++) {
    $logRecord = new LogRecord(
        new DateTimeImmutable(),
        'app',
        Level::Info,
        sprintf('Processing user%d@example.com', $i),
        ['sensitive_data' => 'secret_value_' . $i]
    );

    $result = $processor3($logRecord);
    echo "Processed: " . $result->message . "\n";
}

echo "Audit logs generated: " . count($auditLogs3) . "\n\n";

// Example 4: Rate Limit Statistics and Monitoring
echo "=== Example 4: Rate Limit Statistics ===\n";

$rateLimitedLogger4 = new RateLimitedAuditLogger($baseAuditLogger, 10, 60);

// Generate some activity
for ($i = 0; $i < 5; $i++) {
    $rateLimitedLogger4('test_operation', 'value_' . $i, 'masked_' . $i);
}

// Check statistics
$stats = $rateLimitedLogger4->getRateLimitStats();
echo "Rate Limit Statistics:\n";
foreach ($stats as $operationType => $stat) {
    if ($stat['current_requests'] > 0) {
        echo "  {$operationType}:\n";
        echo sprintf('    Current requests: %d%s', $stat['current_requests'], PHP_EOL);
        echo sprintf('    Remaining requests: %d%s', $stat['remaining_requests'], PHP_EOL);
        echo "    Time until reset: {$stat['time_until_reset']} seconds\n";
    }
}

echo "\n";

// Example 5: Different Operation Types
echo "=== Example 5: Operation Type Classification ===\n";

$rateLimitedLogger5 = new RateLimitedAuditLogger($baseAuditLogger, 2, 60); // Very restrictive

echo "Testing different operation types (2 per minute limit):\n";

// These will be classified into different operation types
$rateLimitedLogger5('json_masked', '{"key": "value"}', '{"key": "***MASKED***"}');
$rateLimitedLogger5('conditional_skip', 'skip_reason', 'Level not matched');
$rateLimitedLogger5('regex_error', '/invalid[/', 'Pattern compilation failed');
$rateLimitedLogger5('preg_replace_error', 'input', 'PCRE error occurred');

// Try to exceed limits for each type
echo "\nTesting rate limiting per operation type:\n";
$rateLimitedLogger5('json_encode_error', 'data', 'JSON encoding failed');    // json_operations
$rateLimitedLogger5('json_decode_error', 'data', 'JSON decoding failed');    // json_operations (should be limited)
$rateLimitedLogger5('conditional_error', 'rule', 'Rule evaluation failed');  // conditional_operations
$rateLimitedLogger5('regex_validation', 'pattern', 'Pattern is invalid');    // regex_operations

echo "\nOperation type stats:\n";
$stats5 = $rateLimitedLogger5->getRateLimitStats();
foreach ($stats5 as $type => $stat) {
    if ($stat['current_requests'] > 0) {
        $current = $stat['current_requests'];
        $all = $stat['current_requests'] + $stat['remaining_requests'];
        echo "  {$type}: {$current}/{$all} used\n";
    }
}

echo "\n=== Rate Limiting Examples Completed ===\n";
echo "\nKey Benefits:\n";
echo "• Prevents audit log flooding during high-volume operations\n";
echo "• Maintains system performance by limiting resource usage\n";
echo "• Provides configurable rate limits for different environments\n";
echo "• Separate rate limits for different operation types\n";
echo "• Built-in statistics and monitoring capabilities\n";
echo "• Graceful degradation with rate limit warnings\n";
