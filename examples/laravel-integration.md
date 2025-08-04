# Laravel Integration Examples

This document provides comprehensive examples for integrating the Monolog GDPR Filter with Laravel applications.

## Installation and Setup

### 1. Install the Package

```bash
composer require ivuorinen/monolog-gdpr-filter
```

### 2. Register the Service Provider

Add the service provider to your `config/app.php`:

```php
'providers' => [
    // Other providers...
    Ivuorinen\MonologGdprFilter\Laravel\GdprServiceProvider::class,
],
```

### 3. Add the Facade (Optional)

```php
'aliases' => [
    // Other aliases...
    'Gdpr' => Ivuorinen\MonologGdprFilter\Laravel\Facades\Gdpr::class,
],
```

### 4. Publish the Configuration

```bash
php artisan vendor:publish --tag=gdpr-config
```

## Configuration Examples

### Basic Configuration (`config/gdpr.php`)

```php
<?php

return [
    'auto_register' => true,
    'channels' => ['single', 'daily', 'stack'],
    
    'field_paths' => [
        'user.email' => '', // Mask with regex
        'user.ssn' => GdprProcessor::removeField(),
        'payment.card_number' => GdprProcessor::replaceWith('[CARD]'),
        'request.password' => GdprProcessor::removeField(),
    ],
    
    'custom_callbacks' => [
        'user.ip' => fn($value) => hash('sha256', $value), // Hash IPs
        'user.name' => fn($value) => strtoupper($value),   // Transform names
    ],
    
    'max_depth' => 100,
    
    'audit_logging' => [
        'enabled' => env('GDPR_AUDIT_ENABLED', false),
        'channel' => 'gdpr-audit',
    ],
];
```

### Advanced Configuration

```php
<?php

use Ivuorinen\MonologGdprFilter\GdprProcessor;

return [
    'patterns' => [
        // Custom patterns for your application
        '/\binternal-id-\d+\b/' => '***INTERNAL***',
        '/\bcustomer-\d{6}\b/' => '***CUSTOMER***',
    ],
    
    'field_paths' => [
        // User data
        'user.email' => '',
        'user.phone' => GdprProcessor::replaceWith('[PHONE]'),
        'user.address' => GdprProcessor::removeField(),
        
        // Payment data
        'payment.card_number' => GdprProcessor::replaceWith('[CARD]'),
        'payment.cvv' => GdprProcessor::removeField(),
        'payment.account_number' => GdprProcessor::replaceWith('[ACCOUNT]'),
        
        // Request data
        'request.password' => GdprProcessor::removeField(),
        'request.token' => GdprProcessor::replaceWith('[TOKEN]'),
        'headers.authorization' => GdprProcessor::replaceWith('[AUTH]'),
    ],
    
    'custom_callbacks' => [
        // Hash sensitive identifiers
        'user.ip' => fn($ip) => 'ip_' . substr(hash('sha256', $ip), 0, 8),
        'session.id' => fn($id) => 'sess_' . substr(hash('sha256', $id), 0, 12),
        
        // Mask parts of identifiers
        'user.username' => function($username) {
            if (strlen($username) <= 3) return '***';
            return substr($username, 0, 2) . str_repeat('*', strlen($username) - 2);
        },
    ],
];
```

## Usage Examples

### 1. Using the Facade

```php
<?php

use Ivuorinen\MonologGdprFilter\Laravel\Facades\Gdpr;

// Mask a message directly
$maskedMessage = Gdpr::regExpMessage('Contact john.doe@example.com for details');
// Result: "Contact ***EMAIL*** for details"

// Get default patterns
$patterns = Gdpr::getDefaultPatterns();

// Test pattern validation
try {
    Gdpr::validatePatterns(['/\btest\b/' => '***TEST***']);
    echo "Pattern is valid!";
} catch (InvalidArgumentException $e) {
    echo "Pattern error: " . $e->getMessage();
}
```

### 2. Manual Integration with Specific Channels

```php
<?php

// In a service provider or middleware
use Illuminate\Support\Facades\Log;
use Ivuorinen\MonologGdprFilter\GdprProcessor;

$processor = app('gdpr.processor');

// Add to specific channel
Log::channel('api')->pushProcessor($processor);
Log::channel('audit')->pushProcessor($processor);
```

### 3. Custom Logging with GDPR Protection

```php
<?php

use Illuminate\Support\Facades\Log;

class UserService
{
    public function createUser(array $userData)
    {
        // This will automatically be GDPR filtered
        Log::info('Creating user', [
            'user_data' => $userData, // Contains email, phone, etc.
            'request_ip' => request()->ip(),
            'timestamp' => now(),
        ]);
        
        // User creation logic...
    }
    
    public function loginAttempt(string $email, bool $success)
    {
        Log::info('Login attempt', [
            'email' => $email, // Will be masked
            'success' => $success,
            'ip' => request()->ip(), // Will be hashed if configured
            'user_agent' => request()->userAgent(),
        ]);
    }
}
```

