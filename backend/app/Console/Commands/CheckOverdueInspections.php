<?php

namespace App\Console\Commands;

use App\Modules\Store\Services\InspectionWatch;
use Illuminate\Console\Command;

/**
 * المرحلة ١٤: كل ساعة — مهمة فحص دورية تجاوزت موعدها (فوراً بلا مهلة، جواب المستخدم «أ») تُنبّه مسؤول السلامة والمناوب
 * ومدير المرافق مرة واحدة، والمهام التي لم تُنفَّذ قط في ملخص يومي واحد.
 */
class CheckOverdueInspections extends Command
{
    protected $signature = 'inspections:check-overdue';

    protected $description = 'إشعار مهام الفحص الدورية التي فات موعدها، وملخص يومي لما لم يُنفَّذ قط.';

    public function handle(InspectionWatch $watch): int
    {
        [$sent, $never] = $watch->checkOverdue();
        $this->info(sprintf('فات موعده (إشعارات جديدة): %d · لم تُنفَّذ قط: %d', $sent, $never));
        return self::SUCCESS;
    }
}
