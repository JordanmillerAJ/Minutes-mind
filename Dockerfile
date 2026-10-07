FROM php:8.2-apache

# Copy application files
COPY . /var/www/html/

# Ensure file permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
