<?php

namespace App\Console\Commands;

use App\Ai\Contracts\ReceiptExtractor;
use App\Ai\Exceptions\AiProviderUnavailable;
use App\Ai\Exceptions\ExtractionFailed;
use App\Demo\DemoReceipts;
use App\Demo\DemoScenario;
use App\Domain\Reimbursement\ReimbursementRules;
use App\Domain\Reimbursement\Violation;
use App\Enums\ReimbursementStatus;
use App\Eval\EvalDataset;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Mede a qualidade do fluxo com comprovantes fictícios rotulados: acerto da extração
 * por campo, acerto da decisão final, detecção de manipulação, latência e custo.
 *
 * Chama a IA de verdade e as regras de verdade, mas não grava nada no banco.
 */
class EvalRun extends Command
{
    protected $signature = 'eval:run
        {--model= : Modelo a avaliar (padrão: LLM_MODEL)}
        {--runs=1 : Quantas vezes rodar cada comprovante (mede consistência)}';

    protected $description = 'Avalia extração e decisão da IA com comprovantes fictícios rotulados';

    private const FIELDS = ['cnpj', 'issue_date', 'total_cents', 'category'];

    public function handle(ReimbursementRules $rules): int
    {
        if (blank(config('llm.anthropic.api_key'))) {
            $this->components->error('ANTHROPIC_API_KEY não configurada no .env.');

            return self::FAILURE;
        }

        if ($model = $this->option('model')) {
            config(['llm.anthropic.model' => $model]);
        }

        $model = config('llm.anthropic.model');
        $extractor = app(ReceiptExtractor::class);
        $runs = max(1, (int) $this->option('runs'));
        $cases = EvalDataset::cases();

        $this->components->info(count($cases)." comprovantes × {$runs} execução(ões) com {$model}");

        $results = [];
        foreach ($cases as $i => $case) {
            for ($run = 1; $run <= $runs; $run++) {
                $result = $this->evaluate($extractor, $rules, $case);
                $results[] = $result;

                $label = sprintf('%02d. %s', $i + 1, $case->title).($runs > 1 ? " (#{$run})" : '');
                $this->components->twoColumnDetail($label, $this->summaryLine($result));
            }
        }

        $report = $this->aggregate($results, $model, $runs);
        $this->printReport($report, $results);
        $path = $this->save($report, $results);

        $this->newLine();
        $this->components->twoColumnDetail('Relatório completo (JSON)', $path);

        // Falha (código ≠ 0) se qualquer decisão errar ou alguma chamada falhar, para servir de gate no CI.
        $clean = $report['decisions']['hits'] === $report['calls']
            && $report['extraction_failures'] === 0;

        return $clean ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, mixed> */
    private function evaluate(ReceiptExtractor $extractor, ReimbursementRules $rules, DemoScenario $case): array
    {
        $base = [
            'case' => $case->fileName,
            'title' => $case->title,
            'expected_status' => $case->expectedStatus->value,
            'expected_suspicious' => $case->expectedSuspicious,
            'expected' => $case->expected,
        ];

        try {
            $result = $extractor->extract(DemoReceipts::pdf($case), 'application/pdf');
        } catch (ExtractionFailed $e) {
            // Mesmo comportamento do fluxo real: extração inválida vai para analista.
            return $base + ['status' => ReimbursementStatus::PendingReview->value, 'error' => $e->getMessage(),
                'violations' => [Violation::soft('extraction_failed', $e->getMessage())->code]];
        } catch (AiProviderUnavailable $e) {
            return $base + ['status' => null, 'error' => $e->getMessage(), 'violations' => []];
        }

        $receipt = $result->receipt;
        $decision = $rules->evaluate(
            declaredCategory: $case->category,
            claimedCents: Money::toCents($case->claimedAmount),
            receipt: $receipt,
            isDuplicate: false,
        );

        return $base + [
            'status' => $decision->status()->value,
            'error' => null,
            'violations' => $decision->codes(),
            'extracted' => $receipt->toArray(),
            'model' => $result->model,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'latency_ms' => $result->latencyMs,
            'cost_usd' => $this->cost(config('llm.anthropic.model'), $result->inputTokens, $result->outputTokens),
        ];
    }

    /** @param array<string, mixed> $r */
    private function summaryLine(array $r): string
    {
        if ($r['status'] === null) {
            return '<fg=red>erro: '.$r['error'].'</>';
        }

        $ok = $r['status'] === $r['expected_status'];
        $fields = $this->fieldHits($r);
        $hits = count(array_filter($fields));

        return sprintf(
            '%s%s</> · campos %d/%d · %s ms',
            $ok ? '<fg=green>✓ ' : '<fg=red>✗ ',
            $r['status'].($ok ? '' : " (esperado {$r['expected_status']})"),
            $hits,
            count($fields),
            $r['latency_ms'] ?? '—',
        );
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, bool>
     */
    private function fieldHits(array $r): array
    {
        $hits = [];
        foreach ($r['expected'] as $field => $expected) {
            $hits[$field] = ($r['extracted'][$field] ?? null) === $expected;
        }

        return $hits;
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    private function aggregate(array $results, string $model, int $runs): array
    {
        $answered = array_values(array_filter($results, fn ($r) => $r['status'] !== null));
        $extracted = array_values(array_filter($answered, fn ($r) => isset($r['extracted'])));

        $fields = [];
        foreach (self::FIELDS as $field) {
            $hits = $total = 0;
            foreach ($extracted as $r) {
                if (array_key_exists($field, $r['expected'])) {
                    $total++;
                    $hits += (int) (($r['extracted'][$field] ?? null) === $r['expected'][$field]);
                }
            }
            $fields[$field] = ['hits' => $hits, 'total' => $total];
        }

        $tp = $fp = $fn = 0;
        foreach ($extracted as $r) {
            $got = (bool) $r['extracted']['suspicious_content'];
            $tp += (int) ($got && $r['expected_suspicious']);
            $fp += (int) ($got && ! $r['expected_suspicious']);
            $fn += (int) (! $got && $r['expected_suspicious']);
        }

        // Erro perigoso: aprovar sozinho o que deveria ir para analista ou ser recusado.
        $wrongAutoApprovals = count(array_filter($answered, fn ($r) => $r['status'] === ReimbursementStatus::AutoApproved->value
            && $r['expected_status'] !== ReimbursementStatus::AutoApproved->value));

        $latencies = array_column($extracted, 'latency_ms');
        sort($latencies);

        $byStatus = [];
        foreach (ReimbursementStatus::cases() as $status) {
            $group = array_filter($answered, fn ($r) => $r['expected_status'] === $status->value);
            if ($group) {
                $byStatus[$status->value] = ['hits' => count(array_filter($group, fn ($r) => $r['status'] === $r['expected_status'])), 'total' => count($group)];
            }
        }

        $cost = array_sum(array_column($extracted, 'cost_usd'));

        return [
            'ran_at' => now()->toIso8601String(),
            'model' => $model,
            'runs_per_case' => $runs,
            'cases' => count($results) / $runs,
            'calls' => count($results),
            'provider_errors' => count($results) - count($answered),
            'extraction_failures' => count($answered) - count($extracted),
            'decisions' => ['hits' => count(array_filter($answered, fn ($r) => $r['status'] === $r['expected_status'])), 'total' => count($answered)],
            'decisions_by_expected_status' => $byStatus,
            'wrong_auto_approvals' => $wrongAutoApprovals,
            'fields' => $fields,
            'receipts_all_fields_ok' => [
                'hits' => count(array_filter($extracted, fn ($r) => $r['expected'] && ! in_array(false, $this->fieldHits($r), true))),
                'total' => count(array_filter($extracted, fn ($r) => (bool) $r['expected'])),
            ],
            'suspicious' => ['true_positives' => $tp, 'false_positives' => $fp, 'false_negatives' => $fn],
            'latency_ms' => [
                'p50' => $this->percentile($latencies, 50),
                'p95' => $this->percentile($latencies, 95),
                'max' => $latencies ? max($latencies) : null,
            ],
            'tokens' => [
                'input_avg' => $extracted ? (int) round(array_sum(array_column($extracted, 'input_tokens')) / count($extracted)) : 0,
                'output_avg' => $extracted ? (int) round(array_sum(array_column($extracted, 'output_tokens')) / count($extracted)) : 0,
            ],
            'cost_usd' => [
                'total' => $cost,
                'per_receipt' => $extracted ? $cost / count($extracted) : 0,
                'per_1000_receipts' => $extracted ? $cost / count($extracted) * 1000 : 0,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  list<array<string, mixed>>  $results
     */
    private function printReport(array $report, array $results): void
    {
        $pct = fn (array $x) => $x['total'] ? sprintf('%d/%d (%.0f%%)', $x['hits'], $x['total'], 100 * $x['hits'] / $x['total']) : '—';

        $this->newLine();
        $this->components->info('Decisão');
        $this->components->twoColumnDetail('Decisões corretas', $pct($report['decisions']));
        foreach ($report['decisions_by_expected_status'] as $status => $x) {
            $this->components->twoColumnDetail("  esperado {$status}", $pct($x));
        }
        $this->components->twoColumnDetail('Aprovações automáticas indevidas', (string) $report['wrong_auto_approvals']);

        $this->components->info('Extração');
        foreach ($report['fields'] as $field => $x) {
            $this->components->twoColumnDetail($field, $pct($x));
        }
        $this->components->twoColumnDetail('Comprovantes com todos os campos certos', $pct($report['receipts_all_fields_ok']));
        $s = $report['suspicious'];
        $this->components->twoColumnDetail('Manipulação detectada (VP / FP / FN)', "{$s['true_positives']} / {$s['false_positives']} / {$s['false_negatives']}");
        $this->components->twoColumnDetail('Falhas de extração / erros do provedor', "{$report['extraction_failures']} / {$report['provider_errors']}");

        $this->components->info('Latência e custo');
        $l = $report['latency_ms'];
        $this->components->twoColumnDetail('Latência p50 / p95 / máx', "{$l['p50']} / {$l['p95']} / {$l['max']} ms");
        $this->components->twoColumnDetail('Tokens médios (entrada / saída)', "{$report['tokens']['input_avg']} / {$report['tokens']['output_avg']}");
        $c = $report['cost_usd'];
        $this->components->twoColumnDetail('Custo total / por nota / por mil notas', sprintf('US$ %.4f / %.5f / %.2f', $c['total'], $c['per_receipt'], $c['per_1000_receipts']));

        $failed = array_filter($results, fn ($r) => $r['error'] !== null);
        if ($failed) {
            $this->components->warn(count($failed).' de '.count($results).' chamadas falharam: as métricas de decisão acima não medem a IA.');
            foreach (array_count_values(array_column($failed, 'error')) as $error => $count) {
                $this->line("  - {$count}× {$error}");
            }
        }

        $misses = array_filter($results, fn ($r) => $r['error'] === null
            && ($r['status'] !== $r['expected_status'] || in_array(false, $this->fieldHits($r), true)));
        if ($misses) {
            $this->components->info('Erros');
            foreach ($misses as $r) {
                $wrong = array_keys(array_filter($this->fieldHits($r), fn ($ok) => ! $ok));
                $detail = collect($wrong)->map(fn ($f) => "{$f}: esperado ".json_encode($r['expected'][$f]).', obtido '.json_encode($r['extracted'][$f] ?? null))->implode('; ');
                $this->line("  - {$r['case']}: status {$r['status']} (esperado {$r['expected_status']})".($detail ? " · {$detail}" : '').($r['error'] ? " · {$r['error']}" : ''));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  list<array<string, mixed>>  $results
     */
    private function save(array $report, array $results): string
    {
        $disk = Storage::disk('local');
        $file = 'eval/eval-'.now()->format('Ymd-His').'.json';
        $disk->put($file, json_encode(['summary' => $report, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $disk->path($file);
    }

    /** Custo em US$ pela tabela config('llm.pricing_per_mtok'); null se o modelo não tem preço lá. */
    private function cost(string $model, int $input, int $output): ?float
    {
        $prices = config("llm.pricing_per_mtok.{$model}");

        return $prices ? ($input * $prices['input'] + $output * $prices['output']) / 1_000_000 : null;
    }

    /**
     * Percentil pelo método nearest-rank (sem interpolação).
     *
     * @param  list<int>  $sorted
     */
    private function percentile(array $sorted, int $p): ?int
    {
        if (! $sorted) {
            return null;
        }

        return $sorted[(int) ceil($p / 100 * count($sorted)) - 1];
    }
}
