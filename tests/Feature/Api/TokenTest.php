<?php

use App\Models\User;

it('emite token com expiração para credenciais válidas', function () {
    $user = User::factory()->create(['password' => 'segredo-forte-123']);

    $this->postJson('/api/tokens', ['email' => $user->email, 'password' => 'segredo-forte-123', 'device_name' => 'insomnia'])
        ->assertCreated()
        ->assertJsonStructure(['token', 'expires_at']);
});

it('mesma resposta para e-mail inexistente e senha errada', function () {
    $user = User::factory()->create();

    $wrongPassword = $this->postJson('/api/tokens', ['email' => $user->email, 'password' => 'x', 'device_name' => 't'])->json('errors');
    $unknownEmail = $this->postJson('/api/tokens', ['email' => 'nao@existe.com', 'password' => 'x', 'device_name' => 't'])->json('errors');

    expect($wrongPassword)->toBe($unknownEmail);
});

it('limita tentativas de login', function () {
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/tokens', ['email' => 'a@a.com', 'password' => 'x', 'device_name' => 't'])->assertUnprocessable();
    }

    $this->postJson('/api/tokens', ['email' => 'a@a.com', 'password' => 'x', 'device_name' => 't'])->assertTooManyRequests();
});
