<?php

use App\Actions\Reimbursements\ProcessReimbursement;
use App\Ai\Contracts\ReceiptExtractor;
use App\Ai\Exceptions\AiProviderUnavailable;
use App\Ai\Exceptions\ExtractionFailed;
use App\Enums\ReimbursementStatus;
use App\Jobs\ProcessReimbursementJob;
use App\Models\Reimbursement;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeReceiptExtractor;

beforeEach(function () {
    Storage::fake('local');
});

function useExtractor(FakeReceiptExtractor $fake): FakeReceiptExtractor
{
    app()->instance(ReceiptExtractor::class, $fake);

    return $fake;
}

function submitted(array $attrs = []): Reimbursement
{
    $r = Reimbursement::factory()->create($attrs);
    Storage::disk('local')->put($r->receipt_path, '%PDF-1.4 fake');

    return $r;
}

it('aprova automaticamente e guarda os dados e métricas da IA', function () {
    useExtractor(new FakeReceiptExtractor(receipt()));
    $r = submitted();

    app(ProcessReimbursement::class)->handle($r->id);

    $r->refresh();
    expect($r->status)->toBe(ReimbursementStatus::AutoApproved)
        ->and($r->extracted['cnpj'])->toBe('11222333000181')
        ->and($r->receipt_fingerprint)->not->toBeNull()
        ->and($r->ai_model)->toBe('fake-model')
        ->and($r->ai_input_tokens)->toBe(1200)
        ->and($r->processed_at)->not->toBeNull();
});

it('recusa o mesmo comprovante em outro arquivo (mesmo CNPJ, data e valor)', function () {
    useExtractor(new FakeReceiptExtractor(receipt()));
    $first = submitted();
    app(ProcessReimbursement::class)->handle($first->id);

    $second = submitted();
    app(ProcessReimbursement::class)->handle($second->id);

    expect($second->refresh()->status)->toBe(ReimbursementStatus::Rejected)
        ->and(collect($second->violations)->pluck('code'))->toContain('duplicate_receipt');
});

it('manda para revisão quando a IA devolve algo inválido', function () {
    useExtractor(new FakeReceiptExtractor(throw: new ExtractionFailed('schema')));
    $r = submitted();

    app(ProcessReimbursement::class)->handle($r->id);

    expect($r->refresh()->status)->toBe(ReimbursementStatus::PendingReview)
        ->and($r->violations[0]['code'])->toBe('extraction_failed');
});

it('devolve para a fila quando o provedor está fora', function () {
    useExtractor(new FakeReceiptExtractor(throw: new AiProviderUnavailable('529')));
    $r = submitted();

    expect(fn () => app(ProcessReimbursement::class)->handle($r->id))->toThrow(AiProviderUnavailable::class)
        ->and($r->refresh()->status)->toBe(ReimbursementStatus::Submitted);
});

it('é idempotente: não processa de novo nem gasta outra chamada de IA', function () {
    $fake = useExtractor(new FakeReceiptExtractor(receipt()));
    $r = submitted();

    app(ProcessReimbursement::class)->handle($r->id);
    app(ProcessReimbursement::class)->handle($r->id);

    expect($fake->calls)->toBe(1);
});

it('marca como falho quando o job esgota as tentativas', function () {
    $r = submitted();

    (new ProcessReimbursementJob($r->id))->failed(new AiProviderUnavailable('down'));

    expect($r->refresh()->status)->toBe(ReimbursementStatus::Failed);
});
