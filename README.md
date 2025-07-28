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

## Notable Implementation Details

- If a regex replacement in `regExpMessage` results in an empty string or the string "0", the original message is
  returned. This is covered by dedicated PHPUnit tests.
- If a regex pattern is invalid, the audit logger (if set) is called, and the original message is returned.

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
