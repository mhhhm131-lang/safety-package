<?php

namespace App\Modules\Incident\Services;

use App\Core\Services\AuditLogService;
use App\Modules\Incident\Models\Incident;
use App\Modules\Incident\Models\IncidentEvent;
use App\Modules\Risk\Models\Risk;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * بوابتا الإغلاق (من OHSMS): موافقة المبلّغ المعروف (عادي/عاجل بحساب)، أو تحقق شخص غير المنفّذ (سري أو بلا حساب).
 * الإغلاق النهائي بيد مسؤول السلامة أو المناوب. عند الإغلاق يُحدَّث عدّاد الحوادث على الخطر (كان مستمعاً لحدث في OHSMS).
 */
class IncidentClosureService
{
    public function __construct(private IncidentService $incidentService, private AuditLogService $auditLogService) {}

    public function close(Incident $incident, int $userId): Incident
    {
        $needsReporterApproval = $incident->actor_id !== null && in_array($incident->incident_type, ['normal', 'urgent'], true);

        if ($needsReporterApproval && !$incident->reporter_approved_closure) {
            if (!$incident->pending_closure) {
                DB::transaction(function () use ($incident, $userId) {
                    $incident->pending_closure = true;
                    $incident->closure_requested_at = now();
                    $incident->save();
                    IncidentEvent::create(['incident_id' => $incident->id, 'action' => 'request_closure', 'from_status' => $incident->status,
                        'to_status' => $incident->status, 'note' => 'بانتظار موافقة المُبلِّغ على الإغلاق', 'actor_id' => $userId]);
                });
                $this->incidentService->notifyUser($incident->actor_id, 'incident.closure',
                    'طلب موافقتك على إغلاق بلاغك '.$incident->code, 'عولج البلاغ ويحتاج موافقتك لإغلاقه.', "/app/incidents/{$incident->id}");
            }
            throw new InvalidArgumentException('لا يمكن الإغلاق قبل موافقة المُبلِّغ. تم إرسال طلب الموافقة.');
        }

        if (!$needsReporterApproval && !$incident->coord_verified_at) {
            throw new InvalidArgumentException('لا يمكن الإغلاق قبل تحقق شخص غير المنفّذ من المعالجة ميدانياً.');
        }

        return DB::transaction(function () use ($incident, $userId) {
            $incident = $this->incidentService->transition($incident, $userId, 'close', 'closed');
            $incident->handled_at = now();
            $incident->save();
            $this->bumpRiskCounters($incident);
            return $incident;
        });
    }

    /** المعهد: «وصل المركز → مغلق بملاحظة (لا يحتاج فنياً)» — مسؤول السلامة أو المناوب، الملاحظة إلزامية. */
    public function closeWithNote(Incident $incident, int $userId, string $note): Incident
    {
        $note = trim($note);
        if (mb_strlen($note) < 5) {
            throw new InvalidArgumentException('اكتب سبب الإغلاق (٥ أحرف على الأقل) — يُقيَّد في الخط الزمني.');
        }
        if ($incident->status !== 'received') {
            throw new InvalidArgumentException('الإغلاق بملاحظة متاح فقط لبلاغ وصل المركز ولم يُحَل بعد.');
        }
        return DB::transaction(function () use ($incident, $userId, $note) {
            $incident->resolution_summary = $note;
            $incident->save();
            $incident = $this->incidentService->transition($incident, $userId, 'close_with_note', 'closed', $note);
            $incident->handled_at = now();
            $incident->save();
            $this->bumpRiskCounters($incident);
            return $incident;
        });
    }

    public function approveClosure(Incident $incident, int $userId): Incident
    {
        if ($incident->actor_id === null) {
            throw new InvalidArgumentException('لا يمكن اعتماد الإغلاق على بلاغ بدون مُبلِّغ معروف — يتطلب تحقق شخص غير المنفّذ بدلاً من ذلك.');
        }
        if ($incident->actor_id !== $userId) {
            throw new InvalidArgumentException('فقط المُبلِّغ الأصلي يستطيع اعتماد إغلاق بلاغه.');
        }
        if (!$incident->pending_closure) {
            throw new InvalidArgumentException('لا يوجد طلب إغلاق قيد الانتظار على هذا البلاغ.');
        }
        DB::transaction(function () use ($incident, $userId) {
            $incident->reporter_approved_closure = true;
            $incident->pending_closure = false;
            $incident->save();
            IncidentEvent::create(['incident_id' => $incident->id, 'action' => 'reporter_approved', 'from_status' => $incident->status,
                'to_status' => $incident->status, 'note' => 'وافق المُبلِّغ على الإغلاق', 'actor_id' => $userId]);
        });
        $this->auditLogService->log(null, 'approve_closure', 'Incident', $incident->id, "Reporter approved closure of incident {$incident->code}", $userId);
        return $incident;
    }

    public function rejectClosure(Incident $incident, int $userId, string $note): Incident
    {
        $incident->pending_closure = false;
        $incident->save();
        return $this->incidentService->transition($incident, $userId, 'reject_closure', 'in_progress', $note);
    }

    /** تحقق ميداني من شخص غير الذي علّم «عولج» (عينان مستقلتان). */
    public function verifyByCoordinator(Incident $incident, int $userId): Incident
    {
        if ($incident->status !== 'resolved') {
            throw new InvalidArgumentException("التحقق الميداني متاح فقط للبلاغات في حالة «عولج». الحالة الحالية: {$incident->status_label}.");
        }
        $resolveEvent = IncidentEvent::where('incident_id', $incident->id)->where('action', 'resolve')->latest('id')->first();
        if ($resolveEvent && $resolveEvent->actor_id === $userId) {
            throw new InvalidArgumentException('لا يمكنك التحقق من بلاغ قمت أنت بحلّه — يجب أن يتحقق شخص آخر (عينان مستقلتان).');
        }
        DB::transaction(function () use ($incident, $userId) {
            $incident->coord_verified_at = now();
            $incident->coord_verified_by_id = $userId;
            $incident->save();
            IncidentEvent::create(['incident_id' => $incident->id, 'action' => 'verify', 'from_status' => $incident->status,
                'to_status' => $incident->status, 'note' => 'تم التحقق ميدانياً من شخص غير المنفّذ', 'actor_id' => $userId]);
        });
        $this->auditLogService->log(null, 'verify', 'Incident', $incident->id, "Verified field resolution of incident {$incident->code}", $userId);
        return $incident;
    }

    private function bumpRiskCounters(Incident $incident): void
    {
        foreach (array_unique(array_filter([$incident->risk_id, $incident->risk_reference_id])) as $riskId) {
            Risk::where('id', $riskId)->update(['last_incident_at' => now(), 'incident_count' => DB::raw('incident_count + 1')]);
        }
    }
}
