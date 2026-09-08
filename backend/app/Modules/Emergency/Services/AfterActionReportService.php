<?php

namespace App\Modules\Emergency\Services;

use App\Modules\Emergency\Models\AarCorrectiveAction;
use App\Modules\Emergency\Models\AfterActionReport;
use App\Modules\Emergency\Models\EmergencyIncident;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AfterActionReportService
{
    public function createFromIncident(EmergencyIncident $incident, array $data = []): AfterActionReport
    {
        return DB::transaction(function () use ($incident, $data) {
            $report = AfterActionReport::create(array_merge([
                'incident_id' => $incident->id,
                'building_id' => $incident->building_id,
                'title' => "تقرير ما بعد الحادث: {$incident->incident_code} — {$incident->getTypeLabel()}",
                'incident_start_at' => $incident->triggered_at,
                'incident_end_at' => $incident->ended_at,
                'incident_description' => $incident->description ?? "حادث طوارئ",
                'prepared_by_id' => auth()->id(),
            ], $data));

            if ($incident->ended_at && $incident->triggered_at) {
                $report->resolution_time_minutes = $incident->triggered_at->diffInMinutes($incident->ended_at);
            }

            $report->total_occupants = $incident->building?->current_occupants ?? 0;
            $report->evacuated_count = $incident->checkIns()->where('status', 'safe')->count();
            $report->save();

            Log::info("After-action report created", ['report_id' => $report->id, 'incident_id' => $incident->id]);

            return $report;
        });
    }

    public function updateFindings(AfterActionReport $report, array $data): AfterActionReport
    {
        $report->update($data);

        if (isset($data['notification_effectiveness_score']) ||
            isset($data['evacuation_effectiveness_score']) ||
            isset($data['communication_effectiveness_score']) ||
            isset($data['leadership_effectiveness_score']) ||
            isset($data['equipment_effectiveness_score'])) {
            $report->overall_score = $report->calculateOverallScore();
            $report->save();
        }

        return $report->fresh();
    }

    public function addCorrectiveAction(AfterActionReport $report, array $data): AarCorrectiveAction
    {
        return $report->correctiveActions()->create($data);
    }

    public function updateCorrectiveAction(AarCorrectiveAction $action, array $data): AarCorrectiveAction
    {
        $action->update($data);
        return $action->fresh();
    }

    public function submitForReview(AfterActionReport $report): void
    {
        $report->submitForReview();
        Log::info("After-action report submitted for review", ['report_id' => $report->id]);
    }

    public function approve(AfterActionReport $report, int $approverId, ?string $comments = null): void
    {
        $report->approve($approverId, $comments);
        Log::info("After-action report approved", ['report_id' => $report->id, 'approved_by' => $approverId]);
    }

    public function publish(AfterActionReport $report): void
    {
        $report->publish();
        Log::info("After-action report published", ['report_id' => $report->id]);
    }

    public function getByStatus(string $status): Collection
    {
        return AfterActionReport::query()
            ->where('status', $status)
            ->with(['incident', 'building', 'preparedBy'])
            ->orderByDesc('incident_start_at')
            ->get();
    }

    public function getAll(array $filters = []): Collection
    {
        $query = AfterActionReport::query()->with(['incident', 'building', 'preparedBy']);

        if (!empty($filters['status'])) $query->where('status', $filters['status']);
        if (!empty($filters['severity'])) $query->where('severity', $filters['severity']);
        if (!empty($filters['building_id'])) $query->where('building_id', $filters['building_id']);
        if (!empty($filters['from_date'])) $query->where('incident_start_at', '>=', $filters['from_date']);
        if (!empty($filters['to_date'])) $query->where('incident_start_at', '<=', $filters['to_date']);

        return $query->orderByDesc('incident_start_at')->get();
    }

    public function getOpenCorrectiveActions(): Collection
    {
        return AarCorrectiveAction::query()
            ->open()
            ->with(['report', 'assignedTo'])
            ->orderBy('due_date')
            ->get();
    }

    public function getOverdueActions(): Collection
    {
        return AarCorrectiveAction::query()
            ->overdue()
            ->with(['report', 'assignedTo'])
            ->orderBy('due_date')
            ->get();
    }

    public function getStats(?string $period = 'year'): array
    {
        $query = AfterActionReport::query();

        $query = match($period) {
            'month' => $query->where('incident_start_at', '>=', now()->startOfMonth()),
            'quarter' => $query->where('incident_start_at', '>=', now()->startOfQuarter()),
            'year' => $query->where('incident_start_at', '>=', now()->startOfYear()),
            default => $query->where('incident_start_at', '>=', now()->startOfYear()),
        };

        $reports = $query->get();
        $actionStats = AarCorrectiveAction::all();

        return [
            'total_reports' => $reports->count(),
            'by_status' => [
                'draft' => $reports->where('status', 'draft')->count(),
                'under_review' => $reports->where('status', 'under_review')->count(),
                'approved' => $reports->where('status', 'approved')->count(),
                'published' => $reports->where('status', 'published')->count(),
            ],
            'by_severity' => [
                'minor' => $reports->where('severity', 'minor')->count(),
                'moderate' => $reports->where('severity', 'moderate')->count(),
                'major' => $reports->where('severity', 'major')->count(),
                'critical' => $reports->where('severity', 'critical')->count(),
            ],
            'average_score' => round($reports->avg('overall_score') ?? 0, 1),
            'average_response_time' => round($reports->avg('response_time_minutes') ?? 0),
            'average_evacuation_time' => round($reports->avg('evacuation_time_minutes') ?? 0),
            'total_injuries' => $reports->sum('injuries_count'),
            'total_fatalities' => $reports->sum('fatalities_count'),
            'osha_reportable' => $reports->where('osha_reportable', true)->count(),
            'corrective_actions' => [
                'total' => $actionStats->count(),
                'open' => $actionStats->whereIn('status', ['open', 'in_progress'])->count(),
                'completed' => $actionStats->where('status', 'completed')->count(),
            ],
        ];
    }

    public function getTrendData(int $months = 12): array
    {
        $trends = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $reports = AfterActionReport::query()
                ->whereYear('incident_start_at', $month->year)
                ->whereMonth('incident_start_at', $month->month)
                ->get();

            $trends[] = [
                'month' => $month->format('Y-m'),
                'incidents' => $reports->count(),
                'average_score' => round($reports->avg('overall_score') ?? 0, 1),
                'injuries' => $reports->sum('injuries_count'),
            ];
        }
        return $trends;
    }

    public function getCommonFindings(string $type = 'lessons_learned'): array
    {
        $reports = AfterActionReport::query()->approved()->whereNotNull($type)->get();

        $findings = [];
        foreach ($reports as $report) {
            $items = $report->$type ?? [];
            foreach ($items as $item) {
                $findings[$item] = ($findings[$item] ?? 0) + 1;
            }
        }

        arsort($findings);
        return array_slice($findings, 0, 10, true);
    }
}
