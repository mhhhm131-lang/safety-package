<?php

namespace App\Modules\Emergency\Services;

use App\Core\StateMachine\Exceptions\TransitionException;
use App\Models\User;
use App\Modules\Emergency\Events\EmergencyEnded;
use App\Modules\Emergency\Events\EmergencyTriggered;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyEquipment;
use App\Modules\Emergency\Models\EmergencyEventLog;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Models\EmergencyTeamMember;
use App\Modules\Emergency\Models\EvacuationDrill;
use App\Modules\Emergency\StateMachines\EmergencyStateMachine;
use App\Modules\Governance\Models\Place;
use Illuminate\Support\Facades\DB;

/**
 * خدمة الحالة الطارئة (من OHSMS) مع الإصلاحات المقررة في BACKEND.md ٥-٣:
 * آلة حالة رسمية بدل تعديل الحالة مباشرة، الربط بالمكان (HZ)، تنبيه الفريق الأولي المشتق من ملف المكان عند التفعيل،
 * وتسجيل وصول أعضاء الفريق (بلا حساب) في السجل الزمني.
 */
class EmergencyService
{
    public function __construct(
        protected QrMusteringService $musteringService,
        protected EmergencyNotificationService $notifications,
        protected EmergencyStateMachine $stateMachine,
    ) {}

    /**
     * التفعيل: يبدأ الحالة، يُخلي (حالة المبنى والطوابق)، يولّد رموز الوصول، ويُنبّه الفريق والإدارة وجهات الاتصال.
     * placeId: المكان الذي وقعت فيه الحالة (المعهد) — منه يُشتق الفريق الأولي الذي يُنبَّه.
     */
    public function triggerAlarm(
        EmergencyBuilding $building,
        string $type,
        ?User $triggeredBy,
        string $severity = 'high',
        bool $isDrill = false,
        ?string $description = null,
        ?int $placeId = null,
        ?int $linkedIncidentId = null,
        ?\App\Modules\Integration\Models\IotDevice $device = null,
    ): EmergencyIncident {
        // triggeredBy = null: التفعيل من جهاز (المرحلة ٥) — يُسجَّل باسم الجهاز في السجل الزمني
        $incident = DB::transaction(function () use ($building, $type, $triggeredBy, $severity, $isDrill, $description, $placeId, $linkedIncidentId, $device) {
            $building->update(['emergency_status' => EmergencyBuilding::EMERGENCY_EVACUATING]);

            $incident = EmergencyIncident::create([
                'building_id' => $building->id,
                'place_id' => $placeId,
                'incident_type' => $type,
                'severity' => $severity,
                'status' => EmergencyIncident::STATUS_ACTIVE,
                'is_drill' => $isDrill,
                'triggered_at' => now(),
                'triggered_by_id' => $triggeredBy?->id,
                'description' => $description,
                'linked_incident_id' => $linkedIncidentId,
            ]);

            $place = $placeId ? Place::find($placeId) : null;
            EmergencyEventLog::log(
                $incident,
                EmergencyEventLog::TYPE_ALARM_TRIGGERED,
                ($isDrill ? 'بدء تمرين إخلاء — ' : ($device ? 'إنذار آلي من '.$device->getKindLabel().' «'.$device->name.'» — ' : 'تشغيل إنذار الطوارئ — ')).$incident->getTypeLabel().($place ? ' في '.$place->name : ''),
                ['severity' => $severity, 'place' => $place?->code, 'triggered_by' => $triggeredBy?->name ?? ($device?->name), 'device_id' => $device?->id],
                'critical',
                $triggeredBy?->id
            );

            $this->musteringService->generateQrCodesForIncident($incident);
            $building->floors()->update(['status' => 'evacuating']);

            return $incident;
        });

        // التنبيه خارج المعاملة حتى لا يُلغي فشل مرسل البريد التفعيل نفسه
        $this->notifications->notifyAll($incident);
        broadcast(new EmergencyTriggered($incident));

        return $incident;
    }

