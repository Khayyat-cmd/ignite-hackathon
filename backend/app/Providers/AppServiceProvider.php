<?php

namespace App\Providers;

use App\Services\Ai\AgentIncidentAdvisor;
use App\Services\Ai\IncidentAdvisor;
use App\Services\Ai\OpenAiIncidentAdvisor;
use App\Services\Push\FirebaseCloudMessaging;
use App\Services\Push\PushNotifier;
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
        // The CAMARA orchestration agent is the advisor whenever its service is
        // configured. The single-model advisor stays the fallback for a local
        // machine that is not running the Python service.
        $this->app->bind(IncidentAdvisor::class, fn () => filled(config('aman.agent.url'))
            ? new AgentIncidentAdvisor
            : $this->app->make(OpenAiIncidentAdvisor::class));
        $this->app->bind(PushNotifier::class, FirebaseCloudMessaging::class);
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
