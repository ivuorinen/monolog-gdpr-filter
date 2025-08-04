# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Phase 6: Code Quality & Architecture ✅ COMPLETED (2025-07-29)**:
  - **Custom Exception Classes**: Comprehensive exception hierarchy with rich context and error reporting
    - `GdprProcessorException` - Base exception with context support for key-value error reporting
    - `InvalidRegexPatternException` - Regex compilation errors with PCRE error details and ReDoS detection
    - `MaskingOperationFailedException` - Failed masking operations with operation context and value previews
    - `AuditLoggingException` - Audit logger failures with operation tracking and serialization error handling
    - `RecursionDepthExceededException` - Deep nesting issues with recommendations and circular reference detection
  - **Masking Strategy Interface System**: Complete extensible strategy pattern implementation
    - `MaskingStrategyInterface` - Comprehensive method contracts for masking, validation, priority, and configuration
    - `AbstractMaskingStrategy` - Base class with utilities for path matching, type preservation, and value conversion
    - `RegexMaskingStrategy` - Pattern-based masking with ReDoS protection and include/exclude path filtering
    - `FieldPathMaskingStrategy` - Dot-notation field path masking with wildcard support and FieldMaskConfig integration
    - `ConditionalMaskingStrategy` - Context-aware conditional masking with AND/OR logic and factory methods
    - `DataTypeMaskingStrategy` - PHP type-based masking with type-specific conversion and factory methods
    - `StrategyManager` - Priority-based coordination with strategy validation, statistics, and default factory
  - **PHP 8.2+ Modernization**: Comprehensive codebase modernization with backward compatibility
    - Converted `FieldMaskConfig` to readonly class for immutability
    - Added modern type declarations with proper imports (`Throwable`, `Closure`, `JsonException`)
    - Applied `::class` syntax for class references instead of `get_class()`
    - Implemented arrow functions where appropriate for concise code
    - Used modern array comparisons (`=== []` instead of `empty()`)
    - Enhanced string formatting with `sprintf()` for better performance
    - Added newline consistency and proper imports throughout codebase
  - **Code Quality Improvements**: Significant enhancements to code standards and type safety
    - Fixed 287 PHPCS style issues automatically through code beautifier
    - Reduced Psalm static analysis errors from 100+ to 61 (mostly false positives)
    - Achieved 97.89% type coverage in Psalm analysis
    - Applied 29 Rector modernization rules for PHP 8.2+ features
    - Enhanced docblock types and removed redundant return tags
    - Improved parameter type coercion and null safety
- **Phase 5: Advanced Features ✅ COMPLETED (2025-07-29)**:
  - **Data Type-Based Masking**: Configurable type-specific masks for integers, strings, booleans, null, arrays, and objects
  - **Conditional Masking**: Context-aware masking based on log level, channel, and custom rules with AND logic
  - **Helper Methods**: Creating common conditional rules (level-based, channel-based, context field presence)
  - **JSON String Masking**: Detection and recursive processing of JSON strings within log messages with validation
  - **Rate Limiting**: Configurable audit logger rate limiting to prevent log flooding (profiles: strict, default, relaxed, testing)
  - **Operation Classification**: Different rate limits for different operation types (JSON, conditional, regex, general)
  - **Enhanced Audit Logging**: Detailed error context, conditional rule decisions, and operation tracking
  - **Comprehensive Testing**: 30+ new tests across 6 test files (DataTypeMaskingTest, ConditionalMaskingTest, JsonMaskingTest, RateLimiterTest, RateLimitedAuditLoggerTest, GdprProcessorRateLimitingIntegrationTest)
  - **Examples**: Created comprehensive examples for conditional masking and rate limiting features
- **Phase 4: Performance Optimizations ✅ COMPLETED (2025-07-29)**:
  - **Exceptional Performance**: Optimized processing to 0.004ms per operation (exceeded 0.007ms target)
  - **Static Pattern Caching**: 6.6% performance improvement after warmup with regex pattern validation
  - **Recursion Depth Limiting**: Configurable maximum depth (default: 100 levels) preventing stack overflow
  - **Memory-Efficient Processing**: Chunked processing for large nested arrays (1000+ items)
  - **Automatic Garbage Collection**: For very large datasets (10,000+ items) with memory optimization
  - **Memory Usage**: Optimized to only 2MB for 2,000 nested items with efficient data structures
- **Phase 4: Laravel Integration Package ✅ COMPLETED (2025-07-29)**:
  - **Service Provider**: Complete Laravel Service Provider with auto-registration and configuration
  - **Configuration**: Publishable configuration file with comprehensive GDPR processing options
  - **Facade**: Laravel Facade for easy access (`Gdpr::regExpMessage()`, `Gdpr::createProcessor()`)
  - **Artisan Commands**: Pattern testing and debugging commands (`gdpr:test-pattern`, `gdpr:debug`)
  - **HTTP Middleware**: Request/response GDPR logging middleware for web applications
  - **Documentation**: Comprehensive Laravel integration examples and step-by-step setup guide
