<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * LGPD (direito de eliminação): anonimiza a pessoa sem apagar os pedidos,
 * que precisam ser guardados pelo prazo legal. Remove nome, e-mail, senha,
 * tokens de acesso e as descrições livres que ela escreveu.
 */
class AnonymizeUser extends Command
{
    protected $signature = 'reembolso:anonymize-user {user : ID do usuário} {--force : Não pede confirmação}';

    protected $description = 'Anonimiza um usuário (LGPD) mantendo os pedidos para fins contábeis';

    public function handle(): int
    {
        $id = $this->argument('user');
        $user = is_string($id) && ctype_digit($id) ? User::query()->find((int) $id) : null;

        if ($user === null) {
            $this->components->error('Usuário não encontrado.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Anonimizar o usuário #{$user->id}? Não dá para desfazer.")) {
            return self::FAILURE;
        }

        DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            $user->reimbursements()->update(['description' => null]);

            $user->forceFill([
                'name' => 'Usuário anonimizado',
                'email' => "anonimizado-{$user->id}@invalid.local",
                'password' => Hash::make(Str::random(64)),
                'remember_token' => null,
            ])->save();
        });

        $this->components->info("Usuário #{$user->id} anonimizado. Pedidos mantidos sem dados pessoais.");

        return self::SUCCESS;
    }
}