    /** الإقرار بالاستلام: أول استجابة بشرية بعد التفعيل (يوقف قاعدة «لا إقرار» في التصعيد الآلي). */
    public function acknowledge(EmergencyIncident $incident, User $by): void
    {
        if ($incident->acknowledged_at) return;
        $incident->update(['acknowledged_at' => now(), 'acknowledged_by_id' => $by->id]);
        EmergencyEventLog::log($incident, EmergencyEventLog::TYPE_STATUS_CHANGE, 'استلم الحالة: '.$by->name, [], 'info', $by->id);
    }

    /** وصول عضو من الفريق الأولي إلى الموقع (بلا حساب — يسجّله المركز أو المنسق). */
    public function teamMemberArrived(EmergencyIncident $incident, EmergencyTeamMember $member, User $by, ?string $note = null): void
    {
        $label = trim(($member->getRoleKeyLabel() ?? $member->getRoleLabel()).' '.$member->displayName());
        EmergencyEventLog::log($incident, EmergencyEventLog::TYPE_TEAM_ARRIVED, 'وصل إلى الموقع: '.$label.($note ? ' — '.$note : ''),
            ['team_member_id' => $member->id, 'team_id' => $member->team_id], 'info', $by->id);
        $this->acknowledge($incident, $by);
    }

    public function contain(EmergencyIncident $incident, User $by, ?string $note = null): EmergencyIncident
    {
        $this->transition($incident, 'contained', $by);
        $incident->update(['contained_at' => now(), 'contained_by_id' => $by->id]);
        $incident->building->update(['emergency_status' => EmergencyBuilding::EMERGENCY_ALERT]);
        EmergencyEventLog::log($incident, EmergencyEventLog::TYPE_CONTAINED, 'تمت السيطرة'.($note ? ' — '.$note : ''), [], 'warning', $by->id);
        return $incident->fresh();
    }

    public function reactivate(EmergencyIncident $incident, User $by, ?string $note = null): EmergencyIncident
    {
        $this->transition($incident, 'active', $by);
        $incident->update(['contained_at' => null, 'contained_by_id' => null]);
        $incident->building->update(['emergency_status' => EmergencyBuilding::EMERGENCY_EVACUATING]);
        EmergencyEventLog::log($incident, EmergencyEventLog::TYPE_STATUS_CHANGE, 'عادت الحالة نشطة'.($note ? ' — '.$note : ''), [], 'critical', $by->id);
        return $incident->fresh();
    }

    /** انتهاء الخطر: يختم الإحصاءات ويعيد المبنى إلى طبيعي ويعلن الأمان. */
    public function endIncident(EmergencyIncident $incident, User $endedBy, ?string $finalReport = null): EmergencyIncident
    {
        $this->transition($incident, 'ended', $endedBy);

        $incident = DB::transaction(function () use ($incident, $endedBy, $finalReport) {
            $stats = $this->musteringService->getLiveStats($incident);
            $incident->update([
                'ended_at' => now(),
                'ended_by_id' => $endedBy->id,
                'evacuation_time_sec' => $incident->getDurationSeconds(),
                'total_evacuees' => $stats['total'],
                'total_safe' => $stats['safe'],
                'total_injured' => $stats['injured'],
                'total_missing' => $stats['missing'],
                'final_report' => $finalReport,
            ]);
            $incident->building->update(['emergency_status' => EmergencyBuilding::EMERGENCY_ALL_CLEAR]);
            $incident->building->floors()->update(['status' => 'normal']);
            EmergencyEventLog::log($incident, EmergencyEventLog::TYPE_ALL_CLEAR, 'انتهى الخطر — إنهاء الحالة', $stats, 'info', $endedBy->id);

            // التمرين المرتبط يُغلق معها
            $drill = EvacuationDrill::where('incident_id', $incident->id)->where('status', 'in_progress')->first();
            if ($drill) {
                app(DrillService::class)->finalize($drill, $incident->fresh());
            }
            return $incident->fresh();
        });

        $this->notifications->notifyAllClear($incident);
        broadcast(new EmergencyEnded($incident));

        return $incident;
    }

