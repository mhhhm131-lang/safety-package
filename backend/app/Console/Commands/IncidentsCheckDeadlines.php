<?php

namespace App\Console\Commands;

use App\Modules\Incident\Services\IncidentDeadlineService;
use Illuminate\Console\Command;

/** مجدول كل دقيقة (routes/console.php). البند ج في BACKEND.md ٥-٢-ب. */
class IncidentsCheckDeadlines extends Command
{
    protected $signature = 'incidents:check-deadlines';
    protected $description = 'يسجّل تجاوز مهلة بلاغات الشاغلين التي لم تصل الفني ويُشعر ويُصعّد';

    public function handle(IncidentDeadlineService $service): int
    {
        $n = $service->check();
        $this->info("overdue marked: $n");
        return self::SUCCESS;
    }
}
