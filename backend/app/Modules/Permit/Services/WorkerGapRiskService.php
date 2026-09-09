<?php

namespace App\Modules\Permit\Services;

use App\Modules\Permit\Models\Permit;
use App\Modules\Worker\Models\CompetencyRequirement;
use App\Modules\Worker\Models\Worker;
use App\Modules\Worker\Models\WorkerTrainingRecord;
use Illuminate\Support\Facades\DB;

/**
 * ثغرات أهلية العمال المعيَّنين على تصريح: فحص طبي، إقامة، تدريب إلزامي لمهنته، كفاءات ناقصة.
 * تقرير فقط — لا يربط مخاطر تلقائياً. الثغرة «العالية» تمنع تفعيل التصريح (حارس في PermitService).
 */
class WorkerGapRiskService
{
    /**
     * @return array{total_workers: int, workers_with_gaps: int, gaps: array<int, array{
     *   worker_id: int, worker_name: string, trade: ?string,
     *   gaps: array<int, array{type: string, label: string, severity: string, detail: ?string}>}>}
     */
    public function reportForPermit(Permit $permit): array
    {
        $rows = $permit->workers()->with(['worker.trade'])->get();

        $report = [];
        foreach ($rows as $pw) {
            $worker = $pw->worker;
            if (!$worker) {
                continue;
            }
            $gaps = $this->gapsForWorker($worker);
            if ($gaps !== []) {
                $report[] = [
                    'worker_id'   => $worker->id,
                    'worker_name' => $worker->full_name,
                    'trade'       => $worker->trade?->name,
                    'gaps'        => $gaps,
                ];
            }
        }

        return [
            'total_workers'     => $rows->count(),
            'workers_with_gaps' => count($report),
            'gaps'              => $report,
        ];
    }

    /**
     * ثغرات عامل واحد.
     *
     * @return array<int, array{type: string, label: string, severity: string, detail: ?string}>
     */
    public function gapsForWorker(Worker $worker): array
    {
        $gaps = [];

        // حالة العامل في دورة حياته (المرحلة ٦): غير المصرّح له لا يعمل.
        if (!in_array($worker->status, ['approved', 'work_authorized', 'role_authorized'], true)) {
            $gaps[] = [
                'type' => 'status_not_authorized', 'severity' => 'high',
                'label' => 'حالة العامل لا تسمح بالعمل', 'detail' => $worker->getStatusLabel(),
            ];
        }

        // الفحص الطبي.
        if ($worker->medical_expiry === null) {
            $gaps[] = ['type' => 'medical_missing', 'severity' => 'high', 'label' => 'الفحص الطبي غير مسجَّل', 'detail' => null];
        } elseif ($worker->medical_expiry->isPast()) {
            $gaps[] = ['type' => 'medical_expired', 'severity' => 'high', 'label' => 'الفحص الطبي منتهٍ',
                'detail' => 'انتهى في '.$worker->medical_expiry->format('Y-m-d')];
        } elseif ($worker->medical_expiry->lte(now()->addDays(30))) {
            $gaps[] = ['type' => 'medical_expiring', 'severity' => 'medium', 'label' => 'الفحص الطبي يقارب الانتهاء',
                'detail' => 'ينتهي في '.$worker->medical_expiry->format('Y-m-d')];
        }

        // الإقامة.
        if ($worker->iqama_expiry !== null && $worker->iqama_expiry->isPast()) {
            $gaps[] = ['type' => 'iqama_expired', 'severity' => 'high', 'label' => 'الإقامة منتهية',
                'detail' => 'انتهت في '.$worker->iqama_expiry->format('Y-m-d')];
        }

        // مستندات منتهية.
        $expiredDocs = $worker->documents()
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', now()->toDateString())
            ->pluck('name');
        if ($expiredDocs->isNotEmpty()) {
            $gaps[] = ['type' => 'certificates_expired', 'severity' => 'high', 'label' => 'شهادات منتهية',
                'detail' => $expiredDocs->implode('، ')];
        }

        // التدريب الإلزامي لمهنته.
        foreach ($this->missingTrainingTopics($worker) as $name) {
            $gaps[] = ['type' => 'training_missing', 'severity' => 'medium',
                'label' => 'تدريب إلزامي غير مكتمل: '.$name, 'detail' => null];
        }

        // كفاءات مسجَّلة كناقصة على مهنته.
        if ($worker->trade_id) {
            $missing = DB::table('competency_gaps as g')
                ->join('trade_competencies as c', 'c.id', '=', 'g.trade_competency_id')
                ->where('g.trade_id', $worker->trade_id)
                ->pluck('c.name');
            if ($missing->isNotEmpty()) {
                $gaps[] = ['type' => 'competency_gaps_outstanding', 'severity' => 'medium',
                    'label' => 'كفاءات ناقصة في المهنة ('.$missing->count().')', 'detail' => $missing->implode('، ')];
            }
        }

        return $gaps;
    }

    /** أسماء مواضيع التدريب الإلزامية لمهنة العامل التي لا سجل ساري لها. @return array<int, string> */
    private function missingTrainingTopics(Worker $worker): array
    {
        if (!$worker->trade_id) {
            return [];
        }

        $required = CompetencyRequirement::where('trade_id', $worker->trade_id)
            ->where('is_mandatory', true)
            ->pluck('training_topic_id')
            ->all();

        if ($required === []) {
            return [];
        }

        $completed = WorkerTrainingRecord::where('worker_id', $worker->id)
            ->where('status', 'completed')
            ->whereIn('training_topic_id', $required)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', now()->toDateString()))
            ->pluck('training_topic_id')
            ->all();

        $missing = array_diff($required, $completed);
        if ($missing === []) {
            return [];
        }

        return DB::table('training_topics')->whereIn('id', $missing)->pluck('name')->all();
    }
}
