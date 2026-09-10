<?php

namespace App\Console\Commands;

use App\Modules\Emergency\Services\ResponsePlanSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * مزامنة خطط الاستجابة الثماني من الوثائق (المرحلة ١٠-١). يعمل عند كل نشر (entrypoint) ومن زر الشاشة.
 */
class IpaSyncPlans extends Command
{
    protected $signature = 'ipa:sync-plans {--from= : مجلد المصدر (افتراضياً المستودع الأعلى ثم public/)} {--force : إعادة القراءة ولو لم تتغير البصمة}';

    protected $description = 'قراءة HZ-0x/response-plan.html واشتقاق خطط الاستجابة وخطواتها وبطاقات أدوارها';

    public function handle(ResponsePlanSync $sync): int
    {
        if (!Schema::hasTable('response_plans')) {
            $this->warn('جدول response_plans غير موجود بعد — شغّل الترحيلات أولاً.');
            return self::FAILURE;
        }
        $result = $sync->sync($this->option('from') ?: null, (bool) $this->option('force'));
        $rows = [];
        foreach ($result as $code => $r) {
            $rows[] = [$code, $r['status'], $r['steps'] ?? '—', $r['declared'] ?? '—', $r['no_card'] ?? '—'];
        }
        $this->table(['المكان', 'الحالة', 'خطوات المسارات', 'المعلن في الوثيقة', 'بلا بطاقة'], $rows);
        return self::SUCCESS;
    }
}
