# Monolog GDPR Filter

A PHP library providing a Monolog processor for GDPR compliance.
Mask, remove, or replace sensitive data in logs using regex patterns, field-level configuration,
custom callbacks, and advanced features like streaming, rate limiting, and k-anonymity.

## Features

### Core Masking

- **Regex-based masking** for patterns like SSNs, credit cards, emails, IPs, and more
- **Field-level masking** using dot-notation paths with flexible configuration
- **Custom callbacks** for advanced per-field masking logic
- **Data type masking** to mask values based on their PHP type
- **Serialized data support** for JSON, print_r, var_export, and serialize formats

### Enterprise Features

- **Fluent builder API** for readable processor configuration
- **Streaming processor** for memory-efficient large file processing
- **Rate-limited audit logging** to prevent log flooding
- **Plugin system** for extensible pre/post-processing hooks
- **K-anonymity support** for statistical privacy guarantees
- **Retry and recovery** with configurable failure modes
- **Conditional masking** based on log level, channel, or context

### Framework Integration

- **Monolog 3.x compatible** with ProcessorInterface implementation
- **Laravel integration** with service provider, middleware, and console commands
- **Audit logging** for compliance tracking and debugging

## Requirements

- PHP 8.2 or higher
- Monolog 3.x

## Installation

```bash
composer require ivuorinen/monolog-gdpr-filter
```

## Quick Start

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\FieldMaskConfig;

// Create processor with default GDPR patterns
$processor = new GdprProcessor(
    patterns: GdprProcessor::getDefaultPatterns(),
    fieldPaths: [
        'user.email' => FieldMaskConfig::remove(),
        'user.ssn' => FieldMaskConfig::replace('[REDACTED]'),
    ]
);

// Integrate with Monolog
$logger = new Logger('app');
$logger->pushHandler(new StreamHandler('app.log', Level::Warning));
$logger->pushProcessor($processor);

// Sensitive data is automatically masked
$logger->warning('User login', [
    'user' => [
        'email' => 'john@example.com',  // Will be removed
        'ssn' => '123-45-6789',         // Will be replaced with [REDACTED]
    ]
]);
```

## Core Concepts

### Regex Patterns

Define regex patterns to mask sensitive data in log messages and context values:

```php
$patterns = [
    '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '***EMAIL***',
    '/\b\d{3}-\d{2}-\d{4}\b/' => '***SSN***',
    '/\b\d{4}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}\b/' => '***CARD***',
];

$processor = new GdprProcessor(patterns: $patterns);
```

Use `GdprProcessor::getDefaultPatterns()` for a comprehensive set of pre-configured patterns
covering SSNs, credit cards, emails, phone numbers, IBANs, IP addresses, and more.

### Field Path Masking (FieldMaskConfig)

Configure masking for specific fields using dot-notation paths:

```php
use Ivuorinen\MonologGdprFilter\FieldMaskConfig;

$fieldPaths = [
    // Remove field entirely from logs
    'user.password' => FieldMaskConfig::remove(),

    // Replace with static value
    'payment.card_number' => FieldMaskConfig::replace('[CARD]'),

    // Apply processor's regex patterns to this field
    'user.bio' => FieldMaskConfig::useProcessorPatterns(),

    // Apply custom regex pattern
    'user.phone' => FieldMaskConfig::regexMask('/\d{3}-\d{4}/', '***-****'),
];
```

### Custom Callbacks

Provide custom masking functions for complex scenarios:

```php
$customCallbacks = [
    'user.name' => fn($value) => strtoupper(substr($value, 0, 1)) . '***',
    'user.id' => fn($value) => hash('sha256', (string) $value),
];

$processor = new GdprProcessor(
    patterns: [],
    fieldPaths: [],
    customCallbacks: $customCallbacks
);
```

## Basic Usage

### Direct GdprProcessor Usage

```php
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\FieldMaskConfig;

$processor = new GdprProcessor(
    patterns: GdprProcessor::getDefaultPatterns(),
    fieldPaths: [
        'user.ssn' => FieldMaskConfig::remove(),
        'payment.card' => FieldMaskConfig::replace('[REDACTED]'),
        'contact.email' => FieldMaskConfig::useProcessorPatterns(),
    ],
    customCallbacks: [
        'user.name' => fn($v) => strtoupper($v),
    ],
    auditLogger: function($path, $original, $masked) {
        // Log masking operations for compliance
        error_log("Masked: $path");
    },
    maxDepth: 100,
);
```

### Using GdprProcessorBuilder (Recommended)

The builder provides a fluent, readable API:

```php
use Ivuorinen\MonologGdprFilter\Builder\GdprProcessorBuilder;
use Ivuorinen\MonologGdprFilter\FieldMaskConfig;

