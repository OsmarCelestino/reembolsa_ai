# ---------- base: PHP + extensões, comum a dev e produção ----------
FROM php:8.4-fpm AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libzip-dev libicu-dev \
    && docker-php-ext-install pdo_pgsql zip bcmath sockets pcntl intl opcache \
    && rm -rf /var/lib/apt/lists/*

# Composer existe somente dentro do container
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_HOME=/tmp/composer

# Uploads de até 5 MB (regra de negócio) e sem expor a versão do PHP
COPY docker/php/app.ini /usr/local/etc/php/conf.d/app.ini

WORKDIR /var/www

# ---------- dev: código montado por volume, artisan serve ----------
FROM base AS dev

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN sed -i "s/\r$//" /usr/local/bin/entrypoint && chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

# ---------- vendor: dependências de produção (sem dev) ----------
FROM base AS vendor

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

# ---------- prod: PHP-FPM com código embutido, OPcache e usuário sem privilégios ----------
FROM base AS prod

ENV APP_ENV=production \
    APP_DEBUG=false

COPY docker/php/opcache-prod.ini /usr/local/etc/php/conf.d/opcache-prod.ini
COPY docker/entrypoint.prod.sh /usr/local/bin/entrypoint
RUN sed -i "s/\r$//" /usr/local/bin/entrypoint && chmod +x /usr/local/bin/entrypoint

COPY --from=vendor /var/www/vendor ./vendor
COPY . .

RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

ENTRYPOINT ["entrypoint"]
EXPOSE 9000
CMD ["php-fpm"]

# ---------- web: Nginx servindo public/ e repassando PHP para o FPM ----------
FROM nginx:1.27-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=prod /var/www/public /var/www/public
