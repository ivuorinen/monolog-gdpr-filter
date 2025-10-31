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

# Preview changes before applying (dry-run)
composer lint:tool:rector -- --dry-run
composer lint:tool:psalm -- --alter --dry-run

# Check for hardcoded constant values
php check_for_constants.php           # Basic scan
php check_for_constants.php --verbose # Show line context
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
- **SonarQube**: Cloud-based code quality and security analysis (quality gate must pass)

**Issue Resolution Priority:**

1. **Fix the underlying issue** (preferred approach)
2. **Refactor code** to avoid the issue pattern
3. **Use safe automated fixes** via `composer lint:fix`
4. **Ask before suppressing** - Suppression should be used only as an absolute last resort and requires
   explicit discussion

**Tip:** Use `git stash` before running `composer lint:fix` to easily revert changes if needed.

### SonarQube-Specific Guidelines

SonarQube is a **static analysis tool** that analyzes code structure,
not runtime behavior. Unlike human reviewers, it does NOT understand:

- PHPUnit's `expectException()` mechanism
- Test intent or context
- Comments explaining why code is written a certain way

**Common SonarQube issues and their fixes:**

1. **S1848: Useless object instantiation**
   - **Issue**: `new ClassName()` in tests that expect exceptions
   - **Why it occurs**: SonarQube doesn't understand `expectException()` means the object creation is the test
   - **Fix**: Assign to variable and add assertion: `$obj = new ClassName(); $this->assertInstanceOf(...)`

2. **S4833: Replace require_once with use statement**
   - **Issue**: Direct file inclusion instead of autoloading
   - **Fix**: Use composer's autoloader and proper `use` statements

3. **S1172: Remove unused function parameter**
   - **Issue**: Callback parameters that aren't used in the function body
   - **Fix**: Remove unused parameters from function signature

4. **S112: Define dedicated exception instead of generic one**
   - **Issue**: Throwing `\RuntimeException` or `\Exception` directly
   - **Fix**: Use project-specific exceptions like `RuleExecutionException`, `MaskingOperationFailedException`

5. **S1192: Define constant instead of duplicating literal**
   - **Issue**: String/number literals repeated 3+ times
   - **Fix**: Add to `TestConstants` or `MaskConstants` and use the constant reference

6. **S1481: Remove unused local variable**
   - **Issue**: Variable assigned but never read
   - **Fix**: Remove assignment or use the variable

**IMPORTANT**: Comments and docblocks do NOT fix SonarQube issues. The code structure itself must be changed.

## Code Quality

### Constant Usage

To reduce code duplication and improve maintainability
(as required by SonarQube), the project uses centralized constants:

- **MaskConstants** (`src/MaskConstants.php`): Mask replacement values (e.g., `MASK_MASKED`, `MASK_REDACTED`)
- **TestConstants** (`tests/TestConstants.php`): Test data values, patterns, field paths, messages

**Always use constants instead of hardcoded strings** for values defined in these files.
Use the constant checker to identify hardcoded values:

```bash
# Scan for hardcoded constant values
php check_for_constants.php

# Show line context for each match
php check_for_constants.php --verbose
```

The checker intelligently scans all PHP files and reports where constant references should be used:

- **MaskConstants** checked in both `src/` and `tests/` directories
- **TestConstants** checked only in `tests/` directory (not enforced in production code)
- Filters out common false positives like array keys and internal identifiers
- Helps maintain SonarQube code quality standards

## Important Notes

- **Always run `composer lint:fix` before manual fixes**
- **Fix all linting issues** - suppression requires explicit approval
- **Use constants instead of hardcoded values** - run `php check_for_constants.php` to verify
- The library focuses on GDPR compliance - be careful when modifying masking logic
- Default patterns include Finnish SSN, US SSN, IBAN, credit cards, emails, phones, and IPs
- Audit logging feature can track when sensitive data was masked for compliance
- All static analysis tools are configured to work harmoniously without conflicts
