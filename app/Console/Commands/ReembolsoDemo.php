<?php

namespace App\Console\Commands;

use App\Actions\Reimbursements\SubmitReimbursement;
use App\Demo\DemoReceipts;
use App\Demo\DemoScenario;
use App\Enums\UserRole;
use App\Models\Reimbursement;
use App\Models\User;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Roda o fluxo completo com dois comprovantes fictícios e compara o resultado
 * com o gabarito: envio → IA (Anthropic de verdade) → regras → status final.
 */
class ReembolsoDemo extends Command
{
    protected $signature = 'reembolso:demo
        {--only-files : Só gera os PDFs de exemplo, sem chamar a IA}';

    protected $description = 'Processa 2 notas fictícias com a IA e mostra o resultado esperado x obtido';

    public const EMPLOYEE_EMAIL = 'funcionario@reembolsa.test';

    public function handle(SubmitReimbursement $submit): int
    {
        $disk = Storage::disk(config('reimbursement.upload.disk'));
        $scenarios = DemoReceipts::scenarios();

        $this->components->info('Gerando comprovantes fictícios');
        foreach ($scenarios as $scenario) {
            $disk->put("demo/{$scenario->fileName}", DemoReceipts::pdf($scenario));
            $this->components->twoColumnDetail($scenario->fileName, $disk->path("demo/{$scenario->fileName}"));
        }

        if ($this->option('only-files')) {
            return self::SUCCESS;
        }

        if (blank(config('llm.anthropic.api_key'))) {
            $this->components->error('ANTHROPIC_API_KEY não configurada no .env. Use --only-files para só gerar os PDFs.');

            return self::FAILURE;
        }

        // Processa na hora (sem depender do worker da fila) para mostrar o resultado.
        config(['queue.default' => 'sync']);

        $employee = $this->employee();
        $this->resetPreviousRun($employee);

        $results = [];
        foreach ($scenarios as $i => $scenario) {
            $this->newLine();
            $this->components->info(($i + 1).". {$scenario->title}");

            $file = new UploadedFile($disk->path("demo/{$scenario->fileName}"), $scenario->fileName, 'application/pdf', null, true);

            try {
                $reimbursement = $submit->handle($employee, $file, $scenario->category, Money::toCents($scenario->claimedAmount), $scenario->description);
            } catch (Throwable $e) {
                $this->components->error('Falha ao processar: '.$e->getMessage());

                return self::FAILURE;
            }

            $results[] = $this->report($scenario, $reimbursement->refresh());
        }

        return $this->summary($results);
    }

    /** @return array{status_ok: bool, fields_ok: int, fields_total: int, tokens: int} */
    private function report(DemoScenario $scenario, Reimbursement $r): array
    {
        $statusOk = $r->status === $scenario->expectedStatus;

        $this->components->twoColumnDetail('Pedido', "{$scenario->category->value} · ".Money::format(Money::toCents($scenario->claimedAmount)));
        $this->components->twoColumnDetail('Status esperado', $scenario->expectedStatus->value);
        $this->components->twoColumnDetail('Status obtido', ($statusOk ? '<fg=green>✓ ' : '<fg=red>✗ ').$r->status->value.'</>');
        $this->components->twoColumnDetail('Por quê', $scenario->why);

        $rows = [];
        $fieldsOk = 0;
        foreach ($scenario->expected as $field => $expected) {
            $got = $r->extracted[$field] ?? null;
            $ok = $got === $expected;
            $fieldsOk += (int) $ok;
            $rows[] = [$field, $this->show($field, $expected), $this->show($field, $got), $ok ? '✓' : '✗'];
        }
        $rows[] = ['suspicious_content', '—', var_export($r->extracted['suspicious_content'] ?? null, true), ''];
        $rows[] = ['confidence', '—', (string) ($r->extracted['confidence'] ?? '—'), ''];

        $this->table(['Campo', 'Gabarito', 'Extraído pela IA', ''], $rows);

        if ($r->violations) {
            $this->line('  Regras acionadas:');
            foreach ($r->violations as $v) {
                $this->line("   - [{$v['severity']}] {$v['code']}: {$v['message']}");
            }
        } else {
            $this->line('  Nenhuma regra acionada.');
        }

        $tokens = (int) $r->ai_input_tokens + (int) $r->ai_output_tokens;
        $this->line("  IA: {$r->ai_model} · {$r->ai_input_tokens} tokens de entrada / {$r->ai_output_tokens} de saída · {$r->ai_latency_ms} ms");

        return ['status_ok' => $statusOk, 'fields_ok' => $fieldsOk, 'fields_total' => count($scenario->expected), 'tokens' => $tokens];
    }

    /** @param list<array{status_ok: bool, fields_ok: int, fields_total: int, tokens: int}> $results */
    private function summary(array $results): int
    {
        $statusOk = count(array_filter($results, fn ($r) => $r['status_ok']));
        $fieldsOk = array_sum(array_column($results, 'fields_ok'));
        $fieldsTotal = array_sum(array_column($results, 'fields_total'));

        $this->newLine();
        $this->components->info('Resumo');
        $this->components->twoColumnDetail('Decisões corretas', "{$statusOk}/".count($results));
        $this->components->twoColumnDetail('Campos extraídos corretamente', "{$fieldsOk}/{$fieldsTotal}");
        $this->components->twoColumnDetail('Tokens usados', (string) array_sum(array_column($results, 'tokens')));

        return $statusOk === count($results) ? self::SUCCESS : self::FAILURE;
    }

    private function show(string $field, mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            $field === 'total_cents' => Money::format((int) $value),
            default => (string) $value,
        };
    }

    /** Garante o funcionário da demo, mesmo sem ter rodado o seeder. */
    private function employee(): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => self::EMPLOYEE_EMAIL],
            ['name' => 'Funcionário Demo', 'password' => Hash::make(Str::random(32))],
        );

        $user->forceFill(['role' => UserRole::Employee])->save();

        return $user;
    }

    /** Apaga só os pedidos do usuário de demonstração, para a demo poder rodar várias vezes. */
    private function resetPreviousRun(User $employee): void
    {
        $disk = Storage::disk(config('reimbursement.upload.disk'));

        Reimbursement::query()->whereBelongsTo($employee)->each(function (Reimbursement $r) use ($disk) {
            $disk->delete($r->receipt_path);
            $r->delete();
        });
    }
}
