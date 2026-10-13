FROM php:8.4-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libzip-dev libicu-dev \
    && docker-php-ext-install pdo_pgsql zip bcmath sockets pcntl intl \
    && rm -rf /var/lib/apt/lists/*

# Composer existe somente dentro do container
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_HOME=/tmp/composer

WORKDIR /var/www
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
