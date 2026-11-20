# Stage 1: assets (Vite + Tailwind, only the Laravel welcome page uses them)
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm install --include=dev --no-audit --no-fund
COPY . .
RUN npm run build

# Stage 2: Laravel (PHP + Nginx)
FROM serversideup/php:8.3-fpm-nginx

ENV PHP_OPCACHE_ENABLE=1
# On every start: migrate, storage:link, cache config/routes/views
ENV AUTORUN_ENABLED=true

USER root
# gd: PDF/images, intl: number and date formats
RUN install-php-extensions intl gd
USER www-data

COPY --chown=www-data:www-data . /var/www/html

RUN composer install --no-dev --optimize-autoloader --no-interaction

COPY --from=assets --chown=www-data:www-data /app/public/build /var/www/html/public/build
