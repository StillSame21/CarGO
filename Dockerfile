# Dockerfile
FROM php:8.2-apache

# Install dependencies and required PHP extensions
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd mysqli pdo pdo_mysql zip \
    && a2enmod rewrite

# Install Composer globally
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set server-level configurations
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html

# Run composer installation (exclude dev tools for production build)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Ensure correct permissions on runtime directories
RUN chown -R www-data:www-data /var/www/html
RUN chmod -R 775 /var/www/html/car

EXPOSE 80
