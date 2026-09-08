<?php

namespace App\Modules\Worker\Services;

use App\Core\Services\NotificationService;
use App\Modules\Worker\Models\Worker;
use App\Modules\Worker\Models\WorkerStatusEvent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * دورة حياة العامل (من OHSMS بلا tenant): draft → submitted → induction → training → approved → work_authorized → role_authorized؛
 * الحظر/الإيقاف من approved فما بعد، والعودة إلى approved. إضافة المعهد: التقديم يُنبّه مسؤول السلامة والمنسق (كان NotificationService محقوناً بلا استعمال).
 */
class WorkerService
{
    public const TRANSITIONS = [
        'draft' => ['submitted'],
        'submitted' => ['induction'],
        'induction' => ['training'],
        'training' => ['approved'],
        'approved' => ['work_authorized', 'blocked', 'suspended'],
        'work_authorized' => ['role_authorized', 'blocked', 'suspended'],
        'role_authorized' => ['blocked', 'suspended'],
        'blocked' => ['approved'],
        'suspended' => ['approved'],
    ];

    /** الانتقالات التي تحتاج صلاحية worker.approve (اعتماد المعهد)؛ ما عداها worker.manage أو صاحب العامل (التقديم). */
    public const APPROVAL_TRANSITIONS = ['induction', 'training', 'approved', 'work_authorized', 'role_authorized', 'blocked', 'suspended'];

    public function __construct(private readonly NotificationService $notificationService) {}

    public function create(int $userId, array $data): Worker
    {
        $data['created_by_id'] = $userId;
        $data['status'] = 'draft';
        return Worker::create($data);
    }

    public function transition(Worker $worker, int $userId, string $newStatus, ?string $note = null): Worker
    {
        $currentStatus = $worker->status;
        $allowed = self::TRANSITIONS[$currentStatus] ?? [];
        if (!in_array($newStatus, $allowed, true)) {
            throw new \InvalidArgumentException("Transition from '{$currentStatus}' to '{$newStatus}' is not allowed.");
        }
        if ($newStatus === 'blocked' && empty($note)) {
            throw new \InvalidArgumentException('A blocked_reason (note) is required when blocking a worker.');
        }
        return DB::transaction(function () use ($worker, $userId, $currentStatus, $newStatus, $note) {
            $updateData = ['status' => $newStatus];
            if ($newStatus === 'blocked') {
                $updateData['blocked_reason'] = $note;
            }
            $worker->update($updateData);
            WorkerStatusEvent::create([
                'worker_id' => $worker->id, 'action' => $newStatus, 'from_status' => $currentStatus, 'to_status' => $newStatus,
                'note' => $note, 'actor_id' => $userId, 'created_at' => now(),
            ]);
            if ($newStatus === 'submitted') {
                $this->notificationService->notifyRoles(['system_admin', 'system_staff', 'safety_coordinator'], 'worker.submitted',
                    'عامل بانتظار الاعتماد: '.$worker->full_name, ($worker->externalParty?->name ?? '').' — '.($worker->trade?->name ?? ''), '/app/workers/approval-queue');
            }
            return $worker->fresh();
        });
    }

    public function getApprovalQueue(): Collection
    {
        return Worker::where('status', 'submitted')->with(['trade', 'externalParty'])->get();
    }
}
