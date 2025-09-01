# syntax=docker/dockerfile:1

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress

FROM php:8.2-fpm-alpine AS runtime
WORKDIR /var/www
RUN apk add --no-cache ca-certificates && update-ca-certificates
COPY --from=vendor /app/vendor ./vendor
COPY public ./public
COPY src ./src
COPY --from=vendor /usr/bin/composer /usr/bin/composer
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=0
CMD ["php-fpm","-F"]


