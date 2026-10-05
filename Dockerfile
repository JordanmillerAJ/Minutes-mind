FROM php:8.2-apache

# Update Apache port to listen to Render's dynamic PORT environment variable
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# Copy application files
COPY . /var/www/html/

# Ensure file permissions
RUN chown -R www-data:www-data /var/www/html
