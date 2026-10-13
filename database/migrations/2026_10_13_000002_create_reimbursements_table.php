<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reimbursements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Dados informados por quem pede
            $table->string('category', 30);
            $table->unsignedInteger('claimed_amount_cents');
            $table->string('description', 500)->nullable();

            // Arquivo (disco privado)
            $table->string('receipt_path');
            $table->string('receipt_mime', 100);
            $table->char('receipt_sha256', 64)->index();

            $table->string('status', 30)->index();

            // Resultado da IA
            $table->json('extracted')->nullable();
            $table->char('receipt_fingerprint', 64)->nullable()->index();
            $table->decimal('ai_confidence', 3, 2)->nullable();
            $table->string('ai_model', 100)->nullable();
            $table->unsignedInteger('ai_input_tokens')->nullable();
            $table->unsignedInteger('ai_output_tokens')->nullable();
            $table->unsignedInteger('ai_latency_ms')->nullable();
            $table->json('violations')->nullable();
            $table->timestamp('processed_at')->nullable();

            // Revisão humana
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_note', 1000)->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reimbursements');
    }
};