    public function cancel(EmergencyIncident $incident, User $by, string $reason): EmergencyIncident
    {
        $this->transition($incident, 'cancelled', $by);
        $incident->update(['cancelled_at' => now(), 'cancelled_by_id' => $by->id, 'cancel_reason' => $reason]);
        $incident->building->update(['emergency_status' => EmergencyBuilding::EMERGENCY_NORMAL]);
        $incident->building->floors()->update(['status' => 'normal']);
        EmergencyEventLog::log($incident, EmergencyEventLog::TYPE_CANCELLED, 'أُلغيت الحالة: '.$reason, [], 'warning', $by->id);
        $this->notifications->notifyCancelled($incident, $reason);
        return $incident->fresh();
    }

    /** الانتقال عبر آلة الحالة بدور المستخدم — يرمي TransitionException إن لم يكن مسموحاً. */
    protected function transition(EmergencyIncident $incident, string $to, User $by): void
    {
        $from = $incident->status;
        if (!$this->stateMachine->canUserTransition($from, $to, $by->role())) {
            throw new TransitionException("لا يملك دورك الانتقال من «{$incident->getStatusLabel()}» إلى «".(EmergencyIncident::STATUS_LABELS[$to] ?? $to).'»');
        }
        $incident->status = $to;
        $incident->save();
        if (!in_array($to, ['ended', 'cancelled', 'contained'], true)) {
            EmergencyEventLog::log($incident, EmergencyEventLog::TYPE_STATUS_CHANGE, "الحالة: {$from} ← {$to}", [], 'info', $by->id);
        }
    }

    public function getBuildingStatus(EmergencyBuilding $building): array
    {
        $activeIncident = $building->getActiveIncident();
        $status = [
            'building' => [
                'id' => $building->id, 'name' => $building->name, 'status' => $building->status,
                'emergency_status' => $building->emergency_status, 'emergency_status_label' => $building->getEmergencyStatusLabel(),
                'risk_level' => $building->risk_level, 'total_capacity' => $building->total_capacity,
                'current_occupants' => $building->current_occupants,
            ],
            'floors' => $building->floors->map(fn ($f) => ['id' => $f->id, 'name' => $f->getDisplayName(), 'status' => $f->status, 'occupants' => $f->current_occupants]),
            'assembly_points' => $building->assemblyPoints->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'code' => $p->code, 'is_primary' => $p->is_primary]),
            'teams_count' => $building->teams()->active()->count(),
            'equipment_count' => $building->equipment()->operational()->count(),
            'lockdown' => $building->activeLockdown()?->only(['id', 'level', 'state', 'initiated_at']),
            'active_incident' => null,
        ];
        if ($activeIncident) {
            $status['active_incident'] = [
                'id' => $activeIncident->id, 'code' => $activeIncident->incident_code, 'type' => $activeIncident->incident_type,
                'type_label' => $activeIncident->getTypeLabel(), 'severity' => $activeIncident->severity, 'status' => $activeIncident->status,
                'is_drill' => $activeIncident->is_drill, 'place' => $activeIncident->place?->code,
                'triggered_at' => $activeIncident->triggered_at->toIso8601String(),
                'duration_seconds' => $activeIncident->getDurationSeconds(),
                'stats' => $this->musteringService->getLiveStats($activeIncident),
            ];
        }
        return $status;
    }

    public function getDashboardStats(): array
    {
        $buildings = EmergencyBuilding::all();
        return [
            'total_buildings' => $buildings->count(),
            'active_buildings' => $buildings->where('status', 'active')->count(),
            'buildings_in_emergency' => $buildings->filter(fn ($b) => $b->isInEmergency())->count(),
            'active_incidents' => EmergencyIncident::open()->count(),
            'upcoming_drills' => EvacuationDrill::where('status', 'scheduled')->whereBetween('scheduled_at', [now(), now()->addDays(30)])->count(),
            'overdue_drills' => EvacuationDrill::where('status', 'scheduled')->where('scheduled_at', '<', now())->count(),
            'equipment_needs_inspection' => EmergencyEquipment::where('next_inspection_date', '<=', now())->count(),
        ];
    }

    public function resetBuilding(EmergencyBuilding $building): void
    {
        $building->update(['emergency_status' => EmergencyBuilding::EMERGENCY_NORMAL]);
        $building->floors()->update(['status' => 'normal']);
    }
}
