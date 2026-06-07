FROM composer:2 AS composer-stage
WORKDIR /app
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-autoloader
COPY backend/ .
RUN composer dump-autoload --optimize

FROM php:8.4-fpm-alpine AS production
RUN apk add --no-cache nginx shadow postgresql-dev \
    && docker-php-ext-install pdo_pgsql pgsql
COPY --from=composer-stage /app /var/www/html
RUN chown -R www-data:www-data /var/www/html
COPY docker/backend/nginx.conf /etc/nginx/http.d/default.conf
RUN mkdir -p /var/www/html/storage/app/attachments
RUN chown -R www-data:www-data /var/www/html/storage

EXPOSE 80
CMD ["sh", "-c", "php-fpm -D && nginx -g 'daemon off;'"]
