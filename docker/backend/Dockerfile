# ===========================================================================
# Shared multi-stage Dockerfile for the ATMS backend.
#
# One build produces two final images from named stages:
#
#   app  → PHP-FPM container, used by the `api`, `queue`, and `scheduler`
#          services. Owns the full Laravel source tree.
#   web  → nginx container. Copies ONLY Laravel's public/ out of the app
#          stage and serves it directly. No `volumes_from` dependency on the
#          api container — public/ is baked into this image at build time.
#
# Why multi-stage instead of `COPY --from=<sibling-service-image>`:
#   - No reliance on Compose auto-generated image names.
#   - No cross-service build-order hazard: Docker resolves `COPY --from=app`
#     within the same build, deterministically.
# ===========================================================================

# ---- Stage 1: Composer dependencies ----------------------------------------
FROM composer:2 AS composer-stage
WORKDIR /app
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-autoloader
COPY backend/ .
RUN composer dump-autoload --optimize --no-scripts

# ---- Stage 2: PHP-FPM application (api / queue / scheduler) ----------------
FROM php:8.4-fpm-alpine AS app
RUN apk add --no-cache postgresql-dev shadow \
    && docker-php-ext-install pdo_pgsql pgsql
COPY --from=composer-stage /app /var/www/html
RUN chown -R www-data:www-data /var/www/html \
    && mkdir -p /var/www/html/storage/app/attachments \
    && chown -R www-data:www-data /var/www/html/storage
EXPOSE 9000
CMD ["php-fpm"]

# ---- Stage 3: nginx front end (Laravel public/ baked in) -------------------
# Serves public/ static assets (favicon, index.php fallback) directly. PHP
# execution is reverse-proxied to the `api` container's FastCGI port. The
# attachments volume is NOT mounted here — attachment files are streamed
# through Laravel (PHP-FPM owns the attachments volume), never served
# statically by nginx.
FROM nginx:alpine AS web
COPY --from=app /var/www/html/public /var/www/html/public
COPY docker/backend/nginx.conf /etc/nginx/conf.d/default.conf
EXPOSE 80
