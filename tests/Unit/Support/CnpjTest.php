<?php

use App\Support\Cnpj;

it('valida CNPJ numérico e alfanumérico', function (string $cnpj, bool $valid) {
    expect(Cnpj::isValid($cnpj))->toBe($valid);
})->with([
    'numérico com máscara' => ['11.222.333/0001-81', true],
    'numérico sem máscara' => ['11222333000181', true],
    'alfanumérico (2026)' => ['12.ABC.345/01DE-35', true],
    'alfanumérico minúsculo' => ['12abc34501de35', true],
    'dígito errado' => ['11.222.333/0001-82', false],
    'repetido' => ['00000000000000', false],
    'curto' => ['1122233300018', false],
    'DV com letra' => ['12ABC34501DE3A', false],
    'injeção' => ["11222333000181' OR 1=1 --", false],
]);

it('formata o CNPJ', function () {
    expect(Cnpj::format('12abc34501de35'))->toBe('12.ABC.345/01DE-35');
});
