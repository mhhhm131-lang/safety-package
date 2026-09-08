<?php

namespace App\Providers;

use App\Core\Middleware\CheckPermission;
use App\Core\Services\AuditLogService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * يحمّل مسارات كل وحدة من app/Modules/<X>/Routes/{web,api}.php ويسجّل alias `permission`
 * وsingleton `audit.logger` (من OHSMS بلا tenant/trial/superadmin).
 */
class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('audit.logger', fn ($app) => $app->make(AuditLogService::class));
    }

    public function boot(): void
    {
        $this->app['router']->aliasMiddleware('permission', CheckPermission::class);
        $this->app['router']->aliasMiddleware('contractor', \App\Core\Middleware\EnsureContractor::class);
        $this->loadModuleRoutes();
    }

    protected function loadModuleRoutes(): void
    {
        $modulesPath = app_path('Modules');
        if (!is_dir($modulesPath)) {
            return;
        }
        foreach (scandir($modulesPath) as $module) {
            if ($module === '.' || $module === '..' || !is_dir("$modulesPath/$module")) {
                continue;
            }
            $web = "$modulesPath/$module/Routes/web.php";
            $api = "$modulesPath/$module/Routes/api.php";
            if (file_exists($web)) {
                Route::middleware('web')->group($web);
            }
            if (file_exists($api)) {
                Route::middleware('api')->prefix('api')->group($api);
            }
        }
    }
}
