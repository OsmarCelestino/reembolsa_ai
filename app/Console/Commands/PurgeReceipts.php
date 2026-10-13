<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Enums\ReimbursementStatus;
use App\Models\Reimbursement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * LGPD (minimização e retenção): apaga o arquivo do comprovante e a descrição livre
 * dos pedidos já encerrados que passaram do prazo de retenção. Os dados extraídos
 * (CNPJ, data, valor) e a auditoria ficam, porque sustentam a contabilidade.
 */
class PurgeReceipts extends Command
{
    protected $signature = 'reembolso:purge-receipts {--dry-run : Só mostra quantos seriam descartados}';

    protected $description = 'Descarta comprovantes de pedidos encerrados que passaram do prazo de retenção';

    public function handle(): int
    {
        $days = (int) config('reimbursement.retention_days');
        $disk = Storage::disk(config('reimbursement.upload.disk'));

        $query = Reimbursement::query()
            ->whereNull('receipt_purged_at')
            ->whereIn('status', [
                ReimbursementStatus::AutoApproved,
                ReimbursementStatus::Approved,
                ReimbursementStatus::Rejected,
                ReimbursementStatus::Failed,
            ])
            ->where('created_at', '<', now()->subDays($days));

        if ($this->option('dry-run')) {
            $this->components->info("{$query->count()} comprovante(s) passaram de {$days} dias.");

            return self::SUCCESS;
        }

        $count = 0;
        $query->each(function (Reimbursement $reimbursement) use ($disk, &$count) {
            DB::transaction(function () use ($reimbursement) {
                $reimbursement->forceFill(['receipt_purged_at' => now(), 'description' => null])->save();
                $reimbursement->audit(AuditAction::ReceiptPurged, reason: 'retention_policy');
            });

            // Depois do commit: se a transação falhar, o arquivo continua lá.
            $disk->delete($reimbursement->receipt_path);
            $count++;
        });

        $this->components->info("{$count} comprovante(s) descartado(s) (retenção de {$days} dias).");

        return self::SUCCESS;
    }
}
