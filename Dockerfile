FROM composer:2.8 AS vendor

WORKDIR /app

COPY composer.json ./
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-scripts \
    --optimize-autoloader

COPY . .
RUN composer dump-autoload --optimize --no-dev

FROM dunglas/frankenphp:1-php8.4-alpine AS frontend

WORKDIR /app

RUN apk add --no-cache nodejs npm

COPY --from=vendor /app/vendor ./vendor
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY public ./public
COPY resources ./resources
COPY routes ./routes
COPY artisan composer.json components.json package.json tsconfig.json vite.config.ts .npmrc ./

RUN npm install
RUN npm run build

FROM dunglas/frankenphp:1-php8.4-alpine

WORKDIR /app

RUN install-php-extensions \
    bcmath \
    intl \
    opcache \
    pcntl \
    pdo_pgsql \
    zip

COPY . .
COPY --from=vendor /app/vendor /app/vendor
COPY --from=frontend /app/public/build /app/public/build
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/start-container.sh /usr/local/bin/start-container

RUN chmod +x /usr/local/bin/start-container \
    && mkdir -p storage/logs bootstrap/cache \
    && chown -R www-data:www-data /app

ENV APP_ENV=production
ENV APP_DEBUG=false
ENV LOG_CHANNEL=stack
ENV LOG_LEVEL=info
ENV APP_URL=https://dashboard.emaksprime.com.tr
ENV PORT=8080

EXPOSE 8080

ENTRYPOINT ["start-container"]