## Artisan Commands

### Test Regex Patterns

```bash
# Test a pattern against sample data
php artisan gdpr:test-pattern '/\b\d{3}-\d{2}-\d{4}\b/' '***SSN***' '123-45-6789'

# With validation
php artisan gdpr:test-pattern '/\b\d{16}\b/' '***CARD***' '4111111111111111' --validate
```

### Debug Configuration

```bash
# Show current configuration
php artisan gdpr:debug --show-config

# Show all patterns
php artisan gdpr:debug --show-patterns

# Test with sample data
php artisan gdpr:debug \
  --test-data='{
    "message":"Email: test@example.com", "context":{"user":{"email":"user@example.com"}}
  }'
```

## Middleware Integration

### HTTP Request/Response Logging

Register the middleware in `app/Http/Kernel.php`:

```php
protected $middleware = [
    // Other middleware...
    \Ivuorinen\MonologGdprFilter\Laravel\Middleware\GdprLogMiddleware::class,
];
```

Or apply to specific routes:

```php
Route::middleware(['gdpr.log'])->group(function () {
    Route::post('/api/users', [UserController::class, 'store']);
    Route::put('/api/users/{id}', [UserController::class, 'update']);
});
```

### Custom Middleware Example

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Ivuorinen\MonologGdprFilter\Laravel\Facades\Gdpr;

class ApiRequestLogger
{
    public function handle(Request $request, Closure $next)
    {
        $startTime = microtime(true);
        
        // Log request
        Log::info('API Request', [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'headers' => $request->headers->all(),
            'body' => $request->all(),
        ]);
        
        $response = $next($request);
        
        // Log response
        Log::info('API Response', [
            'status' => $response->getStatusCode(),
            'duration' => round((microtime(true) - $startTime) * 1000, 2),
            'memory' => memory_get_peak_usage(true),
        ]);
        
        return $response;
    }
}
```

## Testing

### Unit Testing with GDPR

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use Ivuorinen\MonologGdprFilter\Laravel\Facades\Gdpr;

class GdprTest extends TestCase
{
    public function test_email_masking()
    {
        $result = Gdpr::regExpMessage('Contact john@example.com');
        $this->assertStringContains('***EMAIL***', $result);
    }
    
    public function test_custom_pattern()
    {
        $processor = new GdprProcessor([
            '/\bcustomer-\d+\b/' => '***CUSTOMER***'
        ]);
        
        $result = $processor->regExpMessage('Order for customer-12345');
        $this->assertEquals('Order for ***CUSTOMER***', $result);
    }
}
```

### Integration Testing

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Log;

class GdprLoggingTest extends TestCase
{
    public function test_user_creation_logging()
    {
        Log::shouldReceive('info')
            ->once()
            ->with('Creating user', \Mockery::on(function ($context) {
                // Verify that email is masked
                return str_contains($context['user_data']['email'], '***EMAIL***');
            }));
            
        $response = $this->postJson('/api/users', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '+1234567890',
        ]);
        
        $response->assertStatus(201);
    }
}
```

## Performance Considerations

### Optimize for Large Applications

```php
<?php

// config/gdpr.php
return [
    'performance' => [
        'chunk_size' => 500, // Smaller chunks for memory-constrained environments
        'garbage_collection_threshold' => 5000, // More frequent GC
    ],
    
    // Use more specific patterns to reduce processing time
    'patterns' => [
        '/\b[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}\b/' => '***EMAIL***',
        '/\b\d{3}-\d{2}-\d{4}\b/' => '***SSN***',
        // Avoid overly broad patterns
    ],
    
    // Prefer field paths over regex for known locations
    'field_paths' => [
        'user.email' => '',
        'request.email' => '',
        'customer.email_address' => '',
    ],
];
```

### Channel-Specific Configuration

```php
<?php

// Apply GDPR only to specific channels
'channels' => [
    'single',    // Local development
    'daily',     // Production file logs
    'database',  // Database logging
    // Skip 'stderr' for performance-critical error logging
],
```

## Troubleshooting

### Common Issues

1. **GDPR not working**: Check if auto_register is true and channels are correctly configured
2. **Performance issues**: Reduce pattern count, use field_paths instead of regex
3. **Over-masking**: Make patterns more specific, check pattern order
4. **Memory issues**: Reduce chunk_size and garbage_collection_threshold

### Debug Steps

```bash
# Check configuration
php artisan gdpr:debug --show-config

# Test patterns
php artisan gdpr:test-pattern '/your-pattern/' '***MASKED***' 'test-string'

# View current patterns
php artisan gdpr:debug --show-patterns
```

## Best Practices

1. **Use field paths over regex** when you know the exact location of sensitive data
2. **Test patterns thoroughly** before deploying to production
3. **Monitor performance** with large datasets
4. **Use audit logging** for compliance requirements
5. **Regularly review patterns** to ensure they're not over-masking
6. **Consider data retention** policies for logged data
