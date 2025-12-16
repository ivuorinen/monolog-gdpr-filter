# Logging Platform Integrations

This guide covers integrating the Monolog GDPR Filter with popular logging platforms and services.

## Table of Contents

- [ELK Stack (Elasticsearch, Logstash, Kibana)](#elk-stack)
- [Graylog](#graylog)
- [Datadog](#datadog)
- [New Relic](#new-relic)
- [Sentry](#sentry)
- [Papertrail](#papertrail)
- [Loggly](#loggly)
- [AWS CloudWatch](#aws-cloudwatch)
- [Google Cloud Logging](#google-cloud-logging)
- [Fluentd/Fluent Bit](#fluentdfluent-bit)

## ELK Stack

### Elasticsearch with Monolog

```php
<?php

use Monolog\Logger;
use Monolog\Handler\ElasticsearchHandler;
use Monolog\Formatter\ElasticsearchFormatter;
use Elastic\Elasticsearch\ClientBuilder;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\DefaultPatterns;

// Create Elasticsearch client
$client = ClientBuilder::create()
    ->setHosts(['localhost:9200'])
    ->build();

// Create handler
$handler = new ElasticsearchHandler($client, [
    'index' => 'app-logs',
    'type' => '_doc',
]);
$handler->setFormatter(new ElasticsearchFormatter('app-logs', '_doc'));

// Create logger with GDPR processor
$logger = new Logger('app');
$logger->pushHandler($handler);
$logger->pushProcessor(new GdprProcessor(DefaultPatterns::all()));

// Logs are now GDPR-compliant before reaching Elasticsearch
$logger->info('User login', ['email' => 'user@example.com', 'ip' => '192.168.1.1']);
```

### Logstash Integration

For Logstash, use the Gelf handler or send JSON to a TCP/UDP input:

```php
<?php

use Monolog\Logger;
use Monolog\Handler\SocketHandler;
use Monolog\Formatter\JsonFormatter;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\DefaultPatterns;

$handler = new SocketHandler('tcp://logstash.example.com:5000');
$handler->setFormatter(new JsonFormatter());

$logger = new Logger('app');
$logger->pushHandler($handler);
$logger->pushProcessor(new GdprProcessor(DefaultPatterns::all()));
```

Logstash configuration:
```ruby
input {
  tcp {
    port => 5000
    codec => json
  }
}

output {
  elasticsearch {
    hosts => ["elasticsearch:9200"]
    index => "app-logs-%{+YYYY.MM.dd}"
  }
}
```

## Graylog

### GELF Handler Integration

```php
<?php

use Monolog\Logger;
use Monolog\Handler\GelfHandler;
use Gelf\Publisher;
use Gelf\Transport\UdpTransport;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\DefaultPatterns;

// Create GELF transport
$transport = new UdpTransport('graylog.example.com', 12201);
$publisher = new Publisher($transport);

// Create handler
$handler = new GelfHandler($publisher);

// Create logger with GDPR processor
$logger = new Logger('app');
$logger->pushHandler($handler);
$logger->pushProcessor(new GdprProcessor(DefaultPatterns::all()));

$logger->info('Payment processed', [
    'user_email' => 'customer@example.com',
    'card_last_four' => '4242',
]);
```

### Graylog Stream Configuration

Create a stream to filter GDPR-sensitive logs:

1. Create an extractor to identify masked fields
2. Set up alerts for potential data leaks (unmasked patterns)

```php
<?php

// Add metadata to help Graylog categorize
$logger->pushProcessor(function ($record) {
    $record['extra']['gdpr_processed'] = true;
    $record['extra']['app_version'] = '1.0.0';
    return $record;
});
```

## Datadog

### Datadog Handler Integration

```php
<?php

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\JsonFormatter;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\DefaultPatterns;

// Datadog agent reads from file or stdout
$handler = new StreamHandler('php://stdout');
$handler->setFormatter(new JsonFormatter());

$logger = new Logger('app');
$logger->pushHandler($handler);
$logger->pushProcessor(new GdprProcessor(DefaultPatterns::all()));

// Add Datadog-specific context
$logger->pushProcessor(function ($record) {
    $record['extra']['dd'] = [
        'service' => 'my-php-app',
        'env' => getenv('DD_ENV') ?: 'production',
        'version' => '1.0.0',
    ];
    return $record;
});

$logger->info('User action', ['user_id' => 123, 'email' => 'user@example.com']);
```

### Datadog APM Integration

```php
<?php

use DDTrace\GlobalTracer;
use Ivuorinen\MonologGdprFilter\GdprProcessor;

// Add trace context to logs
$logger->pushProcessor(function ($record) {
    $tracer = GlobalTracer::get();
    $span = $tracer->getActiveSpan();

    if ($span) {
        $record['extra']['dd.trace_id'] = $span->getTraceId();
        $record['extra']['dd.span_id'] = $span->getSpanId();
    }

    return $record;
});
```

## New Relic

### New Relic Handler Integration

```php
<?php

use Monolog\Logger;
use Monolog\Handler\NewRelicHandler;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\DefaultPatterns;

$handler = new NewRelicHandler(
    level: Logger::ERROR,
    appName: 'My PHP App'
);

$logger = new Logger('app');
$logger->pushHandler($handler);
$logger->pushProcessor(new GdprProcessor(DefaultPatterns::all()));

// Errors are sent to New Relic with masked PII
$logger->error('Authentication failed', [
    'email' => 'user@example.com',
    'ip' => '192.168.1.1',
]);
```

### Custom Attributes

```php
<?php

// Add New Relic custom attributes
$logger->pushProcessor(function ($record) {
    if (function_exists('newrelic_add_custom_parameter')) {
        newrelic_add_custom_parameter('log_level', $record['level_name']);
        newrelic_add_custom_parameter('channel', $record['channel']);
    }
    return $record;
});
```

## Sentry

### Sentry Handler Integration

```php
<?php

use Monolog\Logger;
use Sentry\Monolog\Handler;
use Sentry\State\Hub;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\DefaultPatterns;

\Sentry\init(['dsn' => 'https://key@sentry.io/project']);

$handler = new Handler(Hub::getCurrent());

$logger = new Logger('app');
$logger->pushHandler($handler);

// IMPORTANT: Add GDPR processor BEFORE Sentry handler processes
$logger->pushProcessor(new GdprProcessor(DefaultPatterns::all()));

$logger->error('Payment failed', [
    'user_email' => 'customer@example.com',
    'card_number' => '4111111111111111',
]);
```

### Sentry Breadcrumbs

```php
<?php

use Sentry\Breadcrumb;

// Add breadcrumb processor that respects GDPR
$logger->pushProcessor(function ($record) {
    \Sentry\addBreadcrumb(new Breadcrumb(
        Breadcrumb::LEVEL_INFO,
        Breadcrumb::TYPE_DEFAULT,
        $record['channel'],
        $record['message'],  // Already masked by GDPR processor
        $record['context']   // Already masked
    ));
    return $record;
});
```

## Papertrail

### Papertrail Handler Integration

```php
<?php

use Monolog\Logger;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Formatter\LineFormatter;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\DefaultPatterns;

$handler = new SyslogUdpHandler(
    'logs.papertrailapp.com',
    12345  // Your Papertrail port
);

$formatter = new LineFormatter(
    "%channel%.%level_name%: %message% %context% %extra%\n",
    null,
    true,
    true
);
$handler->setFormatter($formatter);

$logger = new Logger('my-app');
$logger->pushHandler($handler);
$logger->pushProcessor(new GdprProcessor(DefaultPatterns::all()));
```

## Loggly

### Loggly Handler Integration

```php
<?php

use Monolog\Logger;
use Monolog\Handler\LogglyHandler;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\DefaultPatterns;

$handler = new LogglyHandler('your-loggly-token/tag/monolog');

$logger = new Logger('app');
$logger->pushHandler($handler);
$logger->pushProcessor(new GdprProcessor(DefaultPatterns::all()));

$logger->info('User registered', [
    'email' => 'newuser@example.com',
    'phone' => '+1-555-123-4567',
]);
```

## AWS CloudWatch

### CloudWatch Handler Integration

```php
<?php

use Monolog\Logger;
use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Maxbanton\Cwh\Handler\CloudWatch;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\DefaultPatterns;

$client = new CloudWatchLogsClient([
    'region' => 'us-east-1',
    'version' => 'latest',
]);

$handler = new CloudWatch(
    $client,
    'app-log-group',
    'app-log-stream',
    retentionDays: 14
);

$logger = new Logger('app');
$logger->pushHandler($handler);
$logger->pushProcessor(new GdprProcessor(DefaultPatterns::all()));

$logger->info('API request', [
    'user_email' => 'api-user@example.com',
    'endpoint' => '/api/v1/users',
]);
```

### CloudWatch with Laravel

```php
<?php

// config/logging.php
return [
    'channels' => [
        'cloudwatch' => [
            'driver' => 'custom',
            'via' => App\Logging\CloudWatchLoggerFactory::class,
            'retention' => 14,
            'group' => env('CLOUDWATCH_LOG_GROUP', 'laravel'),
            'stream' => env('CLOUDWATCH_LOG_STREAM', 'app'),
        ],
    ],
];
```

```php
<?php

// app/Logging/CloudWatchLoggerFactory.php
namespace App\Logging;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Maxbanton\Cwh\Handler\CloudWatch;
use Monolog\Logger;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\DefaultPatterns;

class CloudWatchLoggerFactory
{
    public function __invoke(array $config): Logger
    {
        $client = new CloudWatchLogsClient([
            'region' => config('services.aws.region'),
            'version' => 'latest',
        ]);

        $handler = new CloudWatch(
            $client,
            $config['group'],
            $config['stream'],
            $config['retention']
        );

        $logger = new Logger('cloudwatch');
        $logger->pushHandler($handler);
        $logger->pushProcessor(new GdprProcessor(DefaultPatterns::all()));

        return $logger;
    }
}
```

## Google Cloud Logging

### Google Cloud Handler Integration

```php
<?php

use Monolog\Logger;
use Google\Cloud\Logging\LoggingClient;
use Google\Cloud\Logging\PsrLogger;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\DefaultPatterns;

$logging = new LoggingClient([
    'projectId' => 'your-project-id',
]);

$psrLogger = $logging->psrLogger('app-logs');

// Wrap in Monolog for processor support
$monologLogger = new Logger('app');
$monologLogger->pushHandler(new \Monolog\Handler\PsrHandler($psrLogger));
$monologLogger->pushProcessor(new GdprProcessor(DefaultPatterns::all()));

$monologLogger->info('User action', [
    'email' => 'user@example.com',
    'action' => 'login',
]);
```

## Fluentd/Fluent Bit

### Fluentd Integration

```php
<?php

use Monolog\Logger;
use Monolog\Handler\SocketHandler;
use Monolog\Formatter\JsonFormatter;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\DefaultPatterns;

// Send to Fluentd forward input
$handler = new SocketHandler('tcp://fluentd:24224');
$handler->setFormatter(new JsonFormatter());

$logger = new Logger('app');
$logger->pushHandler($handler);
$logger->pushProcessor(new GdprProcessor(DefaultPatterns::all()));

// Add Fluentd tag
$logger->pushProcessor(function ($record) {
    $record['extra']['fluent_tag'] = 'app.logs';
    return $record;
});
```

Fluentd configuration:
```ruby
<source>
  @type forward
  port 24224
</source>

<match app.**>
  @type elasticsearch
  host elasticsearch
  port 9200
  index_name app-logs
</match>
```

### Fluent Bit with File Tail

```php
<?php

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\JsonFormatter;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\DefaultPatterns;

// Write JSON logs to file for Fluent Bit to tail
$handler = new StreamHandler('/var/log/app/app.json.log');
$handler->setFormatter(new JsonFormatter());

$logger = new Logger('app');
$logger->pushHandler($handler);
$logger->pushProcessor(new GdprProcessor(DefaultPatterns::all()));
```

Fluent Bit configuration:
```ini
[INPUT]
    Name tail
    Path /var/log/app/*.json.log
    Parser json

[OUTPUT]
    Name es
    Host elasticsearch
    Port 9200
    Index app-logs
```

## Best Practices

### 1. Always Process Before Sending

Ensure the GDPR processor runs before logs leave your application:

```php
<?php

// Correct order: GDPR processor added AFTER handlers
$logger = new Logger('app');
$logger->pushHandler($externalHandler);
$logger->pushProcessor(new GdprProcessor($patterns));  // Runs before handlers
```

### 2. Add Compliance Metadata

```php
<?php

$logger->pushProcessor(function ($record) {
    $record['extra']['gdpr'] = [
        'processed' => true,
        'processor_version' => '3.0.0',
        'timestamp' => date('c'),
    ];
    return $record;
});
```

### 3. Monitor for Leaks

Set up alerts in your logging platform for unmasked PII patterns:

```json
{
  "query": {
    "regexp": {
      "message": "[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}"
    }
  }
}
```

### 4. Retention Policies

Configure retention aligned with GDPR requirements:

- Most platforms support automatic log deletion
- Set retention to 30 days for most operational logs
- Archive critical audit logs separately with longer retention
