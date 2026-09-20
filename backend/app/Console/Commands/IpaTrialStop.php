<?php

namespace App\Console\Commands;

use App\Core\Trial\TrialMode;
use Illuminate\Console\Command;

/**
 * المرحلة ٢١-٢ (قرار ٥٤): ينهي «وضع التجربة» — يحذف كل ما أُنشئ منذ تشغيله ويعيد ملفات المعهد والإعدادات.
 *
 *   php artisan ipa:trial-stop --dry-run
 *   php artisan ipa:trial-stop --force
 */
class IpaTrialStop extends Command
{
    protected $signature = 'ipa:trial-stop {--dry-run : اعرض ما سيُحذف بلا حذف} {--force : نفّذ بلا سؤال}';

    protected $description = 'ينهي وضع التجربة: يحذف ما أُنشئ أثناءه فقط، وما قبله لا يُمس.';

    public function handle(TrialMode $mode): int
    {
        $inventory = $mode->inventory();
        $this->line('أُنشئ منذ تشغيل التجربة:');
        foreach ($inventory as $table => $n) $this->line(sprintf('  %-45s %d', $table, $n));
        if (!$inventory) $this->line('  لا شيء.');

        if ($this->option('dry-run')) {
            $this->info('عرض فقط — لم يُحذف شيء.');
            return self::SUCCESS;
        }
        if (!$this->option('force') && !$this->confirm('أتأكيد حذف بيانات التجربة؟')) {
            $this->warn('أُلغي.');
            return self::SUCCESS;
        }
        try {
            $r = $mode->stop();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
        $this->info(sprintf('تم: حُذف %d صفاً من %d جدولاً، وأُعيدت ملفات المعهد والإعدادات.', array_sum($r['deleted']), count($r['deleted'])));
        foreach ($r['left'] as $table => $n) $this->error("بقي في $table: $n صفاً لم يُحذف (مفتاح أجنبي من صف قديم).");
        return $r['left'] ? self::FAILURE : self::SUCCESS;
    }
}