$processor = GdprProcessorBuilder::create()
    ->withDefaultPatterns()
    ->addPattern('/custom-secret-\w+/', '[SECRET]')
    ->addFieldPath('user.email', FieldMaskConfig::remove())
    ->addFieldPath('user.ssn', FieldMaskConfig::replace('[SSN]'))
    ->addCallback('user.id', fn($v) => hash('sha256', (string) $v))
    ->withMaxDepth(50)
    ->withAuditLogger(function($path, $original, $masked) {
        // Audit logging
    })
    ->build();
```

## Advanced Features

### Conditional Masking

Apply masking only when specific conditions are met:

```php
use Ivuorinen\MonologGdprFilter\ConditionalRuleFactory;
use Monolog\Level;

$processor = new GdprProcessor(
    patterns: GdprProcessor::getDefaultPatterns(),
    conditionalRules: [
        // Only mask error-level logs
        'error_only' => ConditionalRuleFactory::createLevelBasedRule([Level::Error]),

        // Only mask specific channels
        'app_channel' => ConditionalRuleFactory::createChannelBasedRule(['app', 'security']),

        // Custom condition
        'has_user' => fn($record) => isset($record->context['user']),
    ]
);
```

### Data Type Masking

Mask values based on their PHP type:

```php
use Ivuorinen\MonologGdprFilter\MaskConstants;

$processor = new GdprProcessor(
    patterns: [],
    dataTypeMasks: [
        'integer' => MaskConstants::MASK_INT,
        'double' => MaskConstants::MASK_FLOAT,
        'boolean' => MaskConstants::MASK_BOOL,
    ]
);
```

### Rate-Limited Audit Logging

Prevent audit log flooding in high-volume applications:

```php
use Ivuorinen\MonologGdprFilter\RateLimitedAuditLogger;

$baseLogger = function($path, $original, $masked) {
    // Your audit logging logic
};

// Create rate-limited wrapper (100 logs per minute)
$rateLimitedLogger = new RateLimitedAuditLogger($baseLogger, 100, 60);

$processor = new GdprProcessor(
    patterns: GdprProcessor::getDefaultPatterns(),
    auditLogger: $rateLimitedLogger
);

// Available rate limit profiles via factory
$strictLogger = RateLimitedAuditLogger::create($baseLogger, 'strict');   // 50/min
$defaultLogger = RateLimitedAuditLogger::create($baseLogger, 'default'); // 100/min
$relaxedLogger = RateLimitedAuditLogger::create($baseLogger, 'relaxed'); // 200/min
```

### Streaming Large Files

Process large log files with memory-efficient streaming:

```php
use Ivuorinen\MonologGdprFilter\Streaming\StreamingProcessor;
use Ivuorinen\MonologGdprFilter\MaskingOrchestrator;

$orchestrator = new MaskingOrchestrator(GdprProcessor::getDefaultPatterns());
$streaming = new StreamingProcessor($orchestrator, chunkSize: 1000);

// Process file line by line
$lineParser = fn(string $line) => ['message' => $line, 'context' => []];

foreach ($streaming->processFile('large-app.log', $lineParser) as $maskedRecord) {
    // Write to output file or process further
    fwrite($output, $maskedRecord['message'] . "\n");
}

// Or process to file directly
$formatter = fn(array $record) => json_encode($record);
$count = $streaming->processToFile($records, 'masked-output.log', $formatter);
```

## Laravel Integration

### Service Provider

```php
// app/Providers/AppServiceProvider.php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\FieldMaskConfig;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $processor = new GdprProcessor(
            patterns: GdprProcessor::getDefaultPatterns(),
            fieldPaths: [
                'user.email' => FieldMaskConfig::remove(),
                'user.password' => FieldMaskConfig::remove(),
            ]
        );

        $this->app['log']->getLogger()->pushProcessor($processor);
    }
}
```

### Tap Class

```php
// app/Logging/GdprTap.php
namespace App\Logging;

use Monolog\Logger;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\FieldMaskConfig;

