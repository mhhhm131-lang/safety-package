<?php

namespace App\Console\Commands;

use App\Modules\Emergency\Services\ResponsePlanSync;
use App\Modules\Governance\Services\SafetyPlanSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * مزامنة خطط الاستجابة الثماني من الوثائق (المرحلة ١٠-١)، وزرع خطط السلامة التسع لكل مكان في كل مبنى (المرحلة ٣٠-١، قرار ٨٩).
 * يعمل عند كل نشر (entrypoint) ومن زر الشاشة.
 */
class IpaSyncPlans extends Command
{
    protected $signature = 'ipa:sync-plans {--from= : مجلد المصدر (افتراضياً المستودع الأعلى ثم public/)} {--force : إعادة القراءة ولو لم تتغير البصمة}';

    protected $description = 'قراءة HZ-0x/response-plan.html واشتقاق خطط الاستجابة وخطواتها وبطاقات أدوارها، وزرع HZ-0x/safety-plan.html صفوفاً لكل مكان';

    public function handle(ResponsePlanSync $sync, SafetyPlanSync $safety): int
    {
        if (!Schema::hasTable('response_plans')) {
            $this->warn('جدول response_plans غير موجود بعد — شغّل الترحيلات أولاً.');
            return self::FAILURE;
        }
        $from = $this->option('from') ?: null;
        $force = (bool) $this->option('force');
        $result = $sync->sync($from, $force);
        $rows = [];
        foreach ($result as $code => $r) {
            $rows[] = [$code, $r['status'], $r['steps'] ?? '—', $r['declared'] ?? '—', $r['no_card'] ?? '—'];
        }
        $this->table(['المكان', 'الحالة', 'خطوات المسارات', 'المعلن في الوثيقة', 'بلا بطاقة'], $rows);

        if (Schema::hasTable('safety_plans')) {
            $rows = [];
            foreach ($safety->sync($from, $force) as $code => $r) {
                $rows[] = [$code, $r['status'], $r['tasks'] ?? '—', $r['edited'] ?? '—'];
            }
            $this->table(['المكان', 'خطة السلامة', 'المهام', 'معدَّلة في المكان'], $rows);
        }
        return self::SUCCESS;
    }
}
