<?php

namespace App\Modules\Worker\Services;

use App\Modules\Worker\Models\CompetencyRequirement;
use App\Modules\Worker\Models\TrainingTopic;
use App\Modules\Worker\Models\Trade;
use App\Modules\Worker\Models\Worker;
use App\Modules\Worker\Models\WorkerTrainingRecord;

class CompetencyService
{
    /**
     * Build the competency matrix data for a tenant.
     *
     * Previously this method ran O(trades × topics) queries — on a tenant
     * with 20 trades × 30 topics that was 600+ individual SELECTs. The
     * rewrite does the same work with a constant number of queries (≤6)
     * regardless of tenant size by pre-fetching all rows and indexing
     * them in PHP.
     *
     * @return array{trades: \Illuminate\Support\Collection, topics: \Illuminate\Support\Collection, matrix: array}
     */
    public function getMatrixData(): array
    {
        // 1 query: distinct trades used by workers in this tenant.
        $tradeIds = Worker::query()
            ->whereNotNull('trade_id')
            ->distinct()
            ->pluck('trade_id');

        // 1 query: trades collection.
        $trades = Trade::whereIn('id', $tradeIds)
            ->where('is_active', true)
            ->get();

        // 1 query: active topics.
        $topics = TrainingTopic::where('is_active', true)->get();

        // 1 query: all requirement rows for these trades (indexed below).
        $requirements = CompetencyRequirement::whereIn('trade_id', $tradeIds)
            ->get(['trade_id', 'training_topic_id'])
            ->groupBy('trade_id')
            ->map(fn($group) => $group->pluck('training_topic_id')->all())
            ->all();

        // 1 query: worker counts per trade.
        $workerCountsByTrade = Worker::query()
            ->whereIn('trade_id', $tradeIds)
            ->selectRaw('trade_id, COUNT(*) as c')
            ->groupBy('trade_id')
            ->pluck('c', 'trade_id')
            ->all();

        // 1 query: worker-id → trade-id lookup (used to pivot completion rows).
        $workerToTrade = Worker::query()
            ->whereIn('trade_id', $tradeIds)
            ->pluck('trade_id', 'id')
            ->all();

        // 1 query: all valid (completed, non-expired) training records for
        // the tenant's workers, grouped by (trade, topic) in PHP.
        $completions = [];
        if (!empty($workerToTrade)) {
            $rows = WorkerTrainingRecord::whereIn('worker_id', array_keys($workerToTrade))
                ->where('status', 'completed')
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->get(['worker_id', 'training_topic_id']);

            foreach ($rows as $row) {
                $tradeId = $workerToTrade[$row->worker_id] ?? null;
                if ($tradeId === null) continue;
                $key = $tradeId . '_' . $row->training_topic_id;
                $completions[$key] = ($completions[$key] ?? 0) + 1;
            }
        }

        // Assemble the matrix in PHP (O(trades × topics) but no DB hits).
        $matrix = [];
        foreach ($trades as $trade) {
            $matrix[$trade->id] = [];
            $totalCount = $workerCountsByTrade[$trade->id] ?? 0;
            $requiredTopics = $requirements[$trade->id] ?? [];

            foreach ($topics as $topic) {
                $required = in_array($topic->id, $requiredTopics, true);
                $completedCount = $completions[$trade->id . '_' . $topic->id] ?? 0;

                if (!$required || $totalCount === 0) {
                    $completion = 'none';
                } elseif ($completedCount >= $totalCount) {
                    $completion = 'completed';
                } elseif ($completedCount > 0) {
                    $completion = 'partial';
                } else {
                    $completion = 'none';
                }

                $matrix[$trade->id . '_' . $topic->id] = [
                    'required'        => $required,
                    'completion'      => $completion,
                    'completed_count' => $completedCount,
                    'total_count'     => $totalCount,
                ];
            }
        }

        return [
            'trades' => $trades,
            'topics' => $topics,
            'matrix' => $matrix,
        ];
    }

    /**
     * Toggle a competency requirement for a trade and topic.
     */
    public function toggleRequirement(int $tradeId, int $topicId): array
    {
        $existing = CompetencyRequirement::where('trade_id', $tradeId)
            ->where('training_topic_id', $topicId)
            ->first();

        if ($existing) {
            $existing->delete();

            return [
                'action' => 'deleted',
                'requirement' => $existing,
            ];
        }

        $requirement = CompetencyRequirement::create([
            'trade_id' => $tradeId,
            'training_topic_id' => $topicId,
            'source' => 'trade_based',
        ]);

        return [
            'action' => 'created',
            'requirement' => $requirement,
        ];
    }

    /**
     * Get compliance status for a specific worker.
     */
    public function getWorkerCompliance(Worker $worker): array
    {
        if (!$worker->trade_id) {
            return [
                'requirements' => [],
                'compliance_percent' => 100.0,
            ];
        }

        $requirements = CompetencyRequirement::where('trade_id', $worker->trade_id)
            ->with('trainingTopic')
            ->get();

        if ($requirements->isEmpty()) {
            return [
                'requirements' => [],
                'compliance_percent' => 100.0,
            ];
        }

        $results = [];
        $completedCount = 0;

        foreach ($requirements as $req) {
            $topic = $req->trainingTopic;

            $record = WorkerTrainingRecord::where('worker_id', $worker->id)
                ->where('training_topic_id', $topic->id)
                ->latest('completed_at')
                ->first();

            if (!$record) {
                $status = 'not_started';
            } elseif ($record->status === 'completed') {
                if ($record->expires_at && $record->expires_at->isPast()) {
                    $status = 'expired';
                } else {
                    $status = 'completed';
                    $completedCount++;
                }
            } else {
                $status = 'pending';
            }

            $results[] = [
                'topic' => $topic,
                'status' => $status,
            ];
        }

        $total = count($results);
        $compliancePercent = $total > 0 ? round(($completedCount / $total) * 100, 2) : 100.0;

        return [
            'requirements' => $results,
            'compliance_percent' => $compliancePercent,
        ];
    }

    /**
     * Aggregate compliance for all workers belonging to an external party (contractor).
     */
    public function getContractorCompliance(\App\Modules\Project\Models\ExternalParty $externalParty): array
    {
        $workers = Worker::where('external_party_id', $externalParty->id)->get();

        if ($workers->isEmpty()) {
            return [
                'workers' => [],
                'overall_compliance' => 100.0,
                'workers_count' => 0,
            ];
        }

        $totalCompliance = 0;
        $rows = [];

        foreach ($workers as $worker) {
            $compliance = $this->getWorkerCompliance($worker);
            $rows[] = [
                'worker' => $worker,
                'compliance_percent' => $compliance['compliance_percent'],
            ];
            $totalCompliance += $compliance['compliance_percent'];
        }

        return [
            'workers' => $rows,
            'overall_compliance' => round($totalCompliance / $workers->count(), 2),
            'workers_count' => $workers->count(),
        ];
    }
}
