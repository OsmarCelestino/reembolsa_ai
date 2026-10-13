#!/bin/sh
# Prepara o projeto na primeira subida: o código vem do volume (sem vendor/),
# então instala as dependências e gera a APP_KEY antes de iniciar o serviço.
set -e

if [ "${WAIT_FOR_VENDOR:-0}" = "1" ]; then
    # Serviços auxiliares (fila) só esperam o container "app" instalar tudo.
    until [ -f vendor/autoload.php ]; do
        echo "Aguardando o composer install do serviço app..."
        sleep 3
    done
else
    if [ ! -f vendor/autoload.php ]; then
        composer install --no-interaction --prefer-dist
    fi

    if [ -f .env ] && ! grep -qE '^APP_KEY=.+' .env; then
        php artisan key:generate --force
    fi
fi

exec "$@"
