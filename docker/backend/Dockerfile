FROM composer:2 AS composer-stage
WORKDIR /app
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-autoloader
COPY backend/ .
RUN composer dump-autoload --optimize --no-scripts

FROM php:8.4-fpm-alpine AS production
RUN apk add --no-cache postgresql-dev shadow \
    && docker-php-ext-install pdo_pgsql pgsql
COPY --from=composer-stage /app /var/www/html
RUN chown -R www-data:www-data /var/www/html
RUN mkdir -p /var/www/html/storage/app/attachments
RUN chown -R www-data:www-data /var/www/html/storage

EXPOSE 9000
CMD ["php-fpm"]
