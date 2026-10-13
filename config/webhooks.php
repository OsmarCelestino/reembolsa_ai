<?php

/*
| Notificação de mudança de status para um sistema externo (ERP, financeiro, Slack...).
| Desligado enquanto WEBHOOK_URL estiver vazio. O payload não leva dados pessoais.
*/

return [
    'url' => env('WEBHOOK_URL'),
    'secret' => env('WEBHOOK_SECRET'),

    // Só https, a não ser em desenvolvimento local (ex.: http://host.docker.internal:9000).
    'allow_http' => (bool) env('WEBHOOK_ALLOW_HTTP', false),

    'timeout' => (int) env('WEBHOOK_TIMEOUT', 10),

    // Status que geram notificação (os intermediários, como processing, não).
    'statuses' => ['auto_approved', 'pending_review', 'approved', 'rejected', 'failed'],

    // Janela aceita pelo receptor para o timestamp assinado (proteção contra replay).
    'tolerance_seconds' => 300,
];
