<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Trilha de auditoria: mudanças de status e acessos ao comprovante (LGPD).
        Schema::create('reimbursement_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reimbursement_id')->constrained()->cascadeOnDelete();
            $table->string('action', 40); // status_changed | receipt_downloaded | receipt_purged
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete(); // null = sistema
            $table->string('reason', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['reimbursement_id', 'id']);
        });

        Schema::table('reimbursements', function (Blueprint $table) {
            $table->timestamp('receipt_purged_at')->nullable();
        });

        // Duplicidade garantida pelo banco, mesmo com dois envios simultâneos.
        // Índice parcial: pedidos recusados não bloqueiam (dá para reenviar depois de uma recusa).
        // Sintaxe aceita por PostgreSQL e SQLite (testes).
        DB::statement("CREATE UNIQUE INDEX reimbursements_sha256_active_unique ON reimbursements (receipt_sha256) WHERE status <> 'rejected'");
        DB::statement("CREATE UNIQUE INDEX reimbursements_fingerprint_active_unique ON reimbursements (receipt_fingerprint) WHERE status <> 'rejected' AND receipt_fingerprint IS NOT NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS reimbursements_fingerprint_active_unique');
        DB::statement('DROP INDEX IF EXISTS reimbursements_sha256_active_unique');

        Schema::table('reimbursements', function (Blueprint $table) {
            $table->dropColumn('receipt_purged_at');
        });

        Schema::dropIfExists('reimbursement_audit_logs');
    }
};
