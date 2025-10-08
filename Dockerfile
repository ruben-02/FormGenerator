# Use the official PHP image with Apache
FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y libsqlite3-dev libcurl4-openssl-dev

# Enable required PHP extensions
RUN docker-php-ext-install pdo pdo_sqlite curl

# Set the working directory
WORKDIR /var/www/html

# Copy the project files
COPY . .

# Ensure uploads directory exists and set permissions
RUN mkdir -p uploads && chmod 755 uploads

# Set permissions for database and directory to be writable
RUN chmod 755 /var/www/html && chmod 666 users.db

# Expose port 80
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]
