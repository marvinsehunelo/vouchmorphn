# Base image
FROM php:8.2-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libpq-dev \
    unzip \
    git \
    curl \
    libzip-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql zip

# Set working directory
WORKDIR /var/www/html

# Copy composer.json only (lock file optional)
COPY composer.json ./

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Install PHP dependencies (will generate composer.lock if not exists)
RUN composer install --no-dev --optimize-autoloader || true

# Copy application code
COPY src/ src/
COPY public/ public/

# Expose port
EXPOSE 9000

# Start PHP-FPM
CMD ["php-fpm"]

