<?php

return [

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'version' => '2023-06-01',
        'model' => env('LLM_MODEL') ?: 'claude-haiku-5-5',
        'max_tokens' => (int) env('LLM_MAX_TOKENS', 1024),
        'timeout' => (int) env('LLM_TIMEOUT', 60),
    ],

    // Só modelos desta lista podem ser usados (requisito: "apenas modelos autorizados").
    'allowed_models' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) (env('LLM_ALLOWED_MODELS') ?: 'claude-haiku-5-5,claude-sonnet-5-5')),
    ))),
];
