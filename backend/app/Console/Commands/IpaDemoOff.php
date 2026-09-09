<?php

namespace App\Console\Commands;

use App\Core\Services\CloseoutService;
use App\Models\User;
use App\Modules\Governance\Models\UserProfile;
use Illuminate\Console\Command;

/**
 * تعطيل الحسابات التي ما زالت على كلمة المرور المبذورة (المرحلة ٨-١).
 *
 * **الخطر في الكلمة لا في الاسم** (تصحيح ٢٠٢٦-٠٩-٠٩): الاسم التجريبي الذي غيّر صاحبه
 * كلمته صار حساباً حقيقياً يعمل به، وتعطيله يقفل الباب عليه بلا سبب. والذي بقي على
 * الكلمة المبذورة خطرٌ على موقع مفتوح للإنترنت مهما كان اسمه.
 * `--all` يعطّل القائمة التجريبية كلها ولو غُيّرت كلماتها — للتسليم النهائي.
 *
 * **تُعطَّل ولا تُحذف:** سجل التدقيق وأحداث البلاغات والتصاريح تشير إلى أصحابها.
 *
 * **الحارس:** يرفض ما لم يبقَ `system_admin` نشط بعد التعطيل — وإلا أُغلق الباب على الجميع،
 * ولا سطر أوامر على Render لفتحه.
 */
class IpaDemoOff extends Command
{
    protected $signature = 'ipa:demo-off {--dry-run : اعرض ما سيُعطَّل بلا تعطيل}
                                         {--all : عطّل القائمة التجريبية كلها ولو غُيّرت كلماتها}';

    protected $description = 'تعطيل الحسابات الباقية على كلمة المرور المبذورة، بعد التأكد من بقاء مسؤول سلامة نشط.';

    public function handle(CloseoutService $closeout): int
    {
        $all = (bool) $this->option('all');

        $candidates = User::whereIn('username', CloseoutService::DEMO_USERNAMES)->get()
            ->filter(fn (User $u) => $this->isActive($u))
            ->filter(fn (User $u) => $all || $closeout->stillSeeded($u))
            ->values();

        if ($candidates->isEmpty()) {
            $this->info($all
                ? 'لا حساب تجريبي نشط. لا شيء ليُعمل.'
                : 'لا حساب نشط على كلمة المرور المبذورة. لا شيء ليُعمل.');

            return self::SUCCESS;
        }

        $survivor = $this->survivingAdmin($candidates->pluck('id')->all());

        if (!$survivor) {
            $this->error('لا يبقى «مسؤول سلامة» نشط بعد هذا التعطيل.');
            $this->line('أنشئ حساباً حقيقياً وتحقّق من دخوله، ثم أعد هذا الأمر:');
            $this->line('  php artisan ipa:user <اسم-الدخول> system_admin "<الاسم>" --password=<كلمة قوية>');
            $this->line('التعطيل الآن يغلق الباب على الجميع، ولا سطر أوامر على Render لفتحه.');

            return self::FAILURE;
        }

        $this->line('يبقى نشطاً: '.$survivor->user?->username.' — '.$survivor->user?->name);
        $this->line('سيُعطَّل: '.$candidates->pluck('username')->implode('، '));

        if ($this->option('dry-run')) {
            $this->info('عرض فقط — لم يُعطَّل شيء.');

            return self::SUCCESS;
        }

        UserProfile::whereIn('user_id', $candidates->pluck('id'))->update(['is_active' => false]);

        $this->info(sprintf('تم: عُطّل %d حساباً.', $candidates->count()));

        return self::SUCCESS;
    }

    /** مسؤول سلامة نشط لن يشمله التعطيل. */
    private function survivingAdmin(array $aboutToDisable): ?UserProfile
    {
        return UserProfile::where('role', 'system_admin')
            ->where('is_active', true)
            ->whereNotIn('user_id', $aboutToDisable)
            ->with('user:id,username,name')
            ->first();
    }

    private function isActive(User $user): bool
    {
        return (bool) UserProfile::where('user_id', $user->id)->value('is_active');
    }
}
