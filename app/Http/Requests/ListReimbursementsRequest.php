<?php

namespace App\Http\Requests;

use App\Enums\ReimbursementStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListReimbursementsRequest extends FormRequest
{
    /** Colunas liberadas para ordenação. Nada vindo do usuário vira nome de coluna fora desta lista. */
    public const SORTABLE = ['created_at', 'claimed_amount_cents'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(ReimbursementStatus::class)],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ];
    }
}
