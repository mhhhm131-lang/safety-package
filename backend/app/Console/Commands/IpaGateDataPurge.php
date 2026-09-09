<?php

namespace App\Console\Commands;

use App\Core\Services\CloseoutService;
use Illuminate\Console\Command;

/**
 * حذف بيانات التجربة عند التسليم (المرحلة ٨-١).
 *
 * على Render المجاني لا سطر أوامر، فالنظير العملي شاشة `/app/closeout`.
 * هذا الأمر للتطوير وللاختبارات ولأي بيئة فيها سطر أوامر.
 *
 *   php artisan ipa:gate-data-purge --dry-run
 *   php artisan ipa:gate-data-purge --force
 */
class IpaGateDataPurge extends Command
{
    protected $signature = 'ipa:gate-data-purge {--dry-run : اعرض ما سيُحذف بلا حذف} {--force : نفّذ بلا سؤال}';

    protected $description = 'حذف العمل التشغيلي (بيانات البوابات والتجربة) وإبقاء المرجعي كاملاً.';

    public function handle(CloseoutService $closeout): int
    {
        $unclassified = $closeout->unclassifiedTables();
        if ($unclassified) {
            $this->error('جداول بلا تصنيف في CloseoutService: '.implode('، ', $unclassified));
            $this->line('صنّفها في OPERATIONAL أو REFERENCE قبل الحذف.');

            return self::FAILURE;
        }

        $inventory = $closeout->inventory();
        $this->line('سيُحذف:');
        foreach ($inventory as $label => $n) {
            $this->line(sprintf('  %-40s %d', $label, $n));
        }
        $this->newLine();
        $this->line('سيبقى:');
        foreach ($closeout->preserved() as $label => $n) {
            $this->line(sprintf('  %-40s %d', $label, $n));
        }

        if ($this->option('dry-run')) {
            $this->info('عرض فقط — لم يُحذف شيء.');

            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm('أتأكيد الحذف؟')) {
            $this->warn('أُلغي.');

            return self::SUCCESS;
        }

        $deleted = $closeout->purge();
        $total = array_sum($deleted);
        foreach ($deleted as $table => $n) {
            $this->line(sprintf('  حُذف %-45s %d', $table, $n));
        }
        $this->info(sprintf('تم: %d صفاً من %d جدولاً.', $total, count($deleted)));

        return self::SUCCESS;
    }
}
