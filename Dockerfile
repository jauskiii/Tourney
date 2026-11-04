# Tourney testiympäristöön (tuotannossa Tourney ajetaan normaalisti Apachella / webhotellissa).
FROM php:8.3-apache
RUN docker-php-ext-install pdo_mysql && a2enmod rewrite
COPY . /var/www/html/
RUN rm -f /var/www/html/Dockerfile /var/www/html/.dockerignore \
 && chown -R www-data:www-data /var/www/html/uploads
