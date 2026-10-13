<?php

namespace App\Http\Controllers\Api;

use App\Actions\Reimbursements\ReviewReimbursement;
use App\Actions\Reimbursements\SubmitReimbursement;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListReimbursementsRequest;
use App\Http\Requests\ReviewReimbursementRequest;
use App\Http\Requests\StoreReimbursementRequest;
use App\Http\Resources\ReimbursementResource;
use App\Models\Reimbursement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** Controller fino: valida (FormRequest), autoriza (Policy) e delega (Action). */
class ReimbursementController extends Controller
{
    public function index(ListReimbursementsRequest $request): AnonymousResourceCollection
    {
        $reimbursements = Reimbursement::query()
            ->visibleTo($request->user())
            ->when($request->validated('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderBy($request->validated('sort') ?? 'created_at', $request->validated('direction') ?? 'desc')
            ->paginate($request->validated('per_page') ?? 15);

        return ReimbursementResource::collection($reimbursements);
    }

    public function store(StoreReimbursementRequest $request, SubmitReimbursement $submit): JsonResponse
    {
        $reimbursement = $submit->handle(
            user: $request->user(),
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

        return ReimbursementResource::make($reimbursement);
    }

    public function review(ReviewReimbursementRequest $request, Reimbursement $reimbursement, ReviewReimbursement $review): ReimbursementResource
    {
        Gate::authorize('review', $reimbursement);

        return ReimbursementResource::make(
            $review->handle($request->user(), $reimbursement, $request->approved(), $request->validated('note')),
        );
    }
}
