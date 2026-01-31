# GitHub Copilot Instructions for monolog-gdpr-filter

## Project Overview

This project is a PHP library that provides a Monolog processor for GDPR compliance.

It allows masking, removing, or replacing sensitive data in logs using regex patterns,
field-level configuration, and custom callbacks. It is designed for easy integration with Monolog and Laravel.

## Coding Conventions

- **Language:** PHP 8.4+
  - **PHP Version:** Ensure compatibility with PHP 8.4 and above.
- **PSR Standards:** Follow PSR-12 for code style and autoloading.
- **Testing:** Use PHPUnit for all tests. Place tests in the `tests/` directory. Run `composer test` to execute tests.
  - All tests should be written in a way that they can run independently.
  - Use Attribute-based annotations for test methods (e.g., `#[Test]`).
  - Use Attribute-based annotations for Covers (e.g., `#[CoversClass(GdprProcessor::class)]`).
  - **PHPUnit Version:** Use PHPUnit 10.x or above.
  - **PHPUnit Configuration:** Use `phpunit.xml` for configuration.
  - **Code Coverage:** Use PHPUnit's code coverage features. Generate reports in the `build/` directory.
- **Type Declarations:** Use strict typing and type declarations for all functions and methods.
- **Namespaces:** Use appropriate namespaces for all classes
  (e.g., `Ivuorinen\MonologGdprFilter`, or `Tests\` for tests).
- **Error Handling:** Use exceptions for error handling.
- **Static Analysis:**
  - Use Psalm and PHPStan for static analysis.
  - Config files: `psalm.xml`, `phpstan.neon` (if present).
- **Linting:** Use PHP_CodeSniffer with `phpcs.xml` for code style checks. All code must pass linting before merging.
- **Composer:** Use Composer for dependency management. Follow PSR-4 autoloading.
  - Use `composer install` to install dependencies.
  - Use `composer update` to update dependencies.
- **Formatting:** Use 4 spaces for indentation. No trailing whitespace. Use Unix line endings.
  See `.editorconfig` and `phpcs.xml` for more details.
- **Version Control:**
  - Use Git for version control.
  - Follow semantic versioning (MAJOR.MINOR.PATCH).
  - Do not commit anything, user will do it themselves.
- **Documentation:**
  - Public classes and methods should have PHPDoc blocks.
  - Update `README.md` for usage and installation changes.

## Pull Request Guidelines

- Ensure your branch is up-to-date with `main`.
- Use descriptive branch names (e.g., `feature/add-gdpr-processor`, `fix/issue-42`).
- Include a clear description of changes in the PR.
- Link to any relevant issues (e.g., `fixes #42`).
- Ensure all tests pass (`vendor/bin/phpunit`).
- Run static analysis (`vendor/bin/psalm` and/or `vendor/bin/phpstan`).
- Run code style checks (`vendor/bin/phpcs`).
- Add or update tests for new features or bug fixes.
- Update documentation as needed.

## Directory Structure

- `src/` — Main library source code
- `tests/` — PHPUnit tests
- `build/` — Build artifacts and coverage reports
- `vendor/` — Composer dependencies

## Commit Message Guidelines

- Use clear, concise messages in semantic commits style
  (e.g., `fix: mask email addresses in context`, `feat(logger): add audit logger option`).
- Reference issues when relevant (e.g., `fix: #12 ...`).

## Security & Privacy

- Do not log or expose real sensitive data in tests or documentation.
- Ensure all masking/removal logic is covered by tests.

## Automation

- Use Composer scripts for automation if needed.
- CI will run tests, static analysis, and code style checks.

---
For questions, see the `README.md` or open an issue.
