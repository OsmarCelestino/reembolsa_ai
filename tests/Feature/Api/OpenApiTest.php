<?php

use Illuminate\Support\Facades\Route;

/** Garante que docs/openapi.yaml acompanha as rotas: rota nova sem documentação quebra o teste. */
it('documenta todas as rotas da API no OpenAPI', function () {
    $documented = [];
    $path = null;

    foreach (file(base_path('docs/openapi.yaml')) as $line) {
        if (preg_match('#^  (/[^:]+):\s*$#', $line, $m)) {
            $path = $m[1];
        } elseif (preg_match('#^\S#', $line)) {
            $path = null; // saiu de "paths:"
        } elseif ($path && preg_match('#^    (get|post|put|patch|delete):#', $line, $m)) {
            $documented[] = strtoupper($m[1]).' /api'.preg_replace('/\{\w+\}/', '{}', $path);
        }
    }

    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
        ->flatMap(fn ($route) => collect($route->methods())
            ->reject(fn ($m) => $m === 'HEAD')
            ->map(fn ($m) => $m.' /'.preg_replace('/\{\w+\}/', '{}', $route->uri())))
        ->sort()->values()->all();

    expect($routes)->not->toBeEmpty()
        ->and($routes)->toEqualCanonicalizing($documented);
});
