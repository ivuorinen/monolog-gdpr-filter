# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

### Development
```bash
# Install dependencies
composer install

# Run all linting tools
composer lint

# Auto-fix code issues (runs Rector, Psalm fix, and PHPCBF)
composer lint:fix

# Run tests with coverage
composer test
composer test:coverage  # Generates HTML coverage report

# Individual linting tools
composer lint:tool:phpcs     # PHP_CodeSniffer
composer lint:tool:phpcbf    # PHP Code Beautifier and Fixer
composer lint:tool:psalm     # Static analysis
composer lint:tool:psalm:fix # Auto-fix Psalm issues
composer lint:tool:rector    # Code refactoring
```

### Testing
```bash
# Run all tests
composer test

# Run specific test file
./vendor/bin/phpunit tests/GdprProcessorTest.php

# Run specific test method
./vendor/bin/phpunit --filter testMethodName
```

## Architecture

This is a Monolog processor library for GDPR compliance that masks sensitive data in logs.

### Core Components

1. **GdprProcessor** (`src/GdprProcessor.php`): The main processor implementing Monolog's `ProcessorInterface`
   - Processes log records to mask/remove/replace sensitive data
   - Supports regex patterns, field paths (dot notation), and custom callbacks
   - Provides static factory methods for common field configurations
   - Includes default GDPR patterns (SSN, credit cards, emails, etc.)

2. **FieldMaskConfig** (`src/FieldMaskConfig.php`): Configuration value object with three types:
   - `MASK_REGEX`: Apply regex patterns to field value
   - `REMOVE`: Remove field entirely from context
   - `REPLACE`: Replace with static value

### Key Design Patterns

- **Processor Pattern**: Implements Monolog's ProcessorInterface for log record transformation
- **Value Objects**: FieldMaskConfig is immutable configuration
- **Factory Methods**: Static methods for creating common configurations
- **Dot Notation**: Uses `adbario/php-dot-notation` for nested array access (e.g., "user.email")

### Laravel Integration

The library can be integrated with Laravel in two ways:
1. Service Provider registration
2. Using a Tap class to modify logging channels

## Code Standards

- **PHP 8.2+** with strict types
- **PSR-12** coding standard (enforced by PHP_CodeSniffer)
- **Psalm Level 3** static analysis with strict equality plugin
- **EditorConfig**: 4 spaces, LF line endings, UTF-8, trim trailing whitespace
- **PHPUnit 11** for testing with strict configuration

## Important Notes

- Always run `composer lint:fix` before manual fixes
- The library focuses on GDPR compliance - be careful when modifying masking logic
- Default patterns include Finnish SSN, US SSN, IBAN, credit cards, emails, phones, and IPs
- Audit logging feature can track when sensitive data was masked for compliance
