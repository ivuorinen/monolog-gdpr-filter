# Monolog GDPR Filter

Monolog GDPR Filter is a PHP library that provides a Monolog processor for GDPR compliance. It allows masking, removing,
or replacing sensitive data in logs using regex patterns, field-level configuration, and custom callbacks. Designed for
easy integration with Monolog and Laravel.

## Features

- **Regex-based masking** for patterns like SSNs, credit cards, emails
- **Field-level masking/removal/replacement** using dot-notation paths
- **Custom callbacks** for advanced masking logic per field
- **Audit logging** for compliance tracking
- **Easy integration with Monolog and Laravel**

## Installation

Install via Composer:

```bash
composer require ivuorinen/monolog-gdpr-filter
```

## Usage

### Basic Monolog Setup

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\FieldMaskConfig;

$patterns = GdprProcessor::getDefaultPatterns();
$fieldPaths = [
    'user.ssn' => GdprProcessor::removeField(),
    'payment.card' => GdprProcessor::replaceWith('[CC]'),
    'contact.email' => GdprProcessor::maskWithRegex(),
    'metadata.session' => GdprProcessor::replaceWith('[SESSION]'),
];

// Optional: custom callback for advanced masking
$customCallbacks = [
    'user.name' => fn($value) => strtoupper($value),
];

// Optional: audit logger for compliance
$auditLogger = function($path, $original, $masked) {
    error_log("GDPR mask: $path: $original => $masked");
};

$logger = new Logger('app');
$logger->pushHandler(new StreamHandler('path/to/your.log', Logger::WARNING));
$logger->pushProcessor(
    new GdprProcessor($patterns, $fieldPaths, $customCallbacks, $auditLogger)
);

$logger->warning('This is a warning message.', [
    'user' => ['ssn' => '123456-900T'],
    'contact' => ['email' => 'user@example.com'],
    'payment' => ['card' => '1234567812345678'],
]);
```

### FieldMaskConfig Options

- `GdprProcessor::maskWithRegex()` — Mask field value using regex patterns
- `GdprProcessor::removeField()` — Remove field from context
- `GdprProcessor::replaceWith($value)` — Replace field value with static value

### Custom Callbacks

Provide custom callbacks for specific fields:

```php
$customCallbacks = [
    'user.name' => fn($value) => strtoupper($value),
];
```

### Audit Logger

Optionally provide an audit logger callback to record masking actions:

```php
$auditLogger = function($path, $original, $masked) {
    // Log or store audit info
};
```

> **IMPORTANT**: Be mindful what you send to your audit log. Passing the original value might defeat the whole purpose
> of this project.

## Laravel Integration

You can integrate the GDPR processor with Laravel logging in two ways:

### 1. Service Provider

```php
// app/Providers/AppServiceProvider.php
use Illuminate\Support\ServiceProvider;
use Ivuorinen\MonologGdprFilter\GdprProcessor;

class AppServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $patterns = GdprProcessor::getDefaultPatterns();
        $fieldPaths = [
            'user.ssn' => '[GDPR]',
            'payment.card' => '[CC]',
            'contact.email' => '', // empty string = regex mask
            'metadata.session' => '[SESSION]',
        ];
        $this->app['log']->getLogger()
            ->pushProcessor(new GdprProcessor($patterns, $fieldPaths));
    }
}
```

### 2. Tap Class (config/logging.php)

```php
// app/Logging/GdprTap.php
namespace App\Logging;
use Monolog\Logger;
use Ivuorinen\MonologGdprFilter\GdprProcessor;

