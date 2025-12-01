# PSR-3 Logger Decorator Guide

This guide explains how to wrap any PSR-3 compatible logger with GDPR masking capabilities.

## Overview

The PSR-3 decorator pattern allows you to add GDPR filtering to any logger that implements `Psr\Log\LoggerInterface`, making the library compatible with virtually any PHP logging framework.

## Basic Usage

### Creating a PSR-3 Wrapper

Here's a simple decorator that wraps any PSR-3 logger:

```php
<?php

namespace YourApp\Logging;

use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Monolog\Level;
use Monolog\LogRecord;
use DateTimeImmutable;
use Stringable;

class GdprLoggerDecorator implements LoggerInterface
{
    public function __construct(
        private readonly LoggerInterface $innerLogger,
        private readonly GdprProcessor $gdprProcessor
    ) {
    }

    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        // Convert PSR-3 level to Monolog level
        $monologLevel = $this->convertLevel($level);

        // Create a Monolog LogRecord for processing
        $record = new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'app',
            level: $monologLevel,
            message: (string) $message,
            context: $context
        );

        // Apply GDPR processing
        $processedRecord = ($this->gdprProcessor)($record);

        // Pass to inner logger
        $this->innerLogger->log($level, $processedRecord->message, $processedRecord->context);
    }

    private function convertLevel(mixed $level): Level
    {
        return match ($level) {
            LogLevel::EMERGENCY => Level::Emergency,
            LogLevel::ALERT => Level::Alert,
            LogLevel::CRITICAL => Level::Critical,
            LogLevel::ERROR => Level::Error,
            LogLevel::WARNING => Level::Warning,
            LogLevel::NOTICE => Level::Notice,
            LogLevel::INFO => Level::Info,
            LogLevel::DEBUG => Level::Debug,
            default => Level::Info,
        };
    }
}
```

## Usage Examples

### With Any PSR-3 Logger

```php
<?php

use YourApp\Logging\GdprLoggerDecorator;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Your existing PSR-3 logger (could be Monolog, any other, etc.)
$existingLogger = new Logger('app');
$existingLogger->pushHandler(new StreamHandler('php://stdout'));

// Create GDPR processor
$gdprProcessor = new GdprProcessor([
    '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/' => '[email]',
    '/\b\d{3}-\d{2}-\d{4}\b/' => '***-**-****',
]);

// Wrap with GDPR decorator
$logger = new GdprLoggerDecorator($existingLogger, $gdprProcessor);

// Use as normal
$logger->info('User john@example.com logged in with SSN 123-45-6789');
// Output: User [email] logged in with SSN ***-**-****
```

### With Dependency Injection

```php
<?php

use YourApp\Logging\GdprLoggerDecorator;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Psr\Log\LoggerInterface;

class UserService
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public function createUser(string $email, string $ssn): void
    {
        // Log will be automatically GDPR-filtered
        $this->logger->info("Creating user: {email}, SSN: {ssn}", [
            'email' => $email,
            'ssn' => $ssn,
        ]);
    }
}

// Container configuration (pseudo-code)
$container->register(GdprProcessor::class, function () {
    return new GdprProcessor([
        '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/' => '[email]',
    ]);
});

$container->register(LoggerInterface::class, function ($container) {
    return new GdprLoggerDecorator(
        $container->get('original_logger'),
        $container->get(GdprProcessor::class)
    );
});
```

## Enhanced Decorator with Channel Support

```php
<?php

namespace YourApp\Logging;

use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Monolog\Level;
use Monolog\LogRecord;
use DateTimeImmutable;
use Stringable;

class GdprLoggerDecorator implements LoggerInterface
{
    public function __construct(
        private readonly LoggerInterface $innerLogger,
        private readonly GdprProcessor $gdprProcessor,
        private readonly string $channel = 'app'
    ) {
    }

    /**
     * Create a new instance with a different channel.
     */
    public function withChannel(string $channel): self
    {
        return new self($this->innerLogger, $this->gdprProcessor, $channel);
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $record = new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: $this->channel,
            level: $this->convertLevel($level),
            message: (string) $message,
            context: $context
        );

        $processedRecord = ($this->gdprProcessor)($record);

        $this->innerLogger->log($level, $processedRecord->message, $processedRecord->context);
    }

    // ... other methods remain the same
}
```

## Using with Popular Frameworks

### Laravel

```php
<?php
// app/Providers/LoggingServiceProvider.php

namespace App\Providers;

use App\Logging\GdprLoggerDecorator;
use Illuminate\Support\ServiceProvider;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Psr\Log\LoggerInterface;

class LoggingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend(LoggerInterface::class, function ($logger) {
            $processor = new GdprProcessor(
                config('gdpr.patterns', [])
            );

            return new GdprLoggerDecorator($logger, $processor);
        });
    }
}
```

### Slim Framework

```php
<?php
// config/container.php

use DI\Container;
use YourApp\Logging\GdprLoggerDecorator;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Psr\Log\LoggerInterface;

return [
    LoggerInterface::class => function (Container $c) {
        $baseLogger = new Logger('app');
        $baseLogger->pushHandler(new StreamHandler('logs/app.log'));

        $processor = new GdprProcessor([
            '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/' => '[email]',
        ]);

        return new GdprLoggerDecorator($baseLogger, $processor);
    },
];
```

## Testing Your Decorator

```php
<?php

namespace Tests\Logging;

use PHPUnit\Framework\TestCase;
use YourApp\Logging\GdprLoggerDecorator;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class GdprLoggerDecoratorTest extends TestCase
{
    public function testEmailIsMasked(): void
    {
        $logs = [];
        $mockLogger = $this->createMock(LoggerInterface::class);
        $mockLogger->method('log')
            ->willReturnCallback(function ($level, $message, $context) use (&$logs) {
                $logs[] = ['level' => $level, 'message' => $message, 'context' => $context];
            });

        $processor = new GdprProcessor([
            '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/' => '[email]',
        ]);

        $decorator = new GdprLoggerDecorator($mockLogger, $processor);
        $decorator->info('Contact: john@example.com');

        $this->assertCount(1, $logs);
        $this->assertStringContainsString('[email]', $logs[0]['message']);
        $this->assertStringNotContainsString('john@example.com', $logs[0]['message']);
    }
}
```

## See Also

- [Symfony Integration](symfony-integration.md)
- [Framework Examples](framework-examples.md)
