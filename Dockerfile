FROM composer:2 AS build

WORKDIR /app

RUN composer create-project laravel/laravel . "^11.0" --no-interaction --prefer-dist

COPY . .

RUN composer require guzzlehttp/guzzle doctrine/dbal --no-interaction

FROM php:8.3-fpm-alpine

WORKDIR /var/www/html

RUN apk add --no-cache \
    nginx \
    supervisor \
    bash \
    curl \
    postgresql-dev \
    icu-dev \
    oniguruma-dev \
    libzip-dev \
    zip \
    unzip \
  && docker-php-ext-install \
    pdo \
    pdo_pgsql \
    intl \
    bcmath \
    opcache \
    zip

COPY --from=build /app /var/www/html

EXPOSE 8080

CMD php artisan serve --host=0.0.0.0 --port=8080