- **Phase 4: Testing & Quality Assurance ✅ COMPLETED (2025-07-29)**:
  - **Performance Benchmarks**: Tests measuring actual optimization impact (0.004ms per operation)
  - **Memory Usage Tests**: Validation for large datasets with memory efficiency tracking
  - **Concurrent Processing**: Simulation tests for high-volume concurrent processing scenarios
  - **Pattern Caching**: Effectiveness validation showing 6.6% improvement after warmup
- **Major GDPR Pattern Expansion**: Added 15+ new patterns doubling coverage
  - IPv4 and IPv6 IP address patterns
  - Vehicle registration number patterns (US license plates)
  - National ID patterns (UK National Insurance, Canadian SIN)
  - Bank account patterns (UK sort codes, Canadian transit numbers)
  - Health insurance patterns (US Medicare, European Health Insurance Cards)
- **Enhanced Security**: 
  - Regex pattern validation to prevent injection attacks
  - ReDoS (Regular Expression Denial of Service) protection
  - Comprehensive error handling replacing `@` suppression
- **Type Safety Improvements**:
  - Fixed all PHPStan type errors for better code quality
  - Enhanced type annotations throughout codebase
  - Improved generic type specifications
- **Development Infrastructure**:
  - PHPStan configuration file with maximum level analysis
  - GitHub Actions CI/CD pipeline with multi-PHP version testing
  - Automated security scanning and dependency updates
  - Comprehensive documentation (CONTRIBUTING.md, SECURITY.md)
- **Quality Assurance**:
  - Enhanced test suite with improved error handling validation
  - All tests passing across PHP 8.2, 8.3, and 8.4
  - Comprehensive linting with Psalm, PHPStan, and PHPCS

### Changed
- **Phase 6: Code Quality & Architecture (2025-07-29)**:
  - **Exception System**: Replaced generic exceptions with specific, context-rich exception classes
  - **Strategy Pattern**: Refactored masking logic into pluggable strategy system with priority management
  - **Type System**: Enhanced type safety with PHP 8.2+ features and strict type declarations
  - **Code Standards**: Applied modern PHP conventions and automated code quality improvements
- **Phase 5: Advanced Features (2025-07-29)**:
  - **Improved Error Handling**: Replaced error suppression with proper try-catch blocks
  - **Enhanced Audit Logging**: More detailed error context and security measures
  - **Better Pattern Organization**: Grouped patterns by category with clear documentation
  - **Type Safety**: Stricter type declarations and validation throughout

### Security
- **Phase 6: Enhanced Security (2025-07-29)**:
  - **ReDoS Protection**: Enhanced regular expression denial of service detection in InvalidRegexPatternException
  - **Type Safety**: Improved parameter validation and type coercion safety
  - **Error Context**: Added secure error reporting without exposing sensitive data
- **Phase 5: Critical Security Fixes (2025-07-29)**:
  - Eliminated regex injection vulnerabilities
  - Added ReDoS attack protection
  - Implemented pattern validation for untrusted input
  - Enhanced audit logger security measures

### Fixed
- **Phase 6: Code Quality Fixes (2025-07-29)**:
  - Fixed 287 PHPCS formatting and style issues
  - Resolved Psalm type coercion warnings and parameter type issues
  - Improved null safety and optional parameter handling
  - Enhanced docblock accuracy and type specifications
- **Phase 5: Stability Fixes (2025-07-29)**:
  - All PHPStan type safety errors resolved
  - Improved error handling in regex processing
  - Fixed potential security vulnerabilities in pattern handling
  - Resolved test compatibility issues across PHP versions

## [Previous Versions]

### [1.0.0] - Initial Release
- Basic GDPR processor implementation
- Initial pattern set (Finnish SSN, US SSN, IBAN, etc.)
- Monolog integration
- Laravel compatibility
- Field-level masking with dot notation
- Custom callback support
- Audit logging functionality

---

## Migration Guide

### From 1.x to 2.x (Upcoming)

#### Breaking Changes
- None currently - maintaining backward compatibility

#### Deprecated Features
- `setAuditLogger()` method parameter type changed (constructor parameter preferred)

#### New Features
- 15+ new GDPR patterns available by default
- Enhanced security validation
- Improved error handling and logging

#### Security Improvements
- All regex patterns now validated for safety
- ReDoS protection enabled by default
- Enhanced audit logging security

### Developer Notes

#### Pattern Validation
New patterns are automatically validated for:
- Basic regex syntax correctness
- ReDoS attack patterns
- Security vulnerabilities

#### Error Handling
The library now uses proper exception handling instead of error suppression:
```php
// Old (deprecated)
$result = @preg_replace($pattern, $replacement, $input);

// New (secure)
try {
    $result = preg_replace($pattern, $replacement, $input);
    if ($result === null) {
        // Handle error properly
    }
} catch (\Error $e) {
    // Handle regex compilation errors
}
```

#### Type Safety
Enhanced type declarations provide better IDE support and error detection:
```php
// Improved type annotations
/**
 * @param array<string, string> $patterns
 * @param array<string, FieldMaskConfig|string> $fieldPaths
 */
```

---

## Contributing

Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details on how to contribute to this project.

## Security

Please see [SECURITY.md](SECURITY.md) for information about reporting security vulnerabilities.

## Support

- **Documentation**: See README.md for usage examples
- **Issues**: Report bugs and request features via GitHub Issues
- **Discussions**: General questions via GitHub Discussions
