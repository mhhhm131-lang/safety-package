<?php

namespace App\Modules\Emergency\Services;

use App\Models\User;
use App\Modules\Emergency\Models\DrillParticipant;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Models\EvacuationDrill;
use Illuminate\Support\Collection;

/**
 * التمارين (من OHSMS؛ الخدمة لم تكن موصولة بأي متحكم هناك — هنا موصولة بشاشة التمارين).
 * البدء يفعّل حالة طارئة معلَّمة تمريناً (is_drill) فتسير بالمسار نفسه: تنبيه ← وصول ← انتهاء ← تقرير.
 * النتيجة تُقاس على المخطط الفعلي: pass / needs_improvement / fail (قيم OHSMS في الجدول).
 */
class DrillService
{
    public function __construct(protected EmergencyService $emergencyService, protected QrMusteringService $musteringService) {}

    public function scheduleDrill(EmergencyBuilding $building, string $drillType, \DateTimeInterface $scheduledAt, ?string $scenario = null,
        ?string $objectives = null, ?int $expectedParticipants = null, ?int $targetTimeSec = null, ?int $placeId = null): EvacuationDrill
    {
        return EvacuationDrill::create([
            'building_id' => $building->id, 'place_id' => $placeId, 'drill_type' => $drillType, 'scheduled_at' => $scheduledAt,
            'scenario' => $scenario, 'objectives' => $objectives, 'expected_participants' => $expectedParticipants,
            'target_time_sec' => $targetTimeSec, 'status' => 'scheduled',
        ]);
    }

    public function startDrill(EvacuationDrill $drill, User $conductedBy): EmergencyIncident
    {
        if ($drill->status !== 'scheduled') {
            throw new \RuntimeException('التمرين ليس مجدولاً');
        }
        $drill->update(['status' => 'in_progress', 'started_at' => now(), 'conducted_by_id' => $conductedBy->id]);

        $incident = $this->emergencyService->triggerAlarm(
            $drill->building, $this->mapDrillTypeToIncidentType($drill->drill_type), $conductedBy, 'medium', true,
            $drill->scenario, $drill->place_id,
        );
        $drill->update(['incident_id' => $incident->id]);
        return $incident;
    }

    /** إنهاء التمرين من شاشة التمارين: يُنهي الحالة المرتبطة ثم يحسب النتيجة. */
    public function endDrill(EvacuationDrill $drill, User $by, ?string $observations = null, ?string $improvements = null): EvacuationDrill
    {
        $drill->update(['observations' => $observations, 'improvements' => $improvements]);
        if ($drill->incident && $drill->incident->isOpen()) {
            // endIncident يستدعي finalize عبر الحالة المرتبطة
            $this->emergencyService->endIncident($drill->incident, $by, 'انتهاء التمرين: '.($drill->scenario ?? $drill->drill_code));
        } else {
            $this->finalize($drill, $drill->incident);
        }
        return $drill->fresh();
    }

    /** ختم التمرين بعد انتهاء حالته (يُستدعى من EmergencyService::endIncident أو endDrill). */
    public function finalize(EvacuationDrill $drill, ?EmergencyIncident $incident): void
    {
        $ended = $incident?->ended_at ?? now();
        $drill->update(['ended_at' => $ended, 'status' => 'completed']);
        if (!$incident) return;

        $stats = $this->musteringService->getLiveStats($incident);
        $evacuationTimeSec = (int) abs($ended->diffInSeconds($drill->started_at ?? $incident->triggered_at));
        $actualParticipants = $stats['total'];
        $score = $this->calculateScore($evacuationTimeSec, $drill->target_time_sec, $stats['safe'], $actualParticipants);
        $result = match (true) { $score >= 75 => 'pass', $score >= 40 => 'needs_improvement', default => 'fail' };

        $drill->update([
            'evacuation_time_sec' => $evacuationTimeSec, 'actual_participants' => $actualParticipants,
            'score' => $score, 'result' => $result,
        ]);
    }

    /** الدرجة (٠–١٠٠): ٤٠٪ الزمن مقابل المستهدف + ٦٠٪ نسبة من وصلوا بأمان (معادلة OHSMS). بلا مستهدف: النسبة وحدها. */
    protected function calculateScore(int $actualTime, ?int $targetTime, int $safeCount, int $totalCount): int
    {
        if ($totalCount === 0) return 0;
        $evacuationRate = ($safeCount / $totalCount) * 100;
        if (!$targetTime) return (int) round($evacuationRate);
        $timeScore = min(100, ($targetTime / max(1, $actualTime)) * 100);
        return (int) round(($timeScore * 0.4) + ($evacuationRate * 0.6));
    }

    protected function mapDrillTypeToIncidentType(string $drillType): string
    {
        return match ($drillType) { 'fire' => 'fire', 'earthquake' => 'earthquake', 'chemical' => 'chemical_spill', default => 'evacuation' };
    }

    public function addParticipant(EvacuationDrill $drill, ?int $userId = null, ?string $visitorName = null, ?string $visitorPhone = null, string $role = 'participant'): DrillParticipant
    {
        return DrillParticipant::create([
            'drill_id' => $drill->id, 'user_id' => $userId, 'visitor_name' => $visitorName, 'visitor_phone' => $visitorPhone,
            'role' => $role, 'status' => 'registered',
        ]);
    }

    public function markParticipantEvacuated(DrillParticipant $participant, ?int $evacuationTimeSec = null): void
    {
        $participant->update(['status' => 'evacuated', 'evacuated_at' => now(), 'evacuation_time_sec' => $evacuationTimeSec]);
    }

    public function getDrillStats(?int $buildingId = null, ?int $placeId = null): array
    {
        $drills = EvacuationDrill::where('status', 'completed')
            ->when($buildingId, fn ($q) => $q->where('building_id', $buildingId))
            ->when($placeId, fn ($q) => $q->where('place_id', $placeId))->get();
        if ($drills->isEmpty()) {
            return ['total_drills' => 0, 'avg_score' => 0, 'avg_evacuation_time' => 0, 'best_score' => 0, 'results_breakdown' => []];
        }
        return [
            'total_drills' => $drills->count(),
            'avg_score' => round((float) $drills->avg('score'), 1),
            'avg_evacuation_time' => (int) round((float) $drills->avg('evacuation_time_sec')),
            'best_score' => (int) $drills->max('score'),
            'results_breakdown' => $drills->groupBy('result')->map->count()->all(),
        ];
    }

    public function getUpcomingDrills(int $limit = 5): Collection
    {
        return EvacuationDrill::where('status', 'scheduled')->where('scheduled_at', '>=', now())->orderBy('scheduled_at')->limit($limit)->with('building', 'place')->get();
    }

    public function cancelDrill(EvacuationDrill $drill, ?string $reason = null): void
    {
        if ($drill->status !== 'scheduled') {
            throw new \RuntimeException('لا يمكن إلغاء التمرين بعد بدئه');
        }
        $drill->update(['status' => 'cancelled', 'observations' => $reason]);
    }
}
