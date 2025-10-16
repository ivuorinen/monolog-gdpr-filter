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

# Safe analysis script (recommended for comprehensive analysis)
./scripts/safe-analyze.sh           # Interactive analysis workflow
./scripts/safe-analyze.sh --all --dry-run    # Run all tools safely (dry-run)
./scripts/safe-analyze.sh --all --apply      # Apply safe changes with backup
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
- **Psalm Level 5** static analysis with conservative configuration
- **PHPStan Level 6** for additional code quality insights
- **Rector** for safe automated code improvements
- **EditorConfig**: 4 spaces, LF line endings, UTF-8, trim trailing whitespace
- **PHPUnit 11** for testing with strict configuration

### Static Analysis & Linting Policy

**All issues reported by static analysis tools MUST be fixed.** The project uses a comprehensive static analysis setup:

- **Psalm**: Conservative Level 5 with targeted suppressions for valid patterns
- **PHPStan**: Level 6 analysis with Laravel compatibility
- **Rector**: Safe automated improvements (return types, string casting, etc.)
- **PHPCS**: PSR-12 compliance enforcement

**Issue Resolution Priority:**
1. **Fix the underlying issue** (preferred approach)
2. **Refactor code** to avoid the issue pattern
3. **Use safe automated fixes** via `composer lint:fix` or `./scripts/safe-analyze.sh`
4. **Ask before suppressing** - Suppression should be used only as an absolute last resort and requires
   explicit discussion

**Use the safe analysis script** (`./scripts/safe-analyze.sh`) for comprehensive analysis workflows
with backup/restore capabilities.

## Important Notes

- **Always run `composer lint:fix` or `./scripts/safe-analyze.sh` before manual fixes**
- **Fix all linting issues** - suppression requires explicit approval
- The library focuses on GDPR compliance - be careful when modifying masking logic
- Default patterns include Finnish SSN, US SSN, IBAN, credit cards, emails, phones, and IPs
- Audit logging feature can track when sensitive data was masked for compliance
- Use the safe analysis script for comprehensive static analysis workflows
- All static analysis tools are configured to work harmoniously without conflicts
