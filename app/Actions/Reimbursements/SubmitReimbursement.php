<?php

namespace App\Actions\Reimbursements;

use App\Enums\ExpenseCategory;
use App\Enums\ReimbursementStatus;
use App\Jobs\ProcessReimbursementJob;
use App\Models\Reimbursement;
use App\Models\User;
use App\Support\Text;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Recebe um pedido: guarda o comprovante no disco privado, cria o registro como
 * submitted e agenda o processamento por IA na fila (só depois do commit).
 */
final class SubmitReimbursement
{
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    /** @throws ValidationException se o tipo real do arquivo não for permitido ou o arquivo já tiver sido enviado */
    public function handle(
        User $user,
        UploadedFile $receipt,
        ExpenseCategory $category,
        int $claimedCents,
        ?string $description,
    ): Reimbursement {
        // Tipo detectado pelo conteúdo do arquivo (finfo), não pela extensão enviada.
        $mime = (string) $receipt->getMimeType();
        $extension = self::EXTENSIONS[$mime] ?? throw ValidationException::withMessages([
            'receipt' => 'Tipo de arquivo não permitido.',
        ]);

        $sha256 = hash_file('sha256', $receipt->getRealPath());

        // Bloqueia o reenvio do mesmo arquivo antes de gastar uma chamada de IA.
        $alreadyUsed = Reimbursement::query()
            ->where('receipt_sha256', $sha256)
            ->where('status', '!=', ReimbursementStatus::Rejected)
            ->exists();

        if ($alreadyUsed) {
            throw ValidationException::withMessages(['receipt' => 'Este comprovante já foi enviado.']);
        }

        $disk = config('reimbursement.upload.disk');
        // Nome aleatório: o nome original do arquivo nunca vira caminho no servidor.
        $path = $receipt->storeAs(config('reimbursement.upload.directory'), Str::uuid().'.'.$extension, $disk)
            ?: throw new RuntimeException('Não foi possível gravar o comprovante.');

        try {
            return DB::transaction(function () use ($user, $category, $claimedCents, $description, $path, $mime, $sha256) {
                $reimbursement = new Reimbursement([
                    'category' => $category,
                    'claimed_amount_cents' => $claimedCents,
                    'description' => Text::clean($description, 500),
                ]);

                $reimbursement->transitionTo(ReimbursementStatus::Submitted, $user, [
                    'user_id' => $user->id,
                    'receipt_path' => $path,
                    'receipt_mime' => $mime,
                    'receipt_sha256' => $sha256,
                ]);

                ProcessReimbursementJob::dispatch($reimbursement->id)->afterCommit();

                return $reimbursement;
            });
        } catch (UniqueConstraintViolationException) {
            // Dois envios do mesmo arquivo ao mesmo tempo: o índice único do banco segura o segundo.
            Storage::disk($disk)->delete($path);

            throw ValidationException::withMessages(['receipt' => 'Este comprovante já foi enviado.']);
        }
    }
}
