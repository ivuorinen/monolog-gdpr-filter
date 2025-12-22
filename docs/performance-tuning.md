# Performance Tuning Guide

This guide covers optimization strategies for the Monolog GDPR Filter library in high-throughput environments.

## Table of Contents

- [Benchmarking Your Setup](#benchmarking-your-setup)
- [Pattern Optimization](#pattern-optimization)
- [Memory Management](#memory-management)
- [Caching Strategies](#caching-strategies)
- [Rate Limiting](#rate-limiting)
- [Streaming Large Logs](#streaming-large-logs)
- [Production Configuration](#production-configuration)

## Benchmarking Your Setup

Before optimizing, establish baseline metrics:

```php
<?php

use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\DefaultPatterns;

$processor = new GdprProcessor(DefaultPatterns::all());

$record = [
    'message' => 'User john@example.com logged in from 192.168.1.100',
    'context' => [
        'user' => ['email' => 'john@example.com', 'ssn' => '123-45-6789'],
        'ip' => '192.168.1.100',
    ],
    'level' => 200,
    'level_name' => 'INFO',
    'channel' => 'app',
    'datetime' => new DateTimeImmutable(),
    'extra' => [],
];

// Benchmark
$iterations = 10000;
$start = microtime(true);

for ($i = 0; $i < $iterations; $i++) {
    $processor($record);
}

$elapsed = microtime(true) - $start;
$perSecond = $iterations / $elapsed;

echo "Processed {$iterations} records in {$elapsed:.4f} seconds\n";
echo "Throughput: {$perSecond:.0f} records/second\n";
```

**Target benchmarks:**

- Simple patterns: 50,000+ records/second
- Complex patterns with nested context: 10,000+ records/second
- With audit logging: 5,000+ records/second

## Pattern Optimization

### 1. Order Patterns by Frequency

Place most frequently matched patterns first:

```php
<?php

use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\MaskConstants;

// Good: Email (common) before SSN (rare)
$patterns = [
    '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/' => MaskConstants::MASK_EMAIL,
    '/\b\d{3}-\d{2}-\d{4}\b/' => MaskConstants::MASK_SSN,
];

$processor = new GdprProcessor($patterns);
```

### 2. Use Specific Patterns Over Generic

Specific patterns are faster than broad ones:

```php
<?php

// Slow: Generic catch-all
$slowPattern = '/\b\d{3}[-.\s]?\d{3}[-.\s]?\d{4}\b/';

// Fast: Specific format
$fastPattern = '/\b\d{3}-\d{3}-\d{4}\b/';
```

### 3. Avoid Catastrophic Backtracking

```php
<?php

// Bad: Potential backtracking issues
$badPattern = '/.*@.*\..*/';

// Good: Bounded repetition
$goodPattern = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/';
```

### 4. Use Non-Capturing Groups

```php
<?php

// Slower: Capturing groups
$slowPattern = '/(foo|bar|baz)/';

// Faster: Non-capturing groups
$fastPattern = '/(?:foo|bar|baz)/';
```

### 5. Pre-validate Patterns

Use the PatternValidator to cache validation results:

```php
<?php

use Ivuorinen\MonologGdprFilter\PatternValidator;

$validator = new PatternValidator();

// Cache all patterns at startup
$validator->cacheAllPatterns($patterns);
```

## Memory Management

### 1. Limit Recursion Depth

```php
<?php

use Ivuorinen\MonologGdprFilter\GdprProcessor;

// Default is 10, reduce for memory-constrained environments
$processor = new GdprProcessor(
    patterns: $patterns,
    maxDepth: 5  // Limit nested array processing
);
```

### 2. Use Streaming for Large Logs

```php
<?php

use Ivuorinen\MonologGdprFilter\Streaming\StreamingProcessor;
use Ivuorinen\MonologGdprFilter\MaskingOrchestrator;

$orchestrator = new MaskingOrchestrator($patterns);
$streaming = new StreamingProcessor(
    orchestrator: $orchestrator,
    chunkSize: 500  // Process 500 records at a time
);

// Process large file with constant memory usage
$lineParser = fn(string $line): array => [
    'message' => $line,
    'context' => [],
];

foreach ($streaming->processFile('/var/log/large.log', $lineParser) as $record) {
    // Handle processed record
}
```

### 3. Disable Audit Logging in High-Volume Scenarios

```php
<?php

use Ivuorinen\MonologGdprFilter\GdprProcessor;

// No audit logger = less memory allocation
$processor = new GdprProcessor(
    patterns: $patterns,
    auditLogger: null
);
```

## Caching Strategies

### 1. Pattern Compilation Caching

Patterns are compiled once and cached internally. Ensure you reuse processor instances:

```php
<?php

// Good: Singleton pattern
class ProcessorFactory
{
    private static ?GdprProcessor $instance = null;

    public static function getInstance(): GdprProcessor
    {
        if (self::$instance === null) {
            self::$instance = new GdprProcessor(DefaultPatterns::all());
        }
        return self::$instance;
    }
}
```

### 2. Result Caching for Repeated Values

For applications processing similar data repeatedly:

```php
<?php

class CachedGdprProcessor
{
    private GdprProcessor $processor;
    private array $cache = [];
    private int $maxCacheSize = 1000;

    public function __construct(GdprProcessor $processor)
    {
        $this->processor = $processor;
    }

    public function process(array $record): array
    {
        $key = md5(serialize($record['message'] . json_encode($record['context'])));

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $result = ($this->processor)($record);

        if (count($this->cache) >= $this->maxCacheSize) {
            array_shift($this->cache);
        }

        $this->cache[$key] = $result;
        return $result;
    }
}
```

## Rate Limiting

### 1. Rate-Limited Audit Logging

Prevent audit log flooding:

```php
<?php

use Ivuorinen\MonologGdprFilter\RateLimitedAuditLogger;
use Ivuorinen\MonologGdprFilter\RateLimiter;

$rateLimiter = new RateLimiter(
    maxEvents: 100,      // Max 100 events
    windowSeconds: 60,   // Per 60 seconds
    burstLimit: 20       // Allow burst of 20
);

$auditLogger = new RateLimitedAuditLogger(
    baseLogger: fn($path, $original, $masked) => error_log("Masked: $path"),
    rateLimiter: $rateLimiter
);
```

### 2. Sampling for High-Volume Logging

```php
<?php

class SampledProcessor
{
    private GdprProcessor $processor;
    private float $sampleRate;

    public function __construct(GdprProcessor $processor, float $sampleRate = 0.1)
    {
        $this->processor = $processor;
        $this->sampleRate = $sampleRate;
    }

    public function __invoke(array $record): array
    {
        // Only process sample of records for audit
        $shouldAudit = (mt_rand() / mt_getrandmax()) < $this->sampleRate;

        if (!$shouldAudit) {
            // Process without audit logging
            return $this->processWithoutAudit($record);
        }

        return ($this->processor)($record);
    }

    private function processWithoutAudit(array $record): array
    {
        // Implement lightweight processing
        return $record;
    }
}
```

## Streaming Large Logs

### 1. Chunk Size Optimization

```php
<?php

use Ivuorinen\MonologGdprFilter\Streaming\StreamingProcessor;

// For memory-constrained environments
$smallChunks = new StreamingProcessor($orchestrator, chunkSize: 100);

// For throughput-optimized environments
$largeChunks = new StreamingProcessor($orchestrator, chunkSize: 1000);
```

### 2. Parallel Processing

For multi-core systems, process chunks in parallel:

```php
<?php

// Using pcntl_fork for parallel processing
function processInParallel(array $files, StreamingProcessor $processor): void
{
    $pids = [];

    foreach ($files as $file) {
        $pid = pcntl_fork();

        if ($pid === 0) {
            // Child process
            $lineParser = fn(string $line): array => ['message' => $line, 'context' => []];
            foreach ($processor->processFile($file, $lineParser) as $record) {
                // Process record
            }
            exit(0);
        }

        $pids[] = $pid;
    }

    // Wait for all children
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
}
```

## Production Configuration

### 1. Minimal Pattern Set

Only include patterns you actually need:

```php
<?php

use Ivuorinen\MonologGdprFilter\DefaultPatterns;
use Ivuorinen\MonologGdprFilter\GdprProcessor;

// Instead of DefaultPatterns::all(), use specific patterns
$patterns = array_merge(
    DefaultPatterns::emails(),
    DefaultPatterns::creditCards(),
    // Only what you need
);

$processor = new GdprProcessor($patterns);
```

### 2. Disable Debug Features

```php
<?php

use Ivuorinen\MonologGdprFilter\Builder\GdprProcessorBuilder;

$processor = (new GdprProcessorBuilder())
    ->withDefaultPatterns()
    ->withMaxDepth(5)           // Limit recursion
    ->withAuditLogger(null)      // Disable audit logging
    ->build();
```

### 3. OPcache Configuration

Ensure OPcache is properly configured in `php.ini`:

```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=10000
opcache.jit=1255
opcache.jit_buffer_size=128M
```

### 4. Preloading (PHP 8.0+)

Create a preload script:

```php
<?php
// preload.php

require_once __DIR__ . '/vendor/autoload.php';

// Preload core classes
$classes = [
    \Ivuorinen\MonologGdprFilter\GdprProcessor::class,
    \Ivuorinen\MonologGdprFilter\MaskingOrchestrator::class,
    \Ivuorinen\MonologGdprFilter\DefaultPatterns::class,
    \Ivuorinen\MonologGdprFilter\PatternValidator::class,
];

foreach ($classes as $class) {
    class_exists($class);
}
```

Configure in `php.ini`:

```ini
opcache.preload=/path/to/preload.php
opcache.preload_user=www-data
```

## Performance Checklist

- [ ] Benchmark baseline performance
- [ ] Order patterns by frequency
- [ ] Use specific patterns over generic
- [ ] Limit recursion depth appropriately
- [ ] Use streaming for large log files
- [ ] Implement rate limiting for audit logs
- [ ] Enable OPcache with JIT
- [ ] Consider preloading in production
- [ ] Reuse processor instances (singleton)
- [ ] Disable unnecessary features in production
