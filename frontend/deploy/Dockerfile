# Multi-stage образ Laravel-админки NEXUS (php-fpm + nginx, порт 8081)
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm install
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader
COPY . .
RUN composer dump-autoload --optimize

FROM php:8.3-fpm AS runtime
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx supervisor libpq-dev libicu-dev libonig-dev \
    && docker-php-ext-install pdo_pgsql pgsql intl opcache bcmath \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build
COPY deploy/nginx.conf /etc/nginx/sites-available/nexus-admin.conf
COPY deploy/supervisord.conf /etc/supervisor/conf.d/nexus-admin.conf
RUN rm -f /etc/nginx/sites-enabled/default \
    && ln -s /etc/nginx/sites-available/nexus-admin.conf /etc/nginx/sites-enabled/nexus-admin.conf \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R ug+rwX /var/www/html/storage /var/www/html/bootstrap/cache
EXPOSE 8081
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/nexus-admin.conf"]
