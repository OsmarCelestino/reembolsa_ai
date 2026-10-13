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

    // Preço em US$ por milhão de tokens (entrada, saída), usado só para estimar custo no eval:run.
    // Tabela pública da Anthropic em out/2026; confira antes de usar como número oficial.
    'pricing_per_mtok' => [
        'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50],
        'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00],
    ],

    // Modelos que recusam tool_choice forçado ("tool"/"any" → HTTP 400): usam "auto" + ferramenta strict.
    // Fonte: documentação de tool use da Anthropic (out/2026).
    'auto_tool_choice_models' => ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-fable-5-1'],

    // Só modelos desta lista podem ser usados (requisito: "apenas modelos autorizados").
    'allowed_models' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) (env('LLM_ALLOWED_MODELS') ?: 'claude-haiku-5-5,claude-sonnet-5-5')),
    ))),
];
