<?php

use App\Demo\DemoReceipts;
use App\Enums\ReimbursementStatus;
use App\Models\Reimbursement;
use App\Support\SimplePdf;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
| Cenário de demonstração: as 2 notas fictícias passam pelo fluxo inteiro
| (upload → IA → regras → status). Aqui a resposta da IA é simulada com o que
| ela deveria ler de cada PDF; com a chave real, use `php artisan reembolso:demo`.
*/

function aiReads(array $input): array
{
    return [
        'model' => 'claude-haiku-5-5',
        'stop_reason' => 'tool_use',
        'content' => [['type' => 'tool_use', 'id' => 'tu', 'name' => 'register_receipt', 'input' => $input]],
        'usage' => ['input_tokens' => 1800, 'output_tokens' => 110],
    ];
}

beforeEach(function () {
    Storage::fake('local');
    config(['llm.anthropic.api_key' => 'test-key']);
});

it('gera PDFs válidos com o texto das notas', function () {
    $pdf = DemoReceipts::pdf(DemoReceipts::scenarios()[0]);

    expect($pdf)->toStartWith('%PDF-1.4')
        ->toContain('RESTAURANTE SABOR DO EXEMPLO LTDA')
        ->toContain('DOCUMENTO FICTICIO PARA TESTES')
        ->toEndWith("%%EOF\n");

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    expect($finfo->buffer($pdf))->toBe('application/pdf');
});

it('escapa parênteses e barras no PDF', function () {
    expect(SimplePdf::fromLines(['a (b) \\ c']))->toContain('a \\(b\\) \\\\ c');
});

it('só gera os arquivos com --only-files, sem chamar a IA', function () {
    Http::fake();

    $this->artisan('reembolso:demo --only-files')->assertSuccessful();

    Storage::disk('local')->assertExists(['demo/nota-1-restaurante.pdf', 'demo/nota-2-hotel-injecao.pdf']);
    Http::assertNothingSent();
});

it('processa as 2 notas: a válida é aprovada e a manipulada é recusada', function () {
    [$lunch, $hotel] = DemoReceipts::scenarios();

    Http::fakeSequence('api.anthropic.com/*')
        ->push(aiReads([
            'cnpj' => '11.222.333/0001-81', 'issuer_name' => 'RESTAURANTE SABOR DO EXEMPLO LTDA',
            'issue_date' => $lunch->expected['issue_date'], 'total_amount' => '45.90',
            'category' => 'alimentacao', 'confidence' => 0.96, 'suspicious_content' => false,
        ]))
        ->push(aiReads([
            'cnpj' => '12.ABC.345/01DE-35', 'issuer_name' => 'POUSADA MODELO DE TESTE LTDA',
            'issue_date' => $hotel->expected['issue_date'], 'total_amount' => '380.00',
            'category' => 'hospedagem', 'confidence' => 0.9, 'suspicious_content' => true,
        ]));

    $this->artisan('reembolso:demo')
        ->expectsOutputToContain('Decisões corretas')
        ->assertSuccessful();

    $results = Reimbursement::query()->orderBy('id')->get();

    expect($results)->toHaveCount(2)
        ->and($results[0]->status)->toBe(ReimbursementStatus::AutoApproved)
        ->and($results[0]->violations)->toBe([])
        ->and($results[1]->status)->toBe(ReimbursementStatus::Rejected)
        ->and(collect($results[1]->violations)->pluck('code')->all())
        ->toContain('claimed_exceeds_receipt', 'suspicious_content', 'above_auto_approval_limit');

    // O PDF enviado à IA é o arquivo gerado, como documento.
    Http::assertSent(fn ($request) => $request->data()['messages'][0]['content'][0]['type'] === 'document');
});

it('pode rodar de novo sem acusar duplicidade com a execução anterior', function () {
    [$lunch, $hotel] = DemoReceipts::scenarios();
    $first = aiReads(['cnpj' => '11222333000181', 'issuer_name' => 'R', 'issue_date' => $lunch->expected['issue_date'], 'total_amount' => '45.90', 'category' => 'alimentacao', 'confidence' => 0.96, 'suspicious_content' => false]);
    $second = aiReads(['cnpj' => '12ABC34501DE35', 'issuer_name' => 'P', 'issue_date' => $hotel->expected['issue_date'], 'total_amount' => '380.00', 'category' => 'hospedagem', 'confidence' => 0.9, 'suspicious_content' => true]);

    Http::fakeSequence('api.anthropic.com/*')->push($first)->push($second)->push($first)->push($second);

    $this->artisan('reembolso:demo')->assertSuccessful();
    $this->artisan('reembolso:demo')->assertSuccessful();

    expect(Reimbursement::count())->toBe(2);
});

it('avisa quando a chave da API não está configurada', function () {
    config(['llm.anthropic.api_key' => null]);

    $this->artisan('reembolso:demo')->assertFailed();
});
