<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * إنشاء حساب أو إعادة ضبط كلمة مروره من سطر الأوامر.
 * في الإنتاج: أول حساب يُنشأ بهذا الأمر يدوياً مرة واحدة (BACKEND.md ٧-٤).
 *
 *   php artisan ipa:user salama safety "مسؤول السلامة" --password=xxxx
 */
class IpaUser extends Command
{
    protected $signature = 'ipa:user {username} {role} {name} {--password=} {--dept=} {--disable}';

    protected $description = 'إنشاء حساب معهدي أو تحديثه (اسم الدخول، الدور، الاسم، كلمة المرور)';

    public const ROLES = ['tech', 'fm', 'adm', 'exec', 'safety', 'cons', 'dept'];

    public function handle(): int
    {
        $username = Str::lower(trim($this->argument('username')));
        $role = $this->argument('role');
        if (!in_array($role, self::ROLES, true)) {
            $this->error('الدور غير معروف. المسموح: '.implode(', ', self::ROLES));
            return self::FAILURE;
        }

        $password = $this->option('password') ?: Str::password(12, symbols: false);
        $user = User::updateOrCreate(
            ['username' => $username],
            [
                'name' => $this->argument('name'),
                'role' => $role,
                'dept_code' => $this->option('dept') ?: null,
                'password' => $password,
                'is_active' => !$this->option('disable'),
            ]
        );

        $this->info("الحساب: {$user->username} · الدور: {$user->role} · الاسم: {$user->name}");
        if (!$this->option('password')) {
            $this->warn("كلمة المرور المولّدة (تظهر مرة واحدة): {$password}");
        }
        return self::SUCCESS;
    }
}
