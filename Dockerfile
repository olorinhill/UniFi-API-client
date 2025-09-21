FROM php:8.1-apache

# Install dependencies
RUN apt-get update && apt-get install -y \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache modules
RUN a2enmod rewrite

# Copy source code
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html/

# Expose port
EXPOSE 80