class GdprTap
{
    public function __invoke(Logger $logger)
    {
        $patterns = GdprProcessor::getDefaultPatterns();
        $fieldPaths = [
            'user.ssn' => '[GDPR]',
            'payment.card' => '[CC]',
            'contact.email' => '',
            'metadata.session' => '[SESSION]',
        ];
        $logger->pushProcessor(new GdprProcessor($patterns, $fieldPaths));
    }
}
```

Reference in `config/logging.php`:

```php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['single'],
        'tap' => [App\Logging\GdprTap::class],
    ],
    // ...
],
```

## Configuration

You can configure the processor to filter out sensitive data by specifying:

- **Regex patterns:** Used for masking values in messages and context
- **Field paths:** Dot-notation paths for masking/removal/replacement
- **Custom callbacks:** For advanced per-field masking
- **Audit logger:** For compliance tracking

## Testing & Quality

This project uses PHPUnit for testing, Psalm and PHPStan for static analysis, and PHP_CodeSniffer for code style checks.

### Running Tests

To run the test suite:

```bash
composer test
```

To generate a code coverage report (HTML output in the `coverage/` directory):

```bash
composer test:coverage
```

### Linting & Static Analysis

To run all linters and static analysis:

```bash
composer lint
```

To automatically fix code style and static analysis issues:

```bash
composer lint:fix
```

## Performance Considerations

### Pattern Optimization

The library processes patterns sequentially, so pattern order can affect performance:

```php
// Good: More specific patterns first
$patterns = [
    '/\b\d{3}-\d{2}-\d{4}\b/' => '***SSN***',     // Specific format
    '/\b\d+\b/' => '***NUMBER***',                // Generic pattern last
];

// Avoid: Too many broad patterns
$patterns = [
    '/.*sensitive.*/' => '***MASKED***',          // Too broad, may be slow
];
```

### Large Dataset Handling

For applications processing large volumes of logs:

```php
// Consider pattern count vs. performance
$processor = new GdprProcessor(
    $patterns,        // Keep to essential patterns only
    $fieldPaths,      // More efficient than regex for known fields
    $callbacks        // Most efficient for complex logic
);
```

### Memory Usage

- **Regex Compilation**: Patterns are compiled on each use. Consider caching for high-volume applications.
- **Deep Nesting**: The `recursiveMask()` method processes nested arrays. Very deep structures may impact memory.
- **Audit Logging**: Be mindful of audit logger memory usage in high-volume scenarios.

### Benchmarking

Test performance with your actual data patterns:

```php
$start = microtime(true);
$processor = new GdprProcessor($patterns);
$result = $processor->regExpMessage($yourLogMessage);
$time = microtime(true) - $start;
echo "Processing time: " . ($time * 1000) . "ms\n";
```

## Troubleshooting

### Common Issues

#### Pattern Not Matching

**Problem**: Custom regex pattern isn't masking expected data.

**Solutions**:
```php
// 1. Test pattern in isolation
$testPattern = '/your-pattern/';
if (preg_match($testPattern, $testString)) {
    echo "Pattern matches!";
} else {
    echo "Pattern doesn't match.";
}

// 2. Validate pattern safety
try {
    GdprProcessor::validatePatterns([
        '/your-pattern/' => '***MASKED***'
    ]);
    echo "Pattern is valid and safe.";
} catch (InvalidArgumentException $e) {
    echo "Pattern error: " . $e->getMessage();
}

// 3. Enable audit logging to see what's happening
$auditLogger = function ($path, $original, $masked) {
    error_log("GDPR Debug: {$path} - Original type: " . gettype($original));
};
```

#### Performance Issues

**Problem**: Slow log processing with many patterns.

**Solutions**:
```php
// 1. Reduce pattern count
$essentialPatterns = [
    '/\b\d{3}-\d{2}-\d{4}\b/' => '***SSN***',
    '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/' => '***EMAIL***',
];

// 2. Use field-specific masking instead of global patterns
$fieldPaths = [
    'user.email' => GdprProcessor::maskWithRegex(), // Only for specific fields
    'user.ssn' => GdprProcessor::replaceWith('***SSN***'),
];

