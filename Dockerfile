# Use official PHP with Apache
FROM php:8.2-apache

# Enable Apache mod_rewrite (important for most PHP sites)
RUN a2enmod rewrite

# Install MySQL extensions (for DB support)
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Set working directory
WORKDIR /var/www/html

# Copy your project files into the container
COPY . /var/www/html/
WORKDIR /var/www/html/public

# Fix permissions (important for Linux servers)
RUN chown -R www-data:www-data /var/www/html

# Expose port 80 (Render will map this)
EXPOSE 80