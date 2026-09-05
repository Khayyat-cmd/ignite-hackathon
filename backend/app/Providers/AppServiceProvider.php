<?php

namespace App\Providers;

use App\Services\Ai\IncidentAdvisor;
use App\Services\Ai\OpenAiIncidentAdvisor;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(IncidentAdvisor::class, OpenAiIncidentAdvisor::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?? $request->ip()));
        RateLimiter::for('network', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?? $request->ip()));
    }
}
