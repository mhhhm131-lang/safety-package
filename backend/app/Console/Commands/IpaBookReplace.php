<?php

namespace App\Console\Commands;

use App\Core\Services\CloseoutService;
use Illuminate\Console\Command;

/**
 * استبدال كتاب المعهد (المرحلة ٩، قرار ٢٢) — النظير السطري لزر شاشة الإغلاق.
 * يُرفض ما دام هناك عمل تشغيلي مربوط بالمخاطر (مخاطر فعلية، بلاغات، تصاريح، نماذج).
 */
class IpaBookReplace extends Command
{
    protected $signature = 'ipa:book-replace {--dry-run : اعرض الحال وما يمنع الاستبدال بلا تنفيذ}';

    protected $description = 'استبدال شجرة المخاطر كلها بكتاب المعهد (institute_risk_book.json) مع بنود التحكم وقواعد التصاريح.';

    public function handle(CloseoutService $closeout): int
    {
        foreach ($closeout->bookStatus() as $k => $v) {
            $this->line("  $k: $v");
        }
        if ($blockers = $closeout->bookReplaceBlockers()) {
            $this->error('يمنع الاستبدال: '.implode('، ', array_map(fn ($k, $v) => "$k ($v)", array_keys($blockers), $blockers)));
            return self::FAILURE;
        }
        if ($this->option('dry-run')) {
            $this->info('لا مانع من الاستبدال (dry-run).');
            return self::SUCCESS;
        }
        $status = $closeout->replaceBook();
        $this->info(sprintf('استُبدل الكتاب: %d أصناف، %d فرعاً، %d خطراً.', $status['الأصناف الرئيسية'], $status['الفروع'], $status['مخاطر السجل العام']));
        return self::SUCCESS;
    }
}
