<?php

use App\Support\Money;

it('converte reais em centavos sem float', function (string $in, int $out) {
    expect(Money::toCents($in))->toBe($out);
})->with([
    ['10', 1000],
    ['10.5', 1050],
    ['10,50', 1050],
    ['0.01', 1],
    ['0.29', 29], // 0.29 * 100 em float dá 28.999...
    ['1234567.89', 123456789],
]);

it('recusa formatos inválidos', function (string $in) {
    Money::toCents($in);
})->with(['-10', '1e3', '10.999', 'abc', '', '10.00; DROP TABLE users'])
    ->throws(InvalidArgumentException::class);

it('formata em reais', function () {
    expect(Money::format(123456))->toBe('R$ 1.234,56');
});
