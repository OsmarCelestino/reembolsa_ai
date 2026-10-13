<?php

namespace App\Providers;

use App\Ai\AnthropicReceiptExtractor;
use App\Ai\Contracts\ReceiptExtractor;
use App\Domain\Reimbursement\ReimbursementRules;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ReceiptExtractor::class, fn () => new AnthropicReceiptExtractor(
            apiKey: config('llm.anthropic.api_key'),
            model: config('llm.anthropic.model'),
            allowedModels: config('llm.allowed_models'),
            baseUrl: config('llm.anthropic.base_url'),
            version: config('llm.anthropic.version'),
            maxTokens: config('llm.anthropic.max_tokens'),
            timeout: config('llm.anthropic.timeout'),
        ));

        $this->app->bind(ReimbursementRules::class, fn () => ReimbursementRules::fromConfig());
    }

    public function boot(): void
    {
        // Em dev/teste, acusa acesso a atributo inexistente, lazy loading e mass assignment silencioso.
        Model::shouldBeStrict(! $this->app->isProduction());

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        RateLimiter::for('reimbursements', fn (Request $request) => Limit::perMinute(
            (int) config('reimbursement.submit_rate_per_minute'),
        )->by($request->user()?->id ?: $request->ip()));
    }
}
