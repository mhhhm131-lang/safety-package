<?php

namespace App\Providers;

use App\Modules\Incident\Models\Incident;
use App\Modules\Incident\Observers\IncidentObserver;
use App\Modules\Risk\Models\Risk;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Form\Models\FormTemplate;
use App\Modules\Permit\Models\Permit;
use App\Policies\EmergencyPolicy;
use App\Policies\FormPolicy;
use App\Policies\IncidentPolicy;
use App\Policies\PermitPolicy;
use App\Policies\RiskPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        Gate::policy(Incident::class, IncidentPolicy::class);
        Gate::policy(EmergencyIncident::class, EmergencyPolicy::class);
        Gate::policy(Permit::class, PermitPolicy::class);
        Gate::policy(FormTemplate::class, FormPolicy::class);
        // المرحلة ٥: Webhooks الأجهزة — ٦٠٠ في الدقيقة لكل عنوان (لوحة إنذار قد ترسل حدثاً لكل منطقة)
        \Illuminate\Support\Facades\RateLimiter::for('iot-webhook', fn ($request) => \Illuminate\Cache\RateLimiting\Limit::perMinute(600)->by($request->ip()));
        Incident::observe(IncidentObserver::class);

        // الصفحات العامة لبلاغ الشاغل: ١٠ في الساعة لكل عنوان (OHSMS)
        RateLimiter::for('incident-public', fn (Request $request) => Limit::perHour(10)->by($request->ip()));
    }
}
