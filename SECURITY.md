# Security Policy

## Table of Contents

- [Supported Versions](#supported-versions)
- [Security Features](#security-features)
- [Reporting Security Vulnerabilities](#reporting-security-vulnerabilities)
- [Security Best Practices](#security-best-practices)
- [Known Security Considerations](#known-security-considerations)
- [Security Measures Implemented](#security-measures-implemented)

## Supported Versions

We actively support the following versions with security updates:

| Version | Supported          | PHP Requirements |
| ------- | ------------------ | ---------------- |
| 2.x     | ✅ Active support  | PHP 8.2+         |
| 1.x     | ⚠️ Security fixes only | PHP 8.2+     |

## Security Features

This library includes several built-in security features:

### 🛡️ Regex Injection Protection
- All regex patterns are validated before use
- Input sanitization prevents malicious pattern injection
- Built-in pattern validation using `isValidRegexPattern()`

### 🛡️ ReDoS (Regular Expression Denial of Service) Protection
- Automatic detection of dangerous regex patterns
- Protection against nested quantifiers and excessive backtracking
- Safe pattern compilation with error handling

### 🛡️ Secure Error Handling
- No error suppression (`@`) operators used
- Proper exception handling for all regex operations
- Comprehensive error logging for security monitoring

### 🛡️ Audit Trail Security
- Secure audit logging with configurable callbacks
- Protection against sensitive data exposure in audit logs
- Validation of audit logger parameters

## Reporting Security Vulnerabilities

If you discover a security vulnerability, please follow these steps:

### 🚨 **DO NOT** create a public GitHub issue for security vulnerabilities

### ✅ **DO** report privately using one of these methods:

1. **GitHub Security Advisories** (Preferred):
   - Go to the [Security tab](https://github.com/ivuorinen/monolog-gdpr-filter/security)
   - Click "Report a vulnerability"
   - Provide detailed information about the vulnerability

2. **Direct Email**:
   - Send to: [security@ivuorinen.com](mailto:security@ivuorinen.com)
   - Use subject: "SECURITY: Monolog GDPR Filter Vulnerability"
   - Include GPG encrypted message if possible

### 📝 What to Include in Your Report

Please provide as much information as possible:

- **Description**: Clear description of the vulnerability
- **Impact**: Potential impact and attack scenarios
- **Reproduction**: Step-by-step reproduction instructions
- **Environment**: PHP version, library version, OS details
- **Proof of Concept**: Code example demonstrating the issue
- **Suggested Fix**: If you have ideas for remediation

### 🕒 Response Timeline

- **Initial Response**: Within 48 hours
- **Vulnerability Assessment**: Within 1 week
- **Fix Development**: Depends on severity (1-4 weeks)
- **Release**: Security fixes are prioritized
- **Public Disclosure**: After fix is released and users have time to update

## Security Best Practices

### For Users of This Library

#### ✅ Pattern Validation
Always validate custom patterns before use:

```php
// Good: Validate custom patterns
try {
    GdprProcessor::validatePatterns([
        '/your-custom-pattern/' => '***MASKED***'
    ]);
    $processor = new GdprProcessor($patterns);
} catch (InvalidArgumentException $e) {
    // Handle invalid pattern
}
```

#### ✅ Secure Audit Logging
Be careful with audit logger implementation:

```php
// Good: Secure audit logger
$auditLogger = function (string $path, mixed $original, mixed $masked): void {
    // DON'T log the original sensitive data
    error_log("GDPR: Masked field '{$path}' - type: " . gettype($original));
};

// Bad: Insecure audit logger
$auditLogger = function (string $path, mixed $original, mixed $masked): void {
    // NEVER do this - logs sensitive data!
    error_log("GDPR: {$path} changed from {$original} to {$masked}");
};
```

#### ✅ Input Validation
Validate input when using custom callbacks:

```php
// Good: Validate callback input
$customCallback = function (mixed $value): string {
    if (!is_string($value)) {
        return '***INVALID***';
    }
    
    // Additional validation
    if (strlen($value) > 1000) {
        return '***TOOLONG***';
    }
    
    return preg_replace('/sensitive/', '***MASKED***', $value) ?? '***ERROR***';
};
```

#### ✅ Regular Updates
- Keep the library updated to get security fixes
- Monitor security advisories
- Review changelogs for security-related changes

### For Contributors

#### 🔒 Secure Development Practices

1. **Never commit sensitive data**:
   - No real credentials, tokens, or personal data in tests
   - Use placeholder data only
   - Review diffs before committing

2. **Validate all regex patterns**:
   ```php
   // Always test new patterns for security
   if (!$this->isValidRegexPattern($pattern)) {
       throw new InvalidArgumentException('Invalid pattern');
   }
   ```

3. **Use proper error handling**:
   ```php
   // Good
   try {
       $result = preg_replace($pattern, $replacement, $input);
   } catch (\Error $e) {
       // Handle error
   }
   
   // Bad
   $result = @preg_replace($pattern, $replacement, $input);
   ```

## Known Security Considerations

### ⚠️ Performance Considerations
- Complex regex patterns may cause performance issues
- Large input strings should be validated for reasonable size
- Consider implementing timeouts for regex operations

### ⚠️ Pattern Conflicts
- Multiple patterns may interact unexpectedly
- Pattern order matters for security
- Test all patterns together, not just individually

### ⚠️ Audit Logging
- Audit loggers can inadvertently log sensitive data
- Implement audit loggers carefully
- Consider what data is actually needed for compliance

## Security Measures Implemented

### 🔒 Code-Level Security

1. **Input Validation**:
   - All regex patterns validated before compilation
   - ReDoS pattern detection and prevention
   - Type safety enforcement with strict typing

2. **Error Handling**:
   - No error suppression operators used
   - Comprehensive exception handling
   - Secure failure modes

3. **Memory Safety**:
   - Proper resource cleanup
   - Prevention of memory exhaustion attacks
   - Bounded regex operations

### 🔒 Development Security

1. **Static Analysis**:
   - PHPStan at maximum level
   - Psalm static analysis
   - Security-focused linting rules

2. **Automated Testing**:
   - Comprehensive test suite
   - Security-specific test cases
   - Continuous integration with security checks

3. **Dependency Management**:
   - Regular dependency updates via Dependabot
   - Security vulnerability scanning
   - Minimal dependency footprint

### 🔒 Release Security

1. **Secure Release Process**:
   - Automated builds and testing
   - Signed releases
   - Security review before major releases

2. **Version Management**:
   - Semantic versioning for security transparency
   - Clear documentation of security changes
   - Migration guides for security updates

## Contact

For security-related questions or concerns:

- **Security Issues**: Use GitHub Security Advisories or email security@ivuorinen.com
- **General Questions**: Create a GitHub Discussion
- **Documentation**: Refer to README.md and inline code documentation

## Acknowledgments

We appreciate responsible disclosure from security researchers and the community.
Contributors who report valid security vulnerabilities will be acknowledged
in release notes (unless they prefer to remain anonymous).

---

**Last Updated**: 2025-07-29
