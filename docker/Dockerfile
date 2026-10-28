ARG PHP_VERSION=8.4

FROM composer:2 AS composer

FROM php:${PHP_VERSION}-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && echo "memory_limit=1G" > /usr/local/etc/php/conf.d/memory-limit.ini

COPY --from=composer /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_CACHE_DIR=/tmp/composer-cache

WORKDIR /app