class GdprTap
{
    public function __invoke(Logger $logger): void
    {
        $processor = new GdprProcessor(
            patterns: GdprProcessor::getDefaultPatterns(),
            fieldPaths: [
                'user.email' => FieldMaskConfig::remove(),
                'payment.card' => FieldMaskConfig::replace('[CARD]'),
            ]
        );

        $logger->pushProcessor($processor);
    }
}
```

Reference in `config/logging.php`:

```php
'channels' => [
    'single' => [
        'driver' => 'single',
        'path' => storage_path('logs/laravel.log'),
        'level' => 'debug',
        'tap' => [App\Logging\GdprTap::class],
    ],
],
```

### Console Commands

The library provides Artisan commands for testing and debugging:

```bash
# Test a pattern against sample data
php artisan gdpr:test-pattern '/\b\d{3}-\d{2}-\d{4}\b/' 'SSN: 123-45-6789'

# Debug current GDPR configuration
php artisan gdpr:debug
```

## Plugin System

Extend the processor with custom pre/post-processing hooks:

```php
use Ivuorinen\MonologGdprFilter\Contracts\MaskingPluginInterface;
use Ivuorinen\MonologGdprFilter\Builder\GdprProcessorBuilder;

class CustomPlugin implements MaskingPluginInterface
{
    public function getName(): string
    {
        return 'custom-plugin';
    }

    public function getPriority(): int
    {
        return 10; // Lower = earlier execution
    }

    public function preProcessMessage(string $message): string
    {
        // Modify message before masking
        return $message;
    }

    public function postProcessMessage(string $message): string
    {
        // Modify message after masking
        return $message;
    }

    public function preProcessContext(array $context): array
    {
        return $context;
    }

    public function postProcessContext(array $context): array
    {
        return $context;
    }
}

$processor = GdprProcessorBuilder::create()
    ->withDefaultPatterns()
    ->addPlugin(new CustomPlugin())
    ->buildWithPlugins();
```

## Default Patterns Reference

`GdprProcessor::getDefaultPatterns()` includes patterns for:

| Category | Data Types |
|----------|------------|
| Personal IDs | Finnish SSN (HETU), US SSN, Passport numbers, National IDs |
| Financial | Credit cards, IBAN, Bank account numbers |
| Contact | Email addresses, Phone numbers (E.164) |
| Technical | IPv4/IPv6 addresses, MAC addresses, API keys, Bearer tokens |
| Health | Medicare numbers, European Health Insurance Card (EHIC) |
| Dates | Birth dates in multiple formats |

## Performance Considerations

### Pattern Optimization

Order patterns from most specific to most general:

```php
// Recommended: specific patterns first
$patterns = [
    '/\b\d{3}-\d{2}-\d{4}\b/' => '***SSN***',     // Specific format
    '/\b\d+\b/' => '***NUMBER***',                // Generic fallback
];
```

### Memory-Efficient Processing

For large datasets:

- Use `StreamingProcessor` for file-based processing
- Configure appropriate `maxDepth` to limit recursion
- Use rate-limited audit logging to prevent memory growth

### Pattern Caching

Patterns are validated and cached internally.
For high-throughput applications, the library automatically caches compiled patterns.

## Troubleshooting

### Pattern Not Matching

```php
// Test pattern in isolation
$pattern = '/your-pattern/';
if (preg_match($pattern, $testString)) {
    echo 'Pattern matches';
}

// Validate pattern safety
try {
    GdprProcessor::validatePatternsArray([
        '/your-pattern/' => '***MASKED***'
    ]);
} catch (PatternValidationException $e) {
    echo 'Invalid pattern: ' . $e->getMessage();
}
```

### Performance Issues

- Reduce pattern count to essential patterns only
- Use field-specific masking instead of broad regex patterns
- Profile with audit logging to identify slow operations

### Audit Logger Issues

```php
// Safe audit logging (never log original sensitive data)
$auditLogger = function($path, $original, $masked) {
    error_log(sprintf(
        'GDPR Audit: %s - type=%s, masked=%s',
        $path,
        gettype($original),
        $original !== $masked ? 'yes' : 'no'
    ));
};
```

## Testing and Quality

```bash
# Run tests
composer test

# Run tests with coverage report
composer test:coverage

# Run all linters
composer lint

# Auto-fix code style issues
composer lint:fix
```

## Security

- All patterns are validated for safety before use to prevent regex injection attacks
- The library includes ReDoS (Regular Expression Denial of Service) protection
- Dangerous patterns with recursive structures or excessive backtracking are rejected

For security vulnerabilities, please see [SECURITY.md](SECURITY.md) for responsible disclosure guidelines.

## Legal Disclaimer

This library helps mask and filter sensitive data for GDPR compliance, but it is your responsibility
to ensure your application fully complies with all applicable legal requirements.
This tool is provided as-is without warranty.
Review your logging and data handling policies regularly with legal counsel.

## Contributing

Contributions are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) for development setup and guidelines.

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.
