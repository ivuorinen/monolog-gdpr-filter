# Plugin Development Guide

This guide explains how to create custom plugins for the Monolog GDPR Filter library.

## Table of Contents

- [Introduction](#introduction)
- [Quick Start](#quick-start)
- [Plugin Interface](#plugin-interface)
- [Abstract Base Class](#abstract-base-class)
- [Registration](#registration)
- [Hook Execution Order](#hook-execution-order)
- [Priority System](#priority-system)
- [Configuration Contribution](#configuration-contribution)
- [Use Cases](#use-cases)
- [Best Practices](#best-practices)

## Introduction

Plugins extend the GDPR processor's functionality without modifying core code. Use plugins when you need to:

- Add custom masking patterns for your domain
- Transform messages before or after standard masking
- Enrich context with metadata
- Integrate with external systems
- Apply organization-specific compliance rules

### When to Use Plugins vs. Configuration

| Scenario | Use Plugin | Use Configuration |
|----------|-----------|-------------------|
| Add regex patterns | ✅ (via `getPatterns()`) | ✅ (via constructor) |
| Custom transformation logic | ✅ | ❌ |
| Conditional processing | ✅ | ❌ |
| Multiple reusable rules | ✅ | ❌ |
| Simple field masking | ❌ | ✅ |

## Quick Start

Create a minimal plugin in three steps:

### Step 1: Create the Plugin Class

```php
<?php

namespace App\Logging\Plugins;

use Ivuorinen\MonologGdprFilter\Plugins\AbstractMaskingPlugin;

class MyCompanyPlugin extends AbstractMaskingPlugin
{
    public function getName(): string
    {
        return 'my-company-plugin';
    }

    public function getPatterns(): array
    {
        return [
            '/INTERNAL-\d{6}/' => '[INTERNAL-ID]',  // Internal ID format
            '/EMP-[A-Z]{2}\d{4}/' => '[EMPLOYEE-ID]',  // Employee IDs
        ];
    }
}
```

### Step 2: Register the Plugin

```php
<?php

use Ivuorinen\MonologGdprFilter\Builder\GdprProcessorBuilder;
use App\Logging\Plugins\MyCompanyPlugin;

$processor = GdprProcessorBuilder::create()
    ->withDefaultPatterns()
    ->addPlugin(new MyCompanyPlugin())
    ->buildWithPlugins();
```

### Step 3: Use with Monolog

```php
<?php

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('app');
$logger->pushHandler(new StreamHandler('app.log'));
$logger->pushProcessor($processor);

// Internal IDs and employee IDs are now masked
$logger->info('User INTERNAL-123456 (EMP-AB1234) logged in');
// Output: User [INTERNAL-ID] ([EMPLOYEE-ID]) logged in
```

## Plugin Interface

All plugins must implement `MaskingPluginInterface`:

```php
interface MaskingPluginInterface
{
    // Identification
    public function getName(): string;

    // Pre-processing hooks (before standard masking)
    public function preProcessContext(array $context): array;
    public function preProcessMessage(string $message): string;

    // Post-processing hooks (after standard masking)
    public function postProcessContext(array $context): array;
    public function postProcessMessage(string $message): string;

    // Configuration contribution
    public function getPatterns(): array;
    public function getFieldPaths(): array;

    // Execution order control
    public function getPriority(): int;
}
```

### Method Reference

| Method | Purpose | When Called |
|--------|---------|-------------|
| `getName()` | Unique identifier for debugging | On registration |
| `preProcessContext()` | Modify context before masking | Before core masking |
| `preProcessMessage()` | Modify message before masking | Before core masking |
| `postProcessContext()` | Modify context after masking | After core masking |
| `postProcessMessage()` | Modify message after masking | After core masking |
| `getPatterns()` | Provide regex patterns | During build |
| `getFieldPaths()` | Provide field paths to mask | During build |
| `getPriority()` | Control execution order | During sorting |

## Abstract Base Class

Extend `AbstractMaskingPlugin` to avoid implementing unused methods:

```php
<?php

namespace Ivuorinen\MonologGdprFilter\Plugins;

abstract class AbstractMaskingPlugin implements MaskingPluginInterface
{
    public function __construct(protected readonly int $priority = 100)
    {
    }

    // Default implementations return input unchanged
    public function preProcessContext(array $context): array { return $context; }
    public function postProcessContext(array $context): array { return $context; }
    public function preProcessMessage(string $message): string { return $message; }
    public function postProcessMessage(string $message): string { return $message; }
    public function getPatterns(): array { return []; }
    public function getFieldPaths(): array { return []; }
    public function getPriority(): int { return $this->priority; }
}
```

### Benefits

- Override only the methods you need
- Default priority of 100 (customizable via constructor)
- All hooks pass data through unchanged by default

## Registration

Register plugins using `GdprProcessorBuilder`:

```php
<?php

use Ivuorinen\MonologGdprFilter\Builder\GdprProcessorBuilder;

// Single plugin
$processor = GdprProcessorBuilder::create()
    ->addPlugin($plugin)
    ->buildWithPlugins();

// Multiple plugins
$processor = GdprProcessorBuilder::create()
    ->addPlugins([$plugin1, $plugin2, $plugin3])
    ->buildWithPlugins();

// With other configuration
$processor = GdprProcessorBuilder::create()
    ->withDefaultPatterns()
    ->addPattern('/custom/', '[MASKED]')
    ->addFieldPath('secret', FieldMaskConfig::remove())
    ->addPlugin($plugin)
    ->withAuditLogger($auditLogger)
    ->buildWithPlugins();
```

### Return Types

```php
// No plugins: returns GdprProcessor (no wrapper overhead)
$processor = GdprProcessorBuilder::create()
    ->withDefaultPatterns()
    ->buildWithPlugins();  // GdprProcessor

// With plugins: returns PluginAwareProcessor (wraps GdprProcessor)
$processor = GdprProcessorBuilder::create()
    ->addPlugin($plugin)
    ->buildWithPlugins();  // PluginAwareProcessor
```

## Hook Execution Order

Understanding execution order is critical for plugins that interact:

```
1. preProcessMessage()  - Plugins in priority order (10, 20, 30...)
2. preProcessContext()  - Plugins in priority order (10, 20, 30...)
3. [Core GdprProcessor masking]
4. postProcessMessage() - Plugins in REVERSE order (30, 20, 10...)
5. postProcessContext() - Plugins in REVERSE order (30, 20, 10...)
```

### Why Reverse Order for Post-Processing?

Post-processing runs in reverse to properly "unwrap" transformations:

```php
// Plugin A (priority 10) wraps: "data" -> "[A:data:A]"
// Plugin B (priority 20) wraps: "[A:data:A]" -> "[B:[A:data:A]:B]"

// Post-processing reverse order ensures proper unwrapping:
// Plugin B runs first: "[B:[A:masked:A]:B]" -> "[A:masked:A]"
// Plugin A runs second: "[A:masked:A]" -> "masked"
```

## Priority System

Lower numbers execute earlier in pre-processing:

```php
class HighPriorityPlugin extends AbstractMaskingPlugin
{
    public function __construct()
    {
        parent::__construct(priority: 10);  // Runs early
    }
}

class NormalPriorityPlugin extends AbstractMaskingPlugin
{
    // Default priority: 100
}

class LowPriorityPlugin extends AbstractMaskingPlugin
{
    public function __construct()
    {
        parent::__construct(priority: 200);  // Runs late
    }
}
```

### Recommended Priority Ranges

| Range | Use Case | Example |
|-------|----------|---------|
| 1-50 | Security/validation | Input sanitization |
| 50-100 | Standard processing | Pattern masking |
| 100-150 | Business logic | Domain-specific rules |
| 150-200 | Enrichment | Adding metadata |
| 200+ | Cleanup/finalization | Removing temp fields |

## Configuration Contribution

Plugins can contribute patterns and field paths that are merged into the processor:

### Adding Patterns

```php
public function getPatterns(): array
{
    return [
        '/ACME-\d{8}/' => '[ACME-ORDER]',
        '/INV-[A-Z]{2}-\d+/' => '[INVOICE]',
    ];
}
```

### Adding Field Paths

```php
use Ivuorinen\MonologGdprFilter\FieldMaskConfig;

public function getFieldPaths(): array
{
    return [
        // Static replacement
        'api_key' => FieldMaskConfig::replace('[API_KEY]'),

        // Remove field entirely
        'internal.debug' => FieldMaskConfig::remove(),

        // Apply regex to field value
        'user.notes' => FieldMaskConfig::regexMask('/\d{3}-\d{2}-\d{4}/', '[SSN]'),

        // Use processor's global patterns
        'user.bio' => FieldMaskConfig::useProcessorPatterns(),
    ];
}
```

## Use Cases

### Use Case 1: Message Transformation

Transform messages before masking:

```php
class NormalizePlugin extends AbstractMaskingPlugin
{
    public function getName(): string
    {
        return 'normalize-plugin';
    }

    public function preProcessMessage(string $message): string
    {
        // Normalize whitespace before masking
        return preg_replace('/\s+/', ' ', trim($message));
    }
}
```

### Use Case 2: Domain-Specific Patterns

Add patterns for your organization:

```php
class HealthcarePlugin extends AbstractMaskingPlugin
{
    public function getName(): string
    {
        return 'healthcare-plugin';
    }

    public function getPatterns(): array
    {
        return [
            // Medical Record Number
            '/MRN-\d{10}/' => '[MRN]',
            // National Provider Identifier
            '/NPI-\d{10}/' => '[NPI]',
            // DEA Number
            '/DEA-[A-Z]{2}\d{7}/' => '[DEA]',
        ];
    }

    public function getFieldPaths(): array
    {
        return [
            'patient.diagnosis' => FieldMaskConfig::replace('[PHI]'),
            'patient.medications' => FieldMaskConfig::remove(),
        ];
    }
}
```

### Use Case 3: Context Enrichment

Add metadata to context:

```php
class AuditPlugin extends AbstractMaskingPlugin
{
    public function getName(): string
    {
        return 'audit-plugin';
    }

    public function __construct(private readonly string $environment)
    {
        parent::__construct(priority: 150);  // Run late
    }

    public function postProcessContext(array $context): array
    {
        $context['_audit'] = [
            'processed_at' => date('c'),
            'environment' => $this->environment,
            'plugin_version' => '1.0.0',
        ];
        return $context;
    }
}
```

### Use Case 4: Conditional Masking

Apply masking based on conditions:

```php
class EnvironmentAwarePlugin extends AbstractMaskingPlugin
{
    public function getName(): string
    {
        return 'environment-aware-plugin';
    }

    public function preProcessContext(array $context): array
    {
        // Only mask in production
        if (getenv('APP_ENV') !== 'production') {
            return $context;
        }

        // Add extra masking for production
        if (isset($context['debug_info'])) {
            $context['debug_info'] = '[REDACTED IN PRODUCTION]';
        }

        return $context;
    }
}
```

### Use Case 5: External Integration

Integrate with external services:

```php
class CompliancePlugin extends AbstractMaskingPlugin
{
    public function getName(): string
    {
        return 'compliance-plugin';
    }

    public function __construct(
        private readonly ComplianceService $service
    ) {
        parent::__construct(priority: 50);
    }

    public function postProcessContext(array $context): array
    {
        // Log to compliance system
        $this->service->recordMaskingEvent(
            fields: array_keys($context),
            timestamp: new \DateTimeImmutable()
        );

        return $context;
    }
}
```

## Best Practices

### 1. Keep Plugins Focused

Each plugin should have a single responsibility:

```php
// Good: Single purpose
class EmailPatternPlugin extends AbstractMaskingPlugin { /* ... */ }
class PhonePatternPlugin extends AbstractMaskingPlugin { /* ... */ }

// Avoid: Multiple unrelated responsibilities
class EverythingPlugin extends AbstractMaskingPlugin { /* ... */ }
```

### 2. Use Descriptive Names

Plugin names should be unique and descriptive:

```php
// Good
public function getName(): string
{
    return 'acme-healthcare-hipaa-v2';
}

// Avoid
public function getName(): string
{
    return 'plugin1';
}
```

### 3. Handle Errors Gracefully

Plugins should not throw exceptions that break logging:

```php
public function preProcessContext(array $context): array
{
    try {
        // Risky operation
        $context['processed'] = $this->riskyTransform($context);
    } catch (\Throwable $e) {
        // Log error but don't break logging
        error_log("Plugin error: " . $e->getMessage());
    }

    return $context;  // Always return context
}
```

### 4. Document Your Patterns

Add comments explaining pattern purpose:

```php
public function getPatterns(): array
{
    return [
        // ACME internal order numbers: ACME-YYYYMMDD-NNNN
        '/ACME-\d{8}-\d{4}/' => '[ORDER-ID]',

        // Employee badges: EMP followed by 6 digits
        '/EMP\d{6}/' => '[EMPLOYEE]',
    ];
}
```

### 5. Test Your Plugins

Create comprehensive tests:

```php
class MyPluginTest extends TestCase
{
    public function testPatternMasking(): void
    {
        $plugin = new MyPlugin();
        $patterns = $plugin->getPatterns();

        // Test each pattern
        foreach ($patterns as $pattern => $replacement) {
            $this->assertMatchesRegularExpression($pattern, 'INTERNAL-123456');
        }
    }

    public function testPreProcessing(): void
    {
        $plugin = new MyPlugin();
        $context = ['sensitive' => 'value'];

        $result = $plugin->preProcessContext($context);

        $this->assertArrayHasKey('sensitive', $result);
    }
}
```

### 6. Consider Performance

Avoid expensive operations in hooks that run for every log entry:

```php
// Good: Simple operations
public function preProcessMessage(string $message): string
{
    return trim($message);
}

// Avoid: Heavy operations for every log
public function preProcessMessage(string $message): string
{
    return $this->httpClient->validateMessage($message);  // Slow!
}
```

### 7. Use Priority Thoughtfully

Consider how your plugin interacts with others:

```php
// Security validation should run early
class SecurityPlugin extends AbstractMaskingPlugin
{
    public function __construct()
    {
        parent::__construct(priority: 10);
    }
}

// Metadata enrichment should run late
class MetadataPlugin extends AbstractMaskingPlugin
{
    public function __construct()
    {
        parent::__construct(priority: 180);
    }
}
```
