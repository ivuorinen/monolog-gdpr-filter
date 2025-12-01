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
