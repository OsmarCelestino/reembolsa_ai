<?php

use App\Demo\DemoScenario;
use App\Eval\EvalDataset;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
| `php artisan eval:run` com a resposta da IA simulada: aqui só se testa a
| medição (comparação com o gabarito, agregados, código de saída).
*/

beforeEach(function () {
    Storage::fake('local');
    config(['llm.anthropic.api_key' => 'test-key']);
});

/** Resposta da IA que acerta o gabarito do caso. */
function perfectAnswer(DemoScenario $case): array
{
    $e = $case->expected;

    return [
        'model' => 'claude-haiku-5-5',
        'stop_reason' => 'tool_use',
        'content' => [['type' => 'tool_use', 'id' => 'tu', 'name' => 'register_receipt', 'input' => [
            'cnpj' => $e['cnpj'] ?? null,
            'issuer_name' => 'EMPRESA FICTICIA',
            'issue_date' => $e['issue_date'] ?? null,
            'total_amount' => isset($e['total_cents']) ? number_format($e['total_cents'] / 100, 2, '.', '') : null,
            'category' => $e['category'] ?? null,
            'confidence' => $e === [] ? 0.2 : 0.95,
            'suspicious_content' => $case->expectedSuspicious,
        ]]],
        'usage' => ['input_tokens' => 3000, 'output_tokens' => 200],
    ];
}

it('acerta todas as decisões quando a IA lê o gabarito', function () {
    $sequence = Http::fakeSequence('api.anthropic.com/*');
    foreach (EvalDataset::cases() as $case) {
        $sequence->push(perfectAnswer($case));
    }

    $this->artisan('eval:run')
        ->expectsOutputToContain('Decisões corretas')
        ->assertSuccessful();

    $files = Storage::disk('local')->files('eval');
    $report = json_decode(Storage::disk('local')->get($files[0]), true)['summary'];

    expect($files)->toHaveCount(1)
        ->and($report['decisions'])->toBe(['hits' => 12, 'total' => 12])
        ->and($report['wrong_auto_approvals'])->toBe(0)
        ->and($report['suspicious'])->toBe(['true_positives' => 3, 'false_positives' => 0, 'false_negatives' => 0])
        ->and($report['cost_usd']['per_receipt'])->toEqualWithDelta(0.0004, 0.00001);
});

it('falha e avisa quando as chamadas à IA falham', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'bad']], 400)]);

    $this->artisan('eval:run')
        ->expectsOutputToContain('chamadas falharam')
        ->assertFailed();
});

it('tem casos para os três resultados possíveis', function () {
    $statuses = collect(EvalDataset::cases())->map(fn ($c) => $c->expectedStatus->value)->unique()->sort()->values()->all();

    expect($statuses)->toBe(['auto_approved', 'pending_review', 'rejected']);
});

it('avisa quando a chave da API não está configurada', function () {
    config(['llm.anthropic.api_key' => null]);

    $this->artisan('eval:run')->assertFailed();
});