// 3. Profile pattern performance
$start = microtime(true);
// ... processing
$duration = microtime(true) - $start;
if ($duration > 0.1) { // 100ms threshold
    error_log("Slow GDPR processing: {$duration}s");
}
```

#### Audit Logging Issues

**Problem**: Audit logger not being called or logging sensitive data.

**Solutions**:
```php
// 1. Verify audit logger is callable
$auditLogger = function ($path, $original, $masked) {
    // SECURITY: Never log original sensitive data!
    $safeLog = [
        'path' => $path,
        'original_type' => gettype($original),
        'was_masked' => $original !== $masked,
        'timestamp' => date('c'),
    ];
    error_log('GDPR Audit: ' . json_encode($safeLog));
};

// 2. Test audit logger independently  
$processor = new GdprProcessor($patterns, [], [], $auditLogger);
$processor->regExpMessage('test@example.com'); // Should trigger audit log

// 3. Check if masking actually occurred
if ($original === $masked) {
    // No masking happened - check your patterns
}
```

#### Laravel Integration Issues

**Problem**: GDPR processor not working in Laravel.

**Solutions**:
```php
// 1. Verify processor is registered
Log::info('Test message with email@example.com');
// Check logs to see if masking occurred

// 2. Check logging channel configuration
// In config/logging.php, ensure tap is properly configured
'single' => [
    'driver' => 'single',
    'path' => storage_path('logs/laravel.log'),
    'level' => 'debug',
    'tap' => [App\Logging\GdprTap::class], // Ensure this line exists
],

// 3. Debug in service provider
class AppServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $logger = Log::getLogger();
        $processor = new GdprProcessor($patterns, $fieldPaths);
        $logger->pushProcessor($processor);
        
        // Test immediately
        Log::info('GDPR test: email@example.com should be masked');
    }
}
```

### Error Messages

#### "Invalid regex pattern"
- **Cause**: Pattern fails validation due to syntax error or security risk
- **Solution**: Check pattern syntax and avoid nested quantifiers

#### "Compilation failed"
- **Cause**: PHP regex compilation error
- **Solution**: Test pattern with `preg_match()` in isolation

#### "Unknown modifier"
- **Cause**: Invalid regex modifiers or malformed pattern
- **Solution**: Use standard modifiers like `/pattern/i` for case-insensitive

### Debugging Tips

1. **Enable Error Logging**:
   ```php
   error_reporting(E_ALL);
   ini_set('display_errors', 1);
   ```

2. **Test Patterns Separately**:
   ```php
   foreach ($patterns as $pattern => $replacement) {
       echo "Testing: {$pattern}\n";
       $result = preg_replace($pattern, $replacement, 'test string');
       if ($result === null) {
           echo "Error in pattern: {$pattern}\n";
       }
   }
   ```

3. **Monitor Performance**:
   ```php
   $processor = new GdprProcessor($patterns, $fieldPaths, [], function($path, $orig, $masked) {
       if (microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'] > 1.0) {
           error_log("Slow GDPR processing detected");
       }
   });
   ```

### Getting Help

- **Documentation**: Check [CONTRIBUTING.md](CONTRIBUTING.md) for development setup
- **Security Issues**: See [SECURITY.md](SECURITY.md) for responsible disclosure
- **Bug Reports**: Create an issue on GitHub with minimal reproduction example
- **Performance Issues**: Include profiling data and pattern counts

## Notable Implementation Details

- If a regex replacement in `regExpMessage` results in an empty string or the string "0", the original message is
  returned. This is covered by dedicated PHPUnit tests.
- If a regex pattern is invalid, the audit logger (if set) is called, and the original message is returned.
- All patterns are validated for security before use to prevent regex injection attacks.
- The library includes ReDoS (Regular Expression Denial of Service) protection.

## Directory Structure

- `src/` — Main library source code
- `tests/` — PHPUnit tests
- `coverage/` — Code coverage reports
- `vendor/` — Composer dependencies

## Legal Disclaimer

> **CAUTION**: This library helps mask/filter sensitive data for GDPR compliance, but it is your responsibility to
> ensure your application fully complies with all legal requirements. Review your logging and data handling policies
> regularly.

## Contributing

If you would like to contribute to this project, please fork the repository and submit a pull request.

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for more details.
