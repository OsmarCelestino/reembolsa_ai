<?php

/*
| Regras de negócio do reembolso. Valores monetários em centavos (inteiros),
| para nunca usar float com dinheiro.
*/

return [

    // Limite por comprovante, por categoria.
    'category_limits_cents' => [
        'alimentacao' => (int) env('REIMBURSEMENT_LIMIT_ALIMENTACAO', 15_000),
        'transporte' => (int) env('REIMBURSEMENT_LIMIT_TRANSPORTE', 30_000),
        'hospedagem' => (int) env('REIMBURSEMENT_LIMIT_HOSPEDAGEM', 80_000),
        'material' => (int) env('REIMBURSEMENT_LIMIT_MATERIAL', 50_000),
    ],

    // Comprovantes mais antigos que isso são recusados.
    'receipt_max_age_days' => (int) env('REIMBURSEMENT_RECEIPT_MAX_AGE_DAYS', 90),

    // Acima deste valor, sempre passa por um analista.
    'auto_approve_max_cents' => (int) env('REIMBURSEMENT_AUTO_APPROVE_MAX', 20_000),

    // Confiança mínima da extração para aprovar sem humano.
    'min_confidence' => (float) env('REIMBURSEMENT_MIN_CONFIDENCE', 0.85),

    'upload' => [
        'max_kb' => 5_120,
        'mimetypes' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
        'disk' => 'local', // disco privado: comprovantes nunca ficam públicos
        'directory' => 'receipts',
    ],

    'submit_rate_per_minute' => 10,

    // LGPD: por quantos dias o arquivo do comprovante é guardado depois de criado o pedido.
    // Padrão de 5 anos (1825 dias), prazo usual de guarda de documentos fiscais; ajuste com o jurídico.
    'retention_days' => (int) env('REIMBURSEMENT_RETENTION_DAYS', 1825),
];
