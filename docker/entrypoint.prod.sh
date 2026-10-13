#!/bin/sh
# Produção: prepara pastas (o volume de storage pode vir vazio) e gera os caches do Laravel
# com as variáveis de ambiente do container. Migrations rodam à parte (ver README).
set -e

mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs

php artisan config:cache
php artisan route:cache
php artisan event:cache

exec "$@"
