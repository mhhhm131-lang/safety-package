<?php

namespace App\Console\Commands;

use App\Modules\Emergency\Services\AutoEscalationService;
use Illuminate\Console\Command;

/** التصعيد الآلي للحالات الطارئة النشطة — يعمل كل دقيقة من المجدول (كان غير مجدول في OHSMS، الإصلاح المقرر ٥-٣). */
class EmergencyCheckEscalation extends Command
{
    protected $signature = 'emergency:check-escalation';

    protected $description = 'فحص الحالات الطارئة النشطة وتصعيدها آلياً بحسب مهل الإعدادات';

    public function handle(AutoEscalationService $service): int
    {
        $result = $service->processScheduledChecks();
        $escalated = collect($result['results'])->filter(fn ($r) => !empty($r['escalations']))->count();
        $this->info("فُحصت {$result['incidents_checked']} حالة، صُعّدت {$escalated}");
        return self::SUCCESS;
    }
}
