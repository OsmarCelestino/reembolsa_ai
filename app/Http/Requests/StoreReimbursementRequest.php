<?php

namespace App\Http\Requests;

use App\Enums\ExpenseCategory;
use App\Support\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReimbursementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // qualquer usuário autenticado pode pedir reembolso
    }

    public function rules(): array
    {
        $upload = config('reimbursement.upload');

        return [
            'category' => ['required', Rule::enum(ExpenseCategory::class)],
            'claimed_amount' => [
                'required',
                'regex:'.Money::PATTERN,
                function (string $attribute, mixed $value, Closure $fail) {
                    if (preg_match(Money::PATTERN, (string) $value) && Money::toCents((string) $value) <= 0) {
                        $fail('O valor precisa ser maior que zero.');
                    }
                },
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'receipt' => [
                'required',
                'file',
                'max:'.$upload['max_kb'],
                'mimetypes:'.implode(',', $upload['mimetypes']), // confere o conteúdo real do arquivo
                'extensions:jpg,jpeg,png,webp,pdf',
            ],
        ];
    }

    public function category(): ExpenseCategory
    {
        return ExpenseCategory::from($this->validated('category'));
    }

    public function claimedCents(): int
    {
        return Money::toCents((string) $this->validated('claimed_amount'));
    }
}
