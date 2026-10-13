<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /** Usuários de desenvolvimento (senha "password"). Nunca rode em produção. */
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        foreach ([
            ['funcionario@reembolsa.test', 'Funcionário Demo', UserRole::Employee],
            ['analista@reembolsa.test', 'Analista Demo', UserRole::Reviewer],
        ] as [$email, $name, $role]) {
            User::query()->updateOrCreate(['email' => $email], ['name' => $name, 'password' => Hash::make('password')])
                ->forceFill(['role' => $role])
                ->save();
        }
    }
}
