<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReviewReimbursementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // a autorização fica na Policy, chamada no controller
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:approve,reject'],
            'note' => ['required_if:decision,reject', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function approved(): bool
    {
        return $this->validated('decision') === 'approve';
    }
}
