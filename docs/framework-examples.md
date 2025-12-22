# Framework Integration Examples

This guide provides integration examples for various PHP frameworks.

## CakePHP

### Installation

```bash
composer require ivuorinen/monolog-gdpr-filter
```

### Configuration

Create a custom log engine in `src/Log/Engine/GdprFileLog.php`:

```php
<?php
declare(strict_types=1);

namespace App\Log\Engine;

use Cake\Log\Engine\FileLog;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use DateTimeImmutable;

class GdprFileLog extends FileLog
{
    protected GdprProcessor $gdprProcessor;

    public function __construct(array $config = [])
    {
        parent::__construct($config);

        $patterns = $config['gdpr_patterns'] ?? [
            '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/' => '[email]',
            '/\b\d{3}-\d{2}-\d{4}\b/' => '***-**-****',
        ];

        $this->gdprProcessor = new GdprProcessor($patterns);
    }

    public function log($level, string $message, array $context = []): void
    {
        $record = new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'app',
            level: $this->convertLevel($level),
            message: $message,
            context: $context
        );

        $processed = ($this->gdprProcessor)($record);

        parent::log($level, $processed->message, $processed->context);
    }

    private function convertLevel(mixed $level): Level
    {
        return match ($level) {
            'emergency' => Level::Emergency,
            'alert' => Level::Alert,
            'critical' => Level::Critical,
            'error' => Level::Error,
            'warning' => Level::Warning,
            'notice' => Level::Notice,
            'info' => Level::Info,
            'debug' => Level::Debug,
            default => Level::Info,
        };
    }
}
```

Configure in `config/app.php`:

```php
'Log' => [
    'default' => [
        'className' => \App\Log\Engine\GdprFileLog::class,
        'path' => LOGS,
        'file' => 'debug',
        'levels' => ['notice', 'info', 'debug'],
        'gdpr_patterns' => [
            '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/' => '[email]',
        ],
    ],
],
```

## CodeIgniter 4

### Configuration

Create a custom logger in `app/Libraries/GdprLogger.php`:

```php
<?php

namespace App\Libraries;

use CodeIgniter\Log\Logger;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use DateTimeImmutable;

class GdprLogger extends Logger
{
    protected GdprProcessor $gdprProcessor;

    public function __construct($config, bool $introspect = true)
    {
        parent::__construct($config, $introspect);

        $patterns = $config->gdprPatterns ?? [
            '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/' => '[email]',
        ];

        $this->gdprProcessor = new GdprProcessor($patterns);
    }

    public function log($level, $message, array $context = []): bool
    {
        $record = new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'ci4',
            level: $this->mapLevel($level),
            message: (string) $message,
            context: $context
        );

        $processed = ($this->gdprProcessor)($record);

        return parent::log($level, $processed->message, $processed->context);
    }

    private function mapLevel(mixed $level): Level
    {
        return match (strtolower((string) $level)) {
            'emergency' => Level::Emergency,
            'alert' => Level::Alert,
            'critical' => Level::Critical,
            'error' => Level::Error,
            'warning' => Level::Warning,
            'notice' => Level::Notice,
            'info' => Level::Info,
            'debug' => Level::Debug,
            default => Level::Info,
        };
    }
}
```

Register in `app/Config/Services.php`:

```php
public static function logger(bool $getShared = true): \App\Libraries\GdprLogger
{
    if ($getShared) {
        return static::getSharedInstance('logger');
    }

    return new \App\Libraries\GdprLogger(new \Config\Logger());
}
```

## Laminas (formerly Zend Framework)

### Service Configuration

```php
<?php
// config/autoload/logging.global.php

use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Laminas\Log\Logger;
use Laminas\Log\Writer\Stream;
use Laminas\Log\Processor\ProcessorInterface;
use Psr\Container\ContainerInterface;

return [
    'service_manager' => [
        'factories' => [
            GdprProcessor::class => function (ContainerInterface $container) {
                $config = $container->get('config')['gdpr'] ?? [];
                return new GdprProcessor(
                    $config['patterns'] ?? [],
                    $config['field_paths'] ?? []
                );
            },

            'GdprLogProcessor' => function (ContainerInterface $container) {
                $gdprProcessor = $container->get(GdprProcessor::class);

                return new class($gdprProcessor) implements ProcessorInterface {
                    public function __construct(
                        private readonly GdprProcessor $gdprProcessor
                    ) {}

                    public function process(array $event): array
                    {
                        // Convert to LogRecord, process, convert back
                        $record = new \Monolog\LogRecord(
                            datetime: new \DateTimeImmutable(),
                            channel: 'laminas',
                            level: \Monolog\Level::Info,
                            message: $event['message'] ?? '',
                            context: $event['extra'] ?? []
                        );

                        $processed = ($this->gdprProcessor)($record);

                        $event['message'] = $processed->message;
                        $event['extra'] = $processed->context;

                        return $event;
                    }
                };
            },

            Logger::class => function (ContainerInterface $container) {
                $logger = new Logger();
                $logger->addWriter(new Stream('data/logs/app.log'));
                $logger->addProcessor($container->get('GdprLogProcessor'));
                return $logger;
            },
        ],
    ],

    'gdpr' => [
        'patterns' => [
            '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/' => '[email]',
            '/\b\d{3}-\d{2}-\d{4}\b/' => '***-**-****',
        ],
        'field_paths' => [
            'user.password' => '***REMOVED***',
        ],
    ],
];
```

## Yii2

### Component Configuration

```php
<?php
// config/web.php or config/console.php

return [
    'components' => [
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets' => [
                [
                    'class' => 'app\components\GdprFileTarget',
                    'levels' => ['error', 'warning', 'info'],
                    'gdprPatterns' => [
                        '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/' => '[email]',
                    ],
                ],
            ],
        ],
    ],
];
```

Create `components/GdprFileTarget.php`:

```php
<?php

namespace app\components;

use yii\log\FileTarget;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use DateTimeImmutable;

class GdprFileTarget extends FileTarget
{
    public array $gdprPatterns = [];

    private ?GdprProcessor $processor = null;

    public function init(): void
    {
        parent::init();

        if (!empty($this->gdprPatterns)) {
            $this->processor = new GdprProcessor($this->gdprPatterns);
        }
    }

    public function formatMessage($message): string
    {
        if ($this->processor !== null) {
            [$text, $level, $category, $timestamp] = $message;

            $record = new LogRecord(
                datetime: new DateTimeImmutable('@' . $timestamp),
                channel: $category,
                level: Level::Info,
                message: is_string($text) ? $text : json_encode($text) ?: '',
                context: []
            );

            $processed = ($this->processor)($record);
            $message[0] = $processed->message;
        }

        return parent::formatMessage($message);
    }
}
```

## Generic PSR-15 Middleware

For any framework supporting PSR-15 middleware:

```php
<?php

namespace YourApp\Middleware;

use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class GdprLoggingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly GdprProcessor $gdprProcessor
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        // Log request (with GDPR filtering applied via decorator)
        $this->logger->info('Request received', [
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri(),
            'body' => $request->getParsedBody(),
        ]);

        $response = $handler->handle($request);

        // Log response
        $this->logger->info('Response sent', [
            'status' => $response->getStatusCode(),
        ]);

        return $response;
    }
}
```

## See Also

- [Symfony Integration](symfony-integration.md)
- [PSR-3 Decorator](psr3-decorator.md)
- [Docker Development](docker-development.md)
