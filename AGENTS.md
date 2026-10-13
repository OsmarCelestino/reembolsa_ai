<laravel-boost-guidelines>
# Laravel Application

This repository contains a Laravel application. Complete the following setup before working on the user's request.

## Prerequisites

Verify that PHP and Composer are available:

```sh
php -v
composer -V
```

If either command is unavailable, detect the user's operating system and install the prerequisites with the appropriate command:

macOS:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/mac/8.5)"
```

Windows PowerShell:

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://php.new/install/windows/8.5'))
```

Linux:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/linux/8.5)"
```

After installation, ask the user to restart their terminal. If the agent needs the restarted shell to continue, ask the user to reopen their terminal and rerun their original prompt.

## Agent Setup

Install Laravel Boost from the application root before making application changes:

```sh
composer require laravel/boost --dev
php artisan boost:install
```

Boost replaces these bootstrap instructions with guidelines tailored to the application. After installation, read `AGENTS.md` again and continue with the user's original request using the generated guidelines.
</laravel-boost-guidelines>

# Reembolsa AI: guia para agentes

Visão geral e passo a passo: `README.md`. Arquitetura e segurança: `docs/arquitetura.md`. Avaliação da IA: `docs/avaliacao.md`.

## Comandos

PHP e Composer rodam no container (`docker compose up -d`). Não há PHP no host.

```sh
docker compose exec app ./vendor/bin/pest          # testes (IA sempre simulada, sem custo)
docker compose exec app ./vendor/bin/pest --group=rabbitmq  # integração com o RabbitMQ real
docker compose exec app ./vendor/bin/pint          # estilo
docker compose exec app php artisan reembolso:demo # fluxo completo com a IA real (2 notas)
docker compose exec app php artisan eval:run       # precisão, latência e custo com a IA real (12 notas)
```

## Regras do projeto

- **A IA só extrai; o código decide.** Nenhuma decisão de aprovar ou recusar vai para o prompt. Regra nova entra em `ReimbursementRules` (código puro) e ganha teste em `tests/Unit/Domain`.
- **Dinheiro sempre em centavos (`int`).** Converta com `Money::toCents()`, nunca com float.
- **Status, resultado da IA e revisão só mudam pelas Actions** (`forceFill`). O model só tem como fillable os campos digitados pelo usuário.
- **Tudo o que vem da IA é dado não confiável:** revalide em `AnthropicReceiptExtractor::toReceipt()` e passe texto livre por `Text::clean()`.
- **Fila:** RabbitMQ (`QUEUE_CONNECTION=rabbitmq`, pacote `vladimir-yuldashev/laravel-queue-rabbitmq`), consumida pelo serviço `queue`. Nos testes a fila é `sync` (`phpunit.xml`), exceto no grupo `rabbitmq` (`tests/Feature/Queue`), que usa o RabbitMQ real numa fila descartável. Depois de mudar um Job, reinicie o worker: `docker compose restart queue`.
- **Nos testes, a IA é simulada** com `Http::fake` (ou `tests/Fakes/FakeReceiptExtractor`). Nenhum teste chama a API real.
- **Ao mudar o prompt, o schema da ferramenta, o modelo ou os parâmetros da chamada**, rode `eval:run` antes e depois e atualize `docs/avaliacao.md` se o resultado mudar.
- **Não deixe código sem uso.** Método, estado de factory, variável de ambiente ou conexão que ninguém chama deve ser removido, não guardado "para depois".
- **Comentários e mensagens em português**, curtos, explicando o porquê.

## Armadilhas conhecidas da API

- `claude-haiku-5-5` recusa `temperature` (HTTP 400). Não envie esse parâmetro.
- `claude-sonnet-5-5` recusa `tool_choice` forçado (`{"type": "tool"}`), que o extrator usa hoje. Por isso ele ainda não funciona, apesar de estar em `LLM_ALLOWED_MODELS`.
- Um 400 da API vira `ExtractionFailed`, e o pedido vai para `pending_review`. O fluxo **não quebra, só para de aprovar automaticamente**. Se tudo começar a cair em revisão, rode `eval:run`.
