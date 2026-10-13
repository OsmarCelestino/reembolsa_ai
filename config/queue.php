<?php

/*
| Filas. Em execução normal tudo passa pelo RabbitMQ (serviço "queue" do compose).
| "sync" processa na hora, sem fila: usado pelos testes (phpunit.xml) e pelo reembolso:demo.
*/

return [

    'default' => env('QUEUE_CONNECTION', 'rabbitmq'),

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        // vladimir-yuldashev/laravel-queue-rabbitmq. Uma nova tentativa com espera vai
        // para a fila "<fila>.delay.<ms>", que devolve a mensagem à fila principal no fim do prazo.
        'rabbitmq' => [
            'driver' => 'rabbitmq',
            'queue' => env('RABBITMQ_QUEUE', 'default'),
            'connection' => 'default',
            'hosts' => [
                [
                    'host' => env('RABBITMQ_HOST', '127.0.0.1'),
                    'port' => (int) env('RABBITMQ_PORT', 5672),
                    'user' => env('RABBITMQ_USER', 'guest'),
                    'password' => env('RABBITMQ_PASSWORD', 'guest'),
                    'vhost' => env('RABBITMQ_VHOST', '/'),
                ],
            ],
            'options' => [],
            'worker' => env('RABBITMQ_WORKER', 'default'),
            'after_commit' => false,
        ],

    ],

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    // Jobs que esgotam as tentativas ficam no banco (php artisan queue:failed).
    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
