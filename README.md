# Reembolsa AI

Backoffice em Laravel que recebe pedidos de reembolso com comprovante, usa IA para extrair e validar os dados e envia o pedido para aprovação.

## Stack

- PHP 8.4 / Laravel 13
- PostgreSQL + pgvector (RAG)
- RabbitMQ (processamento assíncrono)
- Docker Compose
- Pest (testes)

## Como rodar

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

A aplicação fica em http://localhost:8000 e o painel do RabbitMQ em http://localhost:15672 (guest/guest).
