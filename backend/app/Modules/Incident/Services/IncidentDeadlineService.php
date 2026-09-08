<?php

namespace App\Modules\Incident\Services;

use App\Modules\Incident\Models\Incident;
use App\Modules\Incident\Models\IncidentEvent;

/**
 * البند ج (إضافة معهدية فوق OHSMS): المهلة حتى «استلمه الفني». المؤقت في الخادم (أمر مجدول كل دقيقة).
 * بلا قيم افتراضية: إن لم يُدخل المستخدم المهل من شاشة الإعدادات لا يحدث شيء.
 * عند التجاوز: يُسجَّل في الخط الزمني، ويُشعَر مسؤول السلامة والمناوب، ويُصعَّد إلى لجنة السلامة (وحتى تشكيلها مسؤول السلامة).
 */
class IncidentDeadlineService
{
    public function __construct(private IncidentService $service, private \App\Core\Services\NotificationService $notifications) {}

    /** يعيد عدد البلاغات التي سُجّل تجاوزها الآن. */
    public function check(): int
    {
        $n = 0;
        $due = Incident::whereIn('status', Incident::BEFORE_FIELD)->whereNotNull('deadline_at')->whereNull('overdue_at')
            ->where('deadline_at', '<', now())->get();
        foreach ($due as $incident) {
            $incident->overdue_at = now();
            $incident->save();
            IncidentEvent::create(['incident_id' => $incident->id, 'action' => 'overdue', 'from_status' => $incident->status,
                'to_status' => $incident->status, 'note' => 'تجاوز المهلة قبل وصول الفني (المهلة: '.$incident->deadline_at->format('Y-m-d H:i').')']);
            $url = "/app/incidents/{$incident->id}";
            $this->notifications->notifyRoles(['system_admin', 'system_staff'], 'incident.overdue', 'تجاوز مهلة بلاغ شاغل: '.$incident->code,
                $incident->title.' — لم يصل الفني بعد', $url);
            $this->service->notifyCommittee('تصعيد آلي — تجاوز مهلة البلاغ '.$incident->code, $incident->title, $url);
            $n++;
        }
        return $n;
    }
}
