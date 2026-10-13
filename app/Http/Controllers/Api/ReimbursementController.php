<?php

namespace App\Http\Controllers\Api;

use App\Actions\Reimbursements\ReprocessReimbursement;
use App\Actions\Reimbursements\ReviewReimbursement;
use App\Actions\Reimbursements\SubmitReimbursement;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListReimbursementsRequest;
use App\Http\Requests\ReviewReimbursementRequest;
use App\Http\Requests\StoreReimbursementRequest;
use App\Http\Resources\ReimbursementResource;
use App\Models\Reimbursement;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Controller fino: valida (FormRequest), autoriza (Policy) e delega (Action). */
class ReimbursementController extends Controller
{
    public function index(ListReimbursementsRequest $request, #[CurrentUser] User $user): AnonymousResourceCollection
    {
        $reimbursements = Reimbursement::query()
            ->visibleTo($user)
            ->when($request->validated('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderBy($request->validated('sort') ?? 'created_at', $request->validated('direction') ?? 'desc')
            ->paginate($request->validated('per_page') ?? 15);

        return ReimbursementResource::collection($reimbursements);
    }

    public function store(StoreReimbursementRequest $request, SubmitReimbursement $submit, #[CurrentUser] User $user): JsonResponse
    {
        $reimbursement = $submit->handle(
            user: $user,
            receipt: $request->file('receipt'),
            category: $request->category(),
            claimedCents: $request->claimedCents(),
            description: $request->validated('description'),
        );

        // 202: aceito, o processamento com IA acontece na fila.
        return ReimbursementResource::make($reimbursement->refresh())->response()->setStatusCode(202);
    }

    public function show(Reimbursement $reimbursement): ReimbursementResource
    {
        Gate::authorize('view', $reimbursement);

        return ReimbursementResource::make($reimbursement->load('auditLogs'));
    }

    /** Comprovante para o dono e para analistas. Cada acesso fica na auditoria (LGPD). */
    public function receipt(Reimbursement $reimbursement, #[CurrentUser] User $user): StreamedResponse
    {
        Gate::authorize('view', $reimbursement);

        abort_unless($reimbursement->receiptAvailable(), 410, 'O comprovante foi descartado pela política de retenção.');

        $reimbursement->audit(AuditAction::ReceiptDownloaded, $user);

        $extension = pathinfo($reimbursement->receipt_path, PATHINFO_EXTENSION);

        return Storage::disk(config('reimbursement.upload.disk'))->download(
            $reimbursement->receipt_path,
            "comprovante-{$reimbursement->id}.{$extension}",
            [
                'Content-Type' => $reimbursement->receipt_mime,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ],
        );
    }

    public function reprocess(Reimbursement $reimbursement, ReprocessReimbursement $reprocess, #[CurrentUser] User $user): JsonResponse
    {
        Gate::authorize('reprocess', $reimbursement);

        return ReimbursementResource::make($reprocess->handle($reimbursement, $user))
            ->response()->setStatusCode(202);
    }

    public function review(ReviewReimbursementRequest $request, Reimbursement $reimbursement, ReviewReimbursement $review, #[CurrentUser] User $user): ReimbursementResource
    {
        Gate::authorize('review', $reimbursement);

        return ReimbursementResource::make(
            $review->handle($user, $reimbursement, $request->approved(), $request->validated('note')),
        );
    }
}
