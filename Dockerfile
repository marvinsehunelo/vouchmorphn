# Use PHP CLI so we can run the built-in server
FROM php:8.2-cli

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

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader || true

# Copy application code
COPY src/ src/
COPY public/ public/

# Expose port (Railway will override $PORT anyway)
EXPOSE 9000

# Start PHP built-in server
CMD php -S 0.0.0.0:${PORT} -t public/
