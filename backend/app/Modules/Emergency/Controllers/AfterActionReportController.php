<?php

namespace App\Modules\Emergency\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Emergency\Models\AarCorrectiveAction;
use App\Modules\Emergency\Models\AfterActionReport;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Services\AfterActionReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AfterActionReportController extends Controller
{
    public function __construct(protected AfterActionReportService $aarService) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'severity', 'building_id', 'from_date', 'to_date']);
        $reports = $this->aarService->getAll($filters);

        return response()->json([
            'success' => true,
            'data' => $reports->map(fn($r) => $this->formatReport($r)),
            'count' => $reports->count(),
        ]);
    }

    public function show(AfterActionReport $report): JsonResponse
    {
        $report->load(['incident', 'building', 'preparedBy', 'reviewedBy', 'approvedBy', 'correctiveActions.assignedTo']);

        return response()->json([
            'success' => true,
            'data' => $this->formatReport($report, true),
        ]);
    }

    public function createFromIncident(Request $request, EmergencyIncident $incident): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:200',
            'severity' => 'nullable|in:minor,moderate,major,critical',
        ]);

        $report = $this->aarService->createFromIncident($incident, $validated);

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء تقرير ما بعد الحادث',
            'data' => $this->formatReport($report),
        ], 201);
    }

    public function update(Request $request, AfterActionReport $report): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:200',
            'severity' => 'nullable|in:minor,moderate,major,critical',
            'response_time_minutes' => 'nullable|integer|min:0',
            'evacuation_time_minutes' => 'nullable|integer|min:0',
            'total_occupants' => 'nullable|integer|min:0',
            'evacuated_count' => 'nullable|integer|min:0',
            'injuries_count' => 'nullable|integer|min:0',
            'fatalities_count' => 'nullable|integer|min:0',
            'missing_count' => 'nullable|integer|min:0',
            'property_damage_estimate' => 'nullable|integer|min:0',
            'incident_description' => 'nullable|string|max:5000',
            'root_cause_analysis' => 'nullable|string|max:5000',
            'chronology' => 'nullable|array',
            'immediate_actions_taken' => 'nullable|string|max:5000',
            'notification_effectiveness_score' => 'nullable|integer|min:1|max:5',
            'evacuation_effectiveness_score' => 'nullable|integer|min:1|max:5',
            'communication_effectiveness_score' => 'nullable|integer|min:1|max:5',
            'leadership_effectiveness_score' => 'nullable|integer|min:1|max:5',
            'equipment_effectiveness_score' => 'nullable|integer|min:1|max:5',
            'what_went_well' => 'nullable|array',
            'what_went_wrong' => 'nullable|array',
            'lessons_learned' => 'nullable|array',
            'recommendations' => 'nullable|array',
            'osha_reportable' => 'nullable|boolean',
            'regulatory_notification_required' => 'nullable|boolean',
        ]);

        $report = $this->aarService->updateFindings($report, $validated);

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث التقرير',
            'data' => $this->formatReport($report),
        ]);
    }

    public function submitForReview(AfterActionReport $report): JsonResponse
    {
        if (!$report->isDraft()) {
            return response()->json(['success' => false, 'message' => 'التقرير ليس مسودة'], 422);
        }

        $this->aarService->submitForReview($report);

        return response()->json(['success' => true, 'message' => 'تم إرسال التقرير للمراجعة']);
    }

    public function approve(Request $request, AfterActionReport $report): JsonResponse
    {
        $validated = $request->validate(['comments' => 'nullable|string|max:1000']);

        $this->aarService->approve($report, auth()->id(), $validated['comments'] ?? null);

        return response()->json(['success' => true, 'message' => 'تم اعتماد التقرير']);
    }

    public function publish(AfterActionReport $report): JsonResponse
    {
        if (!$report->isApproved()) {
            return response()->json(['success' => false, 'message' => 'يجب اعتماد التقرير أولاً'], 422);
        }

        $this->aarService->publish($report);

        return response()->json(['success' => true, 'message' => 'تم نشر التقرير']);
    }

    public function addCorrectiveAction(Request $request, AfterActionReport $report): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:200',
            'description' => 'required|string|max:2000',
            'priority' => 'nullable|in:low,medium,high,critical',
            'category' => 'nullable|in:training,equipment,procedure,communication,infrastructure,staffing,documentation,other',
            'assigned_to_id' => 'nullable|exists:users,id',
            'due_date' => 'nullable|date|after:today',
            'estimated_cost' => 'nullable|integer|min:0',
        ]);

        $action = $this->aarService->addCorrectiveAction($report, $validated);

        return response()->json([
            'success' => true,
            'message' => 'تم إضافة الإجراء التصحيحي',
            'data' => $this->formatAction($action),
        ], 201);
    }

    public function updateCorrectiveAction(Request $request, AarCorrectiveAction $action): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:200',
            'description' => 'nullable|string|max:2000',
            'priority' => 'nullable|in:low,medium,high,critical',
            'category' => 'nullable|in:training,equipment,procedure,communication,infrastructure,staffing,documentation,other',
            'status' => 'nullable|in:open,in_progress,completed,cancelled',
            'assigned_to_id' => 'nullable|exists:users,id',
            'due_date' => 'nullable|date',
            'completion_notes' => 'nullable|string|max:1000',
            'actual_cost' => 'nullable|integer|min:0',
        ]);

        $action = $this->aarService->updateCorrectiveAction($action, $validated);

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث الإجراء',
            'data' => $this->formatAction($action),
        ]);
    }

    public function completeAction(Request $request, AarCorrectiveAction $action): JsonResponse
    {
        $validated = $request->validate([
            'completion_notes' => 'nullable|string|max:1000',
            'actual_cost' => 'nullable|integer|min:0',
        ]);

        $action->complete($validated['completion_notes'] ?? null, $validated['actual_cost'] ?? null);

        return response()->json(['success' => true, 'message' => 'تم إكمال الإجراء']);
    }

    public function openActions(): JsonResponse
    {
        $actions = $this->aarService->getOpenCorrectiveActions();

        return response()->json([
            'success' => true,
            'data' => $actions->map(fn($a) => $this->formatAction($a)),
            'count' => $actions->count(),
        ]);
    }

    public function overdueActions(): JsonResponse
    {
        $actions = $this->aarService->getOverdueActions();

        return response()->json([
            'success' => true,
            'data' => $actions->map(fn($a) => $this->formatAction($a)),
            'count' => $actions->count(),
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $period = $request->get('period', 'year');
        $stats = $this->aarService->getStats($period);

        return response()->json(['success' => true, 'data' => $stats, 'period' => $period]);
    }

    public function trends(): JsonResponse
    {
        $trends = $this->aarService->getTrendData();

        return response()->json(['success' => true, 'data' => $trends]);
    }

    protected function formatReport(AfterActionReport $report, bool $detailed = false): array
    {
        $data = [
            'id' => $report->id,
            'report_number' => $report->report_number,
            'title' => $report->title,
            'status' => $report->status,
            'status_label' => $report->getStatusLabel(),
            'status_color' => $report->getStatusColor(),
            'severity' => $report->severity,
            'severity_label' => $report->getSeverityLabel(),
            'severity_color' => $report->getSeverityColor(),
            'incident_start_at' => $report->incident_start_at?->toIso8601String(),
            'incident_end_at' => $report->incident_end_at?->toIso8601String(),
            'overall_score' => $report->overall_score,
            'overall_score_label' => $report->overall_score ? $report->getScoreLabel($report->overall_score) : null,
            'building' => $report->building ? ['id' => $report->building_id, 'name' => $report->building->name] : null,
            'incident' => $report->incident ? ['id' => $report->incident_id, 'title' => $report->incident->title] : null,
            'prepared_by' => $report->preparedBy?->name,
            'created_at' => $report->created_at->toIso8601String(),
        ];

        if ($detailed) {
            $data = array_merge($data, [
                'response_time_minutes' => $report->response_time_minutes,
                'evacuation_time_minutes' => $report->evacuation_time_minutes,
                'resolution_time_minutes' => $report->resolution_time_minutes,
                'total_occupants' => $report->total_occupants,
                'evacuated_count' => $report->evacuated_count,
                'evacuation_rate' => $report->getEvacuationRate(),
                'injuries_count' => $report->injuries_count,
                'fatalities_count' => $report->fatalities_count,
                'missing_count' => $report->missing_count,
                'property_damage_estimate' => $report->property_damage_estimate,
                'incident_description' => $report->incident_description,
                'root_cause_analysis' => $report->root_cause_analysis,
                'chronology' => $report->chronology,
                'immediate_actions_taken' => $report->immediate_actions_taken,
                'scores' => [
                    'notification' => $report->notification_effectiveness_score,
                    'evacuation' => $report->evacuation_effectiveness_score,
                    'communication' => $report->communication_effectiveness_score,
                    'leadership' => $report->leadership_effectiveness_score,
                    'equipment' => $report->equipment_effectiveness_score,
                ],
                'what_went_well' => $report->what_went_well,
                'what_went_wrong' => $report->what_went_wrong,
                'lessons_learned' => $report->lessons_learned,
                'recommendations' => $report->recommendations,
                'osha_reportable' => $report->osha_reportable,
                'regulatory_notification_required' => $report->regulatory_notification_required,
                'regulatory_notification_sent' => $report->regulatory_notification_sent,
                'corrective_actions' => $report->correctiveActions->map(fn($a) => $this->formatAction($a)),
                'reviewed_by' => $report->reviewedBy?->name,
                'reviewed_at' => $report->reviewed_at?->toIso8601String(),
                'approved_by' => $report->approvedBy?->name,
                'approved_at' => $report->approved_at?->toIso8601String(),
                'review_comments' => $report->review_comments,
            ]);
        }

        return $data;
    }

    protected function formatAction(AarCorrectiveAction $action): array
    {
        return [
            'id' => $action->id,
            'title' => $action->title,
            'description' => $action->description,
            'priority' => $action->priority,
            'priority_label' => $action->getPriorityLabel(),
            'priority_color' => $action->getPriorityColor(),
            'category' => $action->category,
            'category_label' => $action->getCategoryLabel(),
            'status' => $action->status,
            'status_label' => $action->getStatusLabel(),
            'status_color' => $action->getStatusColor(),
            'assigned_to' => $action->assignedTo?->name,
            'due_date' => $action->due_date?->toDateString(),
            'days_until_due' => $action->getDaysUntilDue(),
            'is_overdue' => $action->isOverdue(),
            'completed_date' => $action->completed_date?->toDateString(),
            'completion_notes' => $action->completion_notes,
            'estimated_cost' => $action->estimated_cost,
            'actual_cost' => $action->actual_cost,
        ];
    }
}
