<?php

namespace App\Console\Commands;

use App\Core\Services\CloseoutService;
use App\Models\User;
use App\Modules\Governance\Models\UserProfile;
use Illuminate\Console\Command;

/**
 * تعطيل الحسابات التجريبية عند التسليم (المرحلة ٨-١).
 *
 * **تُعطَّل ولا تُحذف:** سجل التدقيق وأحداث البلاغات والتصاريح تشير إلى أصحابها؛
 * حذف الحساب يقطع الأثر. الحساب المعطَّل لا يدخل (المرحلة ١).
 *
 * **الحارس:** يرفض ما لم يوجد `system_admin` نشط خارج القائمة التجريبية — تعطيلها
 * كلها بلا بديل يغلق الباب على الجميع، ولا سطر أوامر على Render لفتحه.
 */
class IpaDemoOff extends Command
{
    protected $signature = 'ipa:demo-off {--dry-run : اعرض ما سيُعطَّل بلا تعطيل}';

    protected $description = 'تعطيل الحسابات التجريبية بعد التأكد من وجود حساب مسؤول سلامة حقيقي نشط.';

    public function handle(): int
    {
        $demo = User::whereIn('username', CloseoutService::DEMO_USERNAMES)->get();
        $active = $demo->filter(fn (User $u) => $this->isActive($u));

        if ($active->isEmpty()) {
            $this->info('لا حساب تجريبي نشط. لا شيء ليُعمل.');

            return self::SUCCESS;
        }

        $realAdmin = UserProfile::where('role', 'system_admin')
            ->where('is_active', true)
            ->whereHas('user', fn ($q) => $q->whereNotIn('username', CloseoutService::DEMO_USERNAMES))
            ->with('user:id,username,name')
            ->first();

        if (!$realAdmin) {
            $this->error('لا يوجد حساب «مسؤول السلامة» نشط خارج الحسابات التجريبية.');
            $this->line('أنشئه أولاً، وتحقّق من دخوله، ثم أعد هذا الأمر:');
            $this->line('  php artisan ipa:user <اسم-الدخول> system_admin "<الاسم>" --password=<كلمة قوية>');
            $this->line('التعطيل الآن يغلق الباب على الجميع، ولا سطر أوامر على Render لفتحه.');

            return self::FAILURE;
        }

        $this->line('الحساب الحقيقي: '.$realAdmin->user?->username.' — '.$realAdmin->user?->name);
        $this->line('سيُعطَّل: '.$active->pluck('username')->implode('، '));

        if ($this->option('dry-run')) {
            $this->info('عرض فقط — لم يُعطَّل شيء.');

            return self::SUCCESS;
        }

        foreach ($active as $user) {
            UserProfile::where('user_id', $user->id)->update(['is_active' => false]);
        }

        $this->info(sprintf('تم: عُطّل %d حساباً تجريبياً.', $active->count()));

        return self::SUCCESS;
    }

    private function isActive(User $user): bool
    {
        return (bool) UserProfile::where('user_id', $user->id)->value('is_active');
    }
}
