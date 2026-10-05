FROM php:8.2-apache

# Copy all project files into Apache web root
COPY . /var/www/html/

# Expose port 80 for Render
EXPOSE 80
