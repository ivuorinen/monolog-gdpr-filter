# Troubleshooting Guide

This guide helps diagnose and resolve common issues with the Monolog GDPR Filter library.

## Table of Contents

- [Installation Issues](#installation-issues)
- [Pattern Matching Problems](#pattern-matching-problems)
- [Performance Issues](#performance-issues)
- [Memory Problems](#memory-problems)
- [Integration Issues](#integration-issues)
- [Audit Logging Issues](#audit-logging-issues)
- [Error Messages Reference](#error-messages-reference)

## Installation Issues

### Composer Installation Fails

**Symptom:** `composer require` fails with dependency conflicts.

**Solution:**

```bash
# Check PHP version
php -v  # Must be 8.2 or higher

# Clear Composer cache
composer clear-cache

# Update Composer
composer self-update

# Try again with verbose output
composer require ivuorinen/monolog-gdpr-filter -vvv
```

### Class Not Found Errors

**Symptom:** `Class 'Ivuorinen\MonologGdprFilter\GdprProcessor' not found`

**Solutions:**

1. Regenerate autoloader:
```bash
composer dump-autoload
```

2. Verify installation:
```bash
composer show ivuorinen/monolog-gdpr-filter
```

3. Check namespace in your code:
```php
<?php
// Correct
use Ivuorinen\MonologGdprFilter\GdprProcessor;

// Wrong
use MonologGdprFilter\GdprProcessor;
```

## Pattern Matching Problems

### Pattern Not Matching Expected Data

**Symptom:** Sensitive data is not being masked.

**Diagnostic steps:**

```php
<?php

use Ivuorinen\MonologGdprFilter\PatternValidator;

$validator = new PatternValidator();
$pattern = '/your-pattern-here/';

// Test 1: Validate pattern syntax
$result = $validator->validate($pattern);
if (!$result['valid']) {
    echo "Invalid pattern: " . $result['error'] . "\n";
}

// Test 2: Test pattern directly
$testData = 'your test data with sensitive@email.com';
if (preg_match($pattern, $testData, $matches)) {
    echo "Pattern matches: " . print_r($matches, true);
} else {
    echo "Pattern does not match\n";
}

// Test 3: Test with processor
$processor = new GdprProcessor([$pattern => '[MASKED]']);
$record = [
    'message' => $testData,
    'context' => [],
    'level' => 200,
    'level_name' => 'INFO',
    'channel' => 'app',
    'datetime' => new DateTimeImmutable(),
    'extra' => [],
];

$result = $processor($record);
echo "Result: " . $result['message'] . "\n";
```

### Pattern Matches Too Much

**Symptom:** Non-sensitive data is being masked.

**Solutions:**

1. Add word boundaries:
```php
<?php
// Too broad
$pattern = '/\d{4}/';  // Matches any 4 digits

// Better - with boundaries
$pattern = '/\b\d{4}\b/';  // Matches standalone 4-digit numbers
```

2. Use more specific patterns:
```php
<?php
// Too broad for credit cards
$pattern = '/\d{16}/';

// Better - credit card format
$pattern = '/\b(?:\d{4}[-\s]?){3}\d{4}\b/';
```

3. Add negative lookahead/lookbehind:
```php
<?php
// Avoid matching dates that look like years
$pattern = '/(?<!\d{2}\/)\b\d{4}\b(?!\/\d{2})/';
```

### Special Characters in Patterns

**Symptom:** Pattern with special characters fails.

**Solution:** Escape special regex characters:

```php
<?php
// Wrong - unescaped special chars
$pattern = '/user.name@domain.com/';

// Correct - escaped dots
$pattern = '/user\.name@domain\.com/';

// Using preg_quote for dynamic patterns
$email = 'user.name@domain.com';
$pattern = '/' . preg_quote($email, '/') . '/';
```

## Performance Issues

### Slow Processing

**Symptom:** Log processing is slower than expected.

**Diagnostic:**

```php
<?php

$start = microtime(true);
for ($i = 0; $i < 1000; $i++) {
    $processor($record);
}
$elapsed = microtime(true) - $start;
echo "1000 records: {$elapsed}s\n";
```

**Solutions:**

1. Reduce pattern count:
```php
<?php
// Only include patterns you need
$patterns = DefaultPatterns::emails() + DefaultPatterns::creditCards();
```

2. Simplify complex patterns:
```php
<?php
// Slow: Complex pattern with many alternatives
$slow = '/(january|february|march|april|may|june|july|august|september|october|november|december)/i';

// Faster: Simpler pattern
$fast = '/\b[A-Z][a-z]{2,8}\b/';
```

3. Limit recursion depth:
```php
<?php
$processor = new GdprProcessor($patterns, [], [], null, 5);  // Max depth 5
```

See [Performance Tuning Guide](performance-tuning.md) for detailed optimization strategies.

### High CPU Usage

**Symptom:** Processing causes CPU spikes.

**Solutions:**

1. Check for catastrophic backtracking:
```php
<?php
// Problematic pattern
$bad = '/.*@.*\..*/';  // Can cause backtracking

// Fixed pattern
$good = '/[^@]+@[^.]+\.[a-z]+/i';
```

2. Add pattern timeout (PHP 7.3+):
```php
<?php
// Set PCRE backtrack limit
ini_set('pcre.backtrack_limit', '100000');
```

## Memory Problems

### Out of Memory Errors

**Symptom:** `Allowed memory size exhausted`

**Solutions:**

1. Use streaming for large files:
```php
<?php
use Ivuorinen\MonologGdprFilter\Streaming\StreamingProcessor;
use Ivuorinen\MonologGdprFilter\MaskingOrchestrator;

$orchestrator = new MaskingOrchestrator($patterns);
$streaming = new StreamingProcessor($orchestrator, chunkSize: 100);

// Process file without loading entirely into memory
$lineParser = fn(string $line): array => ['message' => $line, 'context' => []];
foreach ($streaming->processFile($largefile, $lineParser) as $record) {
    // Process one record at a time
}
```

2. Reduce recursion depth:
```php
<?php
$processor = new GdprProcessor($patterns, [], [], null, 3);
```

3. Disable audit logging:
```php
<?php
$processor = new GdprProcessor($patterns, [], [], null);  // No audit logger
```

### Memory Leaks

**Symptom:** Memory usage grows over time in long-running processes.

**Solutions:**

1. Clear caches periodically:
```php
<?php
// In long-running workers
if ($processedCount % 10000 === 0) {
    gc_collect_cycles();
}
```

2. Use fresh processor instances for batch jobs:
```php
<?php
foreach ($batches as $batch) {
    $processor = new GdprProcessor($patterns);  // Fresh instance
    foreach ($batch as $record) {
        $processor($record);
    }
    unset($processor);  // Release memory
}
```

## Integration Issues

### Laravel Integration

**Symptom:** Processor not being applied to logs.

**Solutions:**

1. Verify service provider registration:
```php
<?php
// config/app.php
'providers' => [
    Ivuorinen\MonologGdprFilter\Laravel\GdprServiceProvider::class,
],
```

2. Check logging configuration:
```php
<?php
// config/logging.php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['gdpr'],
    ],
    'gdpr' => [
        'driver' => 'single',
        'path' => storage_path('logs/laravel.log'),
        'tap' => [GdprLogTap::class],
    ],
],
```

3. Clear config cache:
```bash
php artisan config:clear
php artisan cache:clear
```

### Monolog Integration

**Symptom:** Processor not working with Monolog logger.

**Solution:** Ensure processor is pushed to logger:

```php
<?php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Ivuorinen\MonologGdprFilter\GdprProcessor;

$logger = new Logger('app');
$logger->pushHandler(new StreamHandler('app.log'));
$logger->pushProcessor(new GdprProcessor(DefaultPatterns::all()));

// Test it
$logger->info('User email: test@example.com');
```

### Symfony Integration

See [Symfony Integration Guide](symfony-integration.md) for detailed setup.

## Audit Logging Issues

### Audit Logger Not Receiving Events

**Symptom:** Audit callback never called.

**Solutions:**

1. Verify audit logger is set:
```php
<?php
$auditLogs = [];
$auditLogger = function (string $path, mixed $original, mixed $masked) use (&$auditLogs): void {
    $auditLogs[] = compact('path', 'original', 'masked');
};

$processor = new GdprProcessor(
    patterns: $patterns,
    auditLogger: $auditLogger
);
```

2. Verify masking is actually occurring:
```php
<?php
// Audit is only called when data is actually masked
$record = ['message' => 'No sensitive data here', 'context' => []];
// This won't trigger audit because nothing is masked
```

### Rate-Limited Audit Missing Events

**Symptom:** Some audit events are being dropped.

**Solution:** Adjust rate limit settings:

```php
<?php
use Ivuorinen\MonologGdprFilter\RateLimiter;
use Ivuorinen\MonologGdprFilter\RateLimitedAuditLogger;

$rateLimiter = new RateLimiter(
    maxEvents: 1000,     // Increase limit
    windowSeconds: 60,
    burstLimit: 100      // Increase burst
);

$rateLimitedLogger = new RateLimitedAuditLogger($baseLogger, $rateLimiter);
```

## Error Messages Reference

### InvalidRegexPatternException

**Message:** `Invalid regex pattern: [pattern]`

**Cause:** The pattern has invalid regex syntax.

**Solution:**
```php
<?php
// Test pattern before using
$pattern = '/[invalid/';
if (@preg_match($pattern, '') === false) {
    echo "Invalid pattern: " . preg_last_error_msg();
}
```

### RecursionDepthExceededException

**Message:** `Maximum recursion depth exceeded`

**Cause:** Nested data structure exceeds max depth.

**Solutions:**
```php
<?php
// Increase max depth
$processor = new GdprProcessor($patterns, [], [], null, 20);

// Or flatten your data before processing
$flatContext = iterator_to_array(
    new RecursiveIteratorIterator(
        new RecursiveArrayIterator($context)
    ),
    false
);
```

### MaskingOperationFailedException

**Message:** `Masking operation failed: [details]`

**Cause:** An error occurred during masking.

**Solution:** Enable recovery mode:
```php
<?php
use Ivuorinen\MonologGdprFilter\Recovery\FallbackMaskStrategy;
use Ivuorinen\MonologGdprFilter\Recovery\FailureMode;

$fallback = new FallbackMaskStrategy(FailureMode::FAIL_SAFE);
// Use with your processor
```

### InvalidConfigurationException

**Message:** `Invalid configuration: [details]`

**Cause:** Invalid processor configuration.

**Solution:** Validate configuration:
```php
<?php
use Ivuorinen\MonologGdprFilter\Builder\GdprProcessorBuilder;

try {
    $processor = (new GdprProcessorBuilder())
        ->addPattern('/valid-pattern/', '[MASKED]')
        ->build();
} catch (InvalidConfigurationException $e) {
    echo "Configuration error: " . $e->getMessage();
}
```

## Getting Help

If you're still experiencing issues:

1. **Check the tests:** The test suite contains many usage examples:
   ```bash
   ls tests/
   ```

2. **Enable debug mode:** Add verbose logging:
   ```php
   <?php
   $auditLogger = function ($path, $original, $masked): void {
       error_log("GDPR Mask: $path | $original -> $masked");
   };
   ```

3. **Report issues:** Open an issue on GitHub with:
   - PHP version (`php -v`)
   - Library version (`composer show ivuorinen/monolog-gdpr-filter`)
   - Minimal reproduction code
   - Expected vs actual behavior
