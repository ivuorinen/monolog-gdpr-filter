# Docker Development Environment

This guide explains how to set up a Docker development environment for working with the Monolog GDPR Filter library.

## Quick Start

### Using Docker Compose

```bash
# Clone the repository
git clone https://github.com/ivuorinen/monolog-gdpr-filter.git
cd monolog-gdpr-filter

# Start the development environment
docker compose up -d

# Run tests
docker compose exec php composer test

# Run linting
docker compose exec php composer lint
```

## Docker Configuration Files

### docker/Dockerfile

```dockerfile
FROM php:8.2-cli-alpine

# Install system dependencies
RUN apk add --no-cache \
    git \
    unzip \
    curl \
    libzip-dev \
    icu-dev \
    && docker-php-ext-install \
    zip \
    intl \
    pcntl

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install Xdebug for code coverage
RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug

# Configure Xdebug
RUN echo "xdebug.mode=coverage,debug" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.client_host=host.docker.internal" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

# Set working directory
WORKDIR /app

# Set recommended PHP settings for development
RUN echo "memory_limit=512M" >> /usr/local/etc/php/conf.d/docker-php-memory.ini \
    && echo "error_reporting=E_ALL" >> /usr/local/etc/php/conf.d/docker-php-errors.ini \
    && echo "display_errors=On" >> /usr/local/etc/php/conf.d/docker-php-errors.ini

# Create non-root user
RUN addgroup -g 1000 developer \
    && adduser -D -u 1000 -G developer developer

USER developer

CMD ["php", "-v"]
```

### docker/docker-compose.yml

```yaml
version: '3.8'

services:
  php:
    build:
      context: .
      dockerfile: Dockerfile
    volumes:
      - ..:/app
      - composer-cache:/home/developer/.composer/cache
    working_dir: /app
    environment:
      - COMPOSER_HOME=/home/developer/.composer
      - XDEBUG_MODE=coverage
    stdin_open: true
    tty: true
    command: tail -f /dev/null

  # Optional: PHP 8.3 for testing compatibility
  php83:
    image: php:8.3-cli-alpine
    volumes:
      - ..:/app
    working_dir: /app
    profiles:
      - testing
    command: php -v

volumes:
  composer-cache:
```

## Running Tests

### All Tests

```bash
docker compose exec php composer test
```

### With Coverage Report

```bash
docker compose exec php composer test:coverage
```

### Specific Test File

```bash
docker compose exec php ./vendor/bin/phpunit tests/GdprProcessorTest.php
```

### Specific Test Method

```bash
docker compose exec php ./vendor/bin/phpunit --filter testEmailMasking
```

## Running Linting Tools

### All Linting

```bash
docker compose exec php composer lint
```

### Individual Tools

```bash
# PHP CodeSniffer
docker compose exec php ./vendor/bin/phpcs

# Auto-fix with PHPCBF
docker compose exec php ./vendor/bin/phpcbf

# Psalm
docker compose exec php ./vendor/bin/psalm

# PHPStan
docker compose exec php ./vendor/bin/phpstan analyse

# Rector (dry-run)
docker compose exec php ./vendor/bin/rector --dry-run
```

## Development Workflow

### Initial Setup

```bash
# Build containers
docker compose build

# Start services
docker compose up -d

# Install dependencies
docker compose exec php composer install

# Run initial checks
docker compose exec php composer lint
docker compose exec php composer test
```

### Daily Development

```bash
# Start environment
docker compose up -d

# Make changes...

# Run tests
docker compose exec php composer test

# Run linting
docker compose exec php composer lint

# Auto-fix issues
docker compose exec php composer lint:fix
```

### Testing Multiple PHP Versions

```bash
# Test with PHP 8.3
docker compose --profile testing run php83 php -v
docker compose --profile testing run php83 ./vendor/bin/phpunit
```

## Debugging

### Enable Xdebug

The Docker configuration includes Xdebug. Configure your IDE to listen on port 9003.

For VS Code, add to `.vscode/launch.json`:

```json
{
    "version": "0.2.0",
    "configurations": [
        {
            "name": "Listen for Xdebug",
            "type": "php",
            "request": "launch",
            "port": 9003,
            "pathMappings": {
                "/app": "${workspaceFolder}"
            }
        }
    ]
}
```

### Interactive Shell

```bash
docker compose exec php sh
```

### View Logs

```bash
docker compose logs -f php
```

## CI/CD Integration

### GitHub Actions Example

```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: ['8.2', '8.3']

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: intl, zip
          coverage: xdebug

      - name: Install dependencies
        run: composer install --prefer-dist --no-progress

      - name: Run linting
        run: composer lint

      - name: Run tests
        run: composer test:coverage
```

## Troubleshooting

### Permission Issues

If you encounter permission issues:

```bash
# Fix ownership
docker compose exec -u root php chown -R developer:developer /app

# Or run as root temporarily
docker compose exec -u root php composer install
```

### Composer Memory Limit

```bash
docker compose exec php php -d memory_limit=-1 /usr/bin/composer install
```

### Clear Caches

```bash
# Clear composer cache
docker compose exec php composer clear-cache

# Clear Psalm cache
docker compose exec php ./vendor/bin/psalm --clear-cache

# Clear PHPStan cache
docker compose exec php ./vendor/bin/phpstan clear-result-cache
```

## See Also

- [Symfony Integration](symfony-integration.md)
- [PSR-3 Decorator](psr3-decorator.md)
- [Framework Examples](framework-examples.md)
