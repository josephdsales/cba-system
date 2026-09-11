# CBA System on Render (PHP 8.2 + Apache, talks to Render PostgreSQL)
FROM php:8.2-apache

# PHP extensions: Postgres + MySQL drivers, zip (for .docx import)
RUN apt-get update && apt-get install -y libzip-dev libpq-dev \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*

# App lives at repo root (index.php beside this Dockerfile)
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

# Render injects $PORT — repoint Apache at it, then serve.
CMD sed -i "s/Listen 80/Listen $PORT/" /etc/apache2/ports.conf \
 && sed -i "s/:80>/:$PORT>/" /etc/apache2/sites-available/000-default.conf \
 && apache2-foreground
