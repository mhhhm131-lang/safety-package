<?php

namespace App\Modules\Project\Services;

use App\Modules\Incident\Models\Incident;
use App\Modules\Project\Models\ExternalPartyEvaluation;
use App\Modules\Project\Models\ManhourLog;
use App\Modules\Project\Models\Project;
use App\Modules\Risk\Models\Risk;
use App\Modules\Worker\Models\Worker;

/**
 * خدمة المشاريع (من OHSMS بلا tenant). لوحة المشروع بلا عدّادات EPC (المعدات في ٦-ب) وبلا تصاريح (٦-ب):
 * تعرض المخاطر والعمال وبلاغات الشاغلين المربوطة بالمشروع وساعات العمل ومعدل LTIR.
 */
class ProjectService
{
    public function getList()
    {
        return Project::with('place')->latest()->paginate(20);
    }

    public function create(int $userId, array $data): Project
    {
        $data['created_by_id'] = $userId;
        return Project::create($data);
    }

    public function update(Project $project, array $data): Project
    {
        $project->update($data);
        return $project->fresh();
    }

    public function getDetail(Project $project): Project
    {
        $project->load(['organizationUnit', 'assignedCoordinator', 'createdBy', 'place']);
        return $project;
    }

    public function getRisks(Project $project)
    {
        return Risk::where('project_id', $project->id)->with(['category', 'subCategory'])->latest()->paginate(20);
    }

    public function getDashboardData(Project $project): array
    {
        $pid = $project->id;
        $totalManhours = (int) ManhourLog::where('project_id', $pid)->sum('total_manhours');
        $incidentCount = Incident::where('project_id', $pid)->count();
        // معدل حوادث الوقت الضائع لكل ٢٠٠ ألف ساعة (OSHA) — كما في OHSMS
        $ltir = $totalManhours > 0 ? round(($incidentCount * 200000) / $totalManhours, 2) : '0.00';

        $recentRisks = Risk::where('project_id', $pid)->latest()->limit(5)->get();

        $contractors = $project->projectContractors()->with('externalParty')->get()->map(function ($pc) use ($pid) {
            $mh = ManhourLog::where('project_id', $pid)->where('external_party_id', $pc->external_party_id)
                ->selectRaw('COALESCE(SUM(total_manhours),0) as hours, COALESCE(SUM(workers_count),0) as workers')->first();
            return [
                'name' => optional($pc->externalParty)->name ?? '—',
                'status' => $pc->qualification_status,
                'status_label' => $pc->getStatusLabel(),
                'workers' => (int) ($mh->workers ?? 0),
                'registered_workers' => Worker::where('project_id', $pid)->where('external_party_id', $pc->external_party_id)->count(),
                'manhours' => (int) ($mh->hours ?? 0),
                'permits' => null, // المرحلة ٦-ب
            ];
        });

        return [
            'active_permits' => null, // المرحلة ٦-ب
            'open_risks' => Risk::where('project_id', $pid)->whereNotIn('status', ['closed', 'archived', 'rejected'])->count(),
            'incidents_count' => $incidentCount,
            'active_workers' => Worker::where('project_id', $pid)->count(),
            'recent_risks' => $recentRisks,
            'manhours' => [
                'total' => $totalManhours,
                'safe' => max(0, $totalManhours - ($incidentCount * 8)),
                'ltir' => $ltir,
            ],
            'contractors' => $contractors,
        ];
    }

    public function getManhours(Project $project)
    {
        return ManhourLog::where('project_id', $project->id)->with('externalParty')->latest('date')->paginate(20);
    }

    public function storeManhour(Project $project, int $userId, array $data): ManhourLog
    {
        $data['project_id'] = $project->id;
        $data['recorded_by_id'] = $userId;
        $data['total_manhours'] = ($data['workers_count'] ?? 0) * ($data['hours_worked'] ?? 0);
        return ManhourLog::create($data);
    }

    public function getComparison(Project $project)
    {
        return ExternalPartyEvaluation::where('project_id', $project->id)->with('externalParty')->latest('created_at')->get();
    }
}
