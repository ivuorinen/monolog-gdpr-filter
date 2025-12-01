# Symfony Integration Guide

This guide explains how to integrate the Monolog GDPR Filter with Symfony applications.

## Installation

```bash
composer require ivuorinen/monolog-gdpr-filter
```

## Basic Service Configuration

Add the GDPR processor as a service in `config/services.yaml`:

```yaml
services:
    App\Logging\GdprProcessor:
        class: Ivuorinen\MonologGdprFilter\GdprProcessor
        arguments:
            $patterns: '%gdpr.patterns%'
            $fieldPaths: '%gdpr.field_paths%'
            $customCallbacks: []
            $auditLogger: null
            $maxDepth: 100
            $dataTypeMasks: []
            $conditionalRules: []
```

## Parameters Configuration

Define GDPR patterns in `config/services.yaml` or a dedicated parameters file:

```yaml
parameters:
    gdpr.patterns:
        '/\b\d{3}-\d{2}-\d{4}\b/': '***-**-****'        # US SSN
        '/\b[A-Z]{2}\d{2}[A-Z0-9]{4}\d{7}([A-Z0-9]?){0,16}\b/': '****'  # IBAN
        '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/': '[email]'   # Email
        '/\b\d{4}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}\b/': '****-****-****-****'  # Credit Card

    gdpr.field_paths:
        'user.password': '***REMOVED***'
        'user.ssn': '***-**-****'
        'payment.card_number': '****-****-****-****'
```

## Monolog Handler Configuration

Configure Monolog to use the GDPR processor in `config/packages/monolog.yaml`:

```yaml
monolog:
    handlers:
        main:
            type: stream
            path: "%kernel.logs_dir%/%kernel.environment%.log"
            level: debug
            channels: ["!event"]
            formatter: monolog.formatter.json
            processor: ['@App\Logging\GdprProcessor']

        # For production with file rotation
        production:
            type: rotating_file
            path: "%kernel.logs_dir%/%kernel.environment%.log"
            level: info
            max_files: 14
            processor: ['@App\Logging\GdprProcessor']
```

## Environment-Specific Configuration

Create environment-specific configurations:

### config/packages/dev/monolog.yaml
```yaml
monolog:
    handlers:
        main:
            type: stream
            path: "%kernel.logs_dir%/%kernel.environment%.log"
            level: debug
            # In dev, you might want less aggressive masking
```

### config/packages/prod/monolog.yaml
```yaml
monolog:
    handlers:
        main:
            type: fingers_crossed
            action_level: error
            handler: nested
            excluded_http_codes: [404, 405]
            buffer_size: 50

        nested:
            type: rotating_file
            path: "%kernel.logs_dir%/%kernel.environment%.log"
            level: info
            max_files: 14
            processor: ['@App\Logging\GdprProcessor']
```

## Advanced Configuration with Audit Logging

Enable audit logging for compliance tracking:

```yaml
services:
    App\Logging\AuditLogger:
        class: Ivuorinen\MonologGdprFilter\RateLimitedAuditLogger
        arguments:
            $auditLogger: '@App\Logging\AuditCallback'
            $maxRequestsPerMinute: 100
            $windowSeconds: 60

    App\Logging\AuditCallback:
        class: Closure
        factory: ['App\Logging\AuditCallbackFactory', 'create']
        arguments:
            $logger: '@monolog.logger.audit'

    App\Logging\GdprProcessor:
        class: Ivuorinen\MonologGdprFilter\GdprProcessor
        arguments:
            $patterns: '%gdpr.patterns%'
            $fieldPaths: '%gdpr.field_paths%'
            $auditLogger: '@App\Logging\AuditLogger'
```

Create the factory class:

```php
<?php
// src/Logging/AuditCallbackFactory.php

namespace App\Logging;

use Psr\Log\LoggerInterface;

class AuditCallbackFactory
{
    public static function create(LoggerInterface $logger): callable
    {
        return function (string $path, mixed $original, mixed $masked) use ($logger): void {
            $logger->info('GDPR masking applied', [
                'path' => $path,
                'original_type' => gettype($original),
                'masked_preview' => substr((string) $masked, 0, 20) . '...',
            ]);
        };
    }
}
```

## Conditional Masking by Environment

Apply different masking rules based on log level or channel:

```yaml
services:
    App\Logging\ConditionalRuleFactory:
        class: App\Logging\ConditionalRuleFactory

    App\Logging\GdprProcessor:
        class: Ivuorinen\MonologGdprFilter\GdprProcessor
        arguments:
            $conditionalRules:
                error_only: '@=service("App\\Logging\\ConditionalRuleFactory").createErrorOnlyRule()'
```

```php
<?php
// src/Logging/ConditionalRuleFactory.php

namespace App\Logging;

use Monolog\Level;
use Monolog\LogRecord;

class ConditionalRuleFactory
{
    public function createErrorOnlyRule(): callable
    {
        return fn(LogRecord $record): bool =>
            $record->level->value >= Level::Error->value;
    }

    public function createChannelRule(array $channels): callable
    {
        return fn(LogRecord $record): bool =>
            in_array($record->channel, $channels, true);
    }
}
```

## Testing in Symfony

Create a test to verify GDPR filtering works:

```php
<?php
// tests/Logging/GdprProcessorTest.php

namespace App\Tests\Logging;

use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use DateTimeImmutable;

class GdprProcessorTest extends TestCase
{
    public function testEmailMasking(): void
    {
        $processor = new GdprProcessor([
            '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/' => '[email]',
        ]);

        $record = new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'User logged in: user@example.com',
            context: []
        );

        $result = $processor($record);

        $this->assertStringContainsString('[email]', $result->message);
        $this->assertStringNotContainsString('user@example.com', $result->message);
    }
}
```

## Troubleshooting

### Patterns Not Matching

1. Verify regex patterns are valid: `preg_match('/your-pattern/', 'test-string')`
2. Check pattern escaping in YAML (may need quotes)
3. Enable debug mode to see which patterns are applied

### Performance Issues

1. Use the rate-limited audit logger
2. Consider caching pattern validation results
3. Profile with Symfony profiler

### Memory Issues

1. Set appropriate `maxDepth` to prevent deep recursion
2. Monitor rate limiter statistics
3. Use cleanup intervals for long-running processes

## See Also

- [PSR-3 Decorator Guide](psr3-decorator.md)
- [Framework Examples](framework-examples.md)
- [Docker Development](docker-development.md)
