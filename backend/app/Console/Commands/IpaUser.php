<?php

namespace App\Console\Commands;

use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * إنشاء حساب أو تحديثه من سطر الأوامر (للتطوير، ولحساب مسؤول السلامة الأول في الإنتاج عبر entrypoint).
 *
 *   php artisan ipa:user salama system_admin "مسؤول السلامة" --password=xxxx [--unit=adm-eng] [--place=HZ-00] [--disable]
 */
class IpaUser extends Command
{
    protected $signature = 'ipa:user {username} {role} {name} {--password=} {--unit=} {--place=} {--disable}';

    protected $description = 'إنشاء حساب معهدي أو تحديثه (اسم الدخول، الدور، الاسم، كلمة المرور، الوحدة، المكان)';

    public function handle(): int
    {
        $username = Str::lower(trim($this->argument('username')));
        $role = $this->argument('role');
        if (!PermissionRegistry::isValidRole($role)) {
            $this->error('الدور غير معروف. المسموح: '.implode(', ', array_keys(PermissionRegistry::ROLES)));
            return self::FAILURE;
        }

        $password = $this->option('password') ?: Str::password(12, symbols: false);
        $user = User::updateOrCreate(['username' => $username], ['name' => $this->argument('name'), 'password' => $password]);

        $unitId = $this->option('unit') ? OrganizationUnit::where('code', $this->option('unit'))->value('id') : null;
        UserProfile::updateOrCreate(['user_id' => $user->id], [
            'role' => $role,
            'organization_unit_id' => $unitId,
            'place_id' => Place::idByCode($this->option('place')),
            'is_active' => !$this->option('disable'),
        ]);

        $this->info("الحساب: {$user->username} · الدور: ".PermissionRegistry::getRoleDisplayName($role)." · الاسم: {$user->name}");
        if (!$this->option('password')) {
            $this->warn("كلمة المرور المولّدة (تظهر مرة واحدة): {$password}");
        }
        return self::SUCCESS;
    }
}
