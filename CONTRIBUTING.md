# Contributing to Monolog GDPR Filter

Thank you for your interest in contributing to Monolog GDPR Filter! This document provides guidelines and information about contributing to this project.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [Getting Started](#getting-started)
- [Development Setup](#development-setup)
- [Making Changes](#making-changes)
- [Testing](#testing)
- [Code Quality](#code-quality)
- [Submitting Changes](#submitting-changes)
- [Adding New GDPR Patterns](#adding-new-gdpr-patterns)
- [Security Issues](#security-issues)

## Code of Conduct

This project adheres to a code of conduct that promotes a welcoming and inclusive environment. Please be respectful in all interactions.

## Getting Started

### Prerequisites

- PHP 8.2 or higher
- Composer
- Git

### Development Setup

1. **Fork and clone the repository:**
   ```bash
   git clone https://github.com/yourusername/monolog-gdpr-filter.git
   cd monolog-gdpr-filter
   ```

2. **Install dependencies:**
   ```bash
   composer install
   ```

3. **Verify the setup:**
   ```bash
   composer test
   composer lint
   ```

## Making Changes

### Branch Structure

- `main` - Stable releases
- `develop` - Development branch for new features
- Feature branches: `feature/description`
- Bug fixes: `bugfix/description`
- Security fixes: `security/description`

### Workflow

1. **Create a feature branch:**
   ```bash
   git checkout -b feature/your-feature-name
   ```

2. **Make your changes** following our coding standards

3. **Test your changes:**
   ```bash
   composer test
   composer lint
   ```

4. **Commit your changes:**
   ```bash
   git commit -m "feat: add new GDPR pattern for vehicle registration"
   ```

## Testing

### Running Tests

```bash
# Run all tests
composer test

# Run tests with coverage (requires Xdebug)
composer test:coverage

# Run specific test file
./vendor/bin/phpunit tests/GdprProcessorTest.php

# Run specific test method
./vendor/bin/phpunit --filter testMethodName
```

### Writing Tests

- Write tests for all new functionality
- Follow existing test patterns in the `tests/` directory
- Use descriptive test method names
- Include both positive and negative test cases
- Test edge cases and error conditions

### Test Structure

```php
public function testNewGdprPattern(): void
{
    $processor = new GdprProcessor([
        '/your-pattern/' => '***MASKED***',
    ]);
    
    $result = $processor->regExpMessage('sensitive data');
    
    $this->assertSame('***MASKED***', $result);
}
```

## Code Quality

### Coding Standards

This project follows:
- **PSR-12** coding standard
- **PHPStan level max** for static analysis
- **Psalm** for additional type checking

### Quality Tools

```bash
# Run all linting tools
composer lint

# Auto-fix code style issues
composer lint:fix

# Individual tools
composer lint:tool:phpcs     # PHP_CodeSniffer
composer lint:tool:phpcbf    # PHP Code Beautifier and Fixer
composer lint:tool:psalm     # Static analysis
composer lint:tool:phpstan   # Static analysis (max level)
composer lint:tool:rector    # Code refactoring
```

### Code Style Guidelines

- Use strict types: `declare(strict_types=1);`
- Use proper type hints for all parameters and return types
- Document all public methods with PHPDoc
- Use meaningful variable and method names
- Keep methods focused and concise
- Avoid deep nesting (max 3 levels)

## Submitting Changes

### Pull Request Process

1. **Ensure all checks pass:**
   - All tests pass
   - All linting checks pass
   - No merge conflicts

2. **Write a clear PR description:**
   - What changes were made
   - Why the changes were necessary
   - Any breaking changes
   - Link to related issues

3. **PR Title Format:**
   - `feat: add new feature`
   - `fix: resolve bug in pattern matching`
   - `docs: update README examples`
   - `refactor: improve code structure`
   - `test: add missing test coverage`

### Commit Message Guidelines

Follow [Conventional Commits](https://conventionalcommits.org/):

```
type(scope): description

[optional body]

[optional footer(s)]
```

Types:
- `feat`: New features
- `fix`: Bug fixes
- `docs`: Documentation changes
- `style`: Code style changes
- `refactor`: Code refactoring
- `test`: Adding tests
- `chore`: Maintenance tasks

## Adding New GDPR Patterns

### Pattern Guidelines

When adding new GDPR patterns to the `getDefaultPatterns()` method:

1. **Be Specific**: Patterns should be specific enough to avoid false positives
2. **Security First**: Validate patterns using the built-in `isValidRegexPattern()` method
3. **Documentation**: Include clear comments explaining what the pattern matches
4. **Testing**: Add comprehensive tests for the new pattern

### Pattern Structure

```php
// Pattern comment explaining what it matches
'/your-regex-pattern/' => '***MASKED_TYPE***',
```

### Pattern Testing

```php
public function testNewPattern(): void
{
    $patterns = GdprProcessor::getDefaultPatterns();
    $processor = new GdprProcessor($patterns);
    
    // Test positive case
    $result = $processor->regExpMessage('sensitive-data-123');
    $this->assertSame('***MASKED_TYPE***', $result);
    
    // Test negative case (should not match)
    $result = $processor->regExpMessage('normal-data');
    $this->assertSame('normal-data', $result);
}
```

### Pattern Validation

Before submitting, validate your pattern:

```php
// Test pattern safety
GdprProcessor::validatePatterns([
    '/your-pattern/' => '***TEST***'
]);

// Test ReDoS resistance
$processor = new GdprProcessor(['/your-pattern/' => '***TEST***']);
$result = $processor->regExpMessage('very-long-string-to-test-performance');
```

## Security Issues

If you discover a security vulnerability, please refer to our [Security Policy](SECURITY.md) for responsible disclosure procedures.

## Questions and Support

- **Issues**: Use GitHub Issues for bug reports and feature requests
- **Discussions**: Use GitHub Discussions for questions and general discussion
- **Documentation**: Check README.md and code comments first

## Recognition

Contributors are recognized in:
- Git commit history
- Release notes for significant contributions
- Special thanks for security fixes

Thank you for contributing to Monolog GDPR Filter! 🎉