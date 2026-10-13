<?php

namespace Database\Factories;

use App\Enums\ExpenseCategory;
use App\Enums\ReimbursementStatus;
use App\Models\Reimbursement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Reimbursement> */
class ReimbursementFactory extends Factory
{
    protected $model = Reimbursement::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category' => ExpenseCategory::Alimentacao,
            'claimed_amount_cents' => 4_590,
            'description' => 'Almoço com cliente',
            'receipt_path' => 'receipts/'.fake()->uuid().'.pdf',
            'receipt_mime' => 'application/pdf',
            'receipt_sha256' => hash('sha256', fake()->uuid()),
            'status' => ReimbursementStatus::Submitted,
        ];
    }

    public function pendingReview(): static
    {
        return $this->state(fn () => [
            'status' => ReimbursementStatus::PendingReview,
            'violations' => [['code' => 'low_confidence', 'severity' => 'soft', 'message' => 'Confiança baixa.']],
            'processed_at' => now(),
        ]);
    }
}
