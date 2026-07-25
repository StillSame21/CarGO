# Dockerfile
FROM php:8.2-apache

# Install dependencies and required PHP extensions
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libwebp-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install gd mysqli pdo pdo_mysql zip \
    && a2enmod rewrite expires headers deflate

# Install Composer globally
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set server-level configurations
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Let car/ (and any future dir) opt into .htaccess overrides - php:8.2-apache
# inherits Debian's `AllowOverride None`, which would otherwise make the
# caching/compression rules in .htaccess silently match nothing.
RUN printf '<Directory /var/www/html>\n    AllowOverride All\n</Directory>\n' \
    > /etc/apache2/conf-available/cargo-override.conf \
    && a2enconf cargo-override

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
