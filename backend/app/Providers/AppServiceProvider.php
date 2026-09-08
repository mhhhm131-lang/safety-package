<?php

namespace App\Providers;

use App\Modules\Risk\Models\Risk;
use App\Policies\RiskPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // في الإنتاج الموقع خلف https دائماً (Render). كل الروابط والتحويلات https.
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        Gate::policy(Risk::class, RiskPolicy::class);
    }
}
