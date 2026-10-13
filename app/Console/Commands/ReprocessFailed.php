<?php

namespace App\Console\Commands;

use App\Actions\Reimbursements\ReprocessReimbursement;
use App\Enums\ReimbursementStatus;
use App\Models\Reimbursement;
use Illuminate\Console\Command;

class ReprocessFailed extends Command
{
    protected $signature = 'reembolso:reprocess-failed';

    protected $description = 'Devolve à fila todos os pedidos com status failed';

    public function handle(ReprocessReimbursement $reprocess): int
    {
        $count = 0;

        Reimbursement::query()
            ->where('status', ReimbursementStatus::Failed)
            ->each(function (Reimbursement $reimbursement) use ($reprocess, &$count) {
                $reprocess->handle($reimbursement);
                $count++;
            });

        $this->components->info("{$count} pedido(s) devolvido(s) à fila.");

        return self::SUCCESS;
    }
}
