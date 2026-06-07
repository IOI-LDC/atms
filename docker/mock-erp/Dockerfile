FROM composer:2 AS composer-stage
WORKDIR /app
COPY mock-erp/composer.json mock-erp/composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-autoloader
COPY mock-erp/ .
RUN composer dump-autoload --optimize

FROM php:8.4-fpm-alpine AS production
RUN apk add --no-cache nginx
COPY --from=composer-stage /app /var/www/html
RUN chown -R www-data:www-data /var/www/html
COPY docker/mock-erp/nginx.conf /etc/nginx/http.d/default.conf

EXPOSE 80
CMD ["sh", "-c", "php artisan migrate --force && php artisan db:seed --force && php-fpm -D && nginx -g 'daemon off;'"]
