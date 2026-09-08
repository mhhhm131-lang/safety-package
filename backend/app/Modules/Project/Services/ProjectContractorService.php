<?php

namespace App\Modules\Project\Services;

use App\Core\Services\NotificationService;
use App\Modules\Project\Events\ProjectContractorCreated;
use App\Modules\Project\Events\ProjectContractorStatusChanged;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Project\Models\Project;
use App\Modules\Project\Models\ProjectContractor;
use App\Modules\Project\Models\ProjectContractorEvent;
use App\Modules\Project\StateMachines\ProjectContractorStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * ربط المقاول بالمشروع وتأهيله (من OHSMS بلا tenant). كل انتقال يمر بآلة الحالة ويُسجَّل حدثاً.
 * إضافة المعهد: تغيّر حالة التأهيل يُنبّه حسابات المقاول ومسؤول السلامة داخل النظام وبالبريد (§٦).
 */
class ProjectContractorService
{
    public function __construct(
        private readonly ProjectContractorStateMachine $stateMachine,
        private readonly NotificationService $inbox,
    ) {}

    public function attach(Project $project, ExternalParty $party, string $role = ProjectContractor::ROLE_MAIN, ?int $userId = null, array $attributes = []): ProjectContractor
    {
        return DB::transaction(function () use ($project, $party, $role, $userId, $attributes) {
            $existing = ProjectContractor::where('project_id', $project->id)->where('external_party_id', $party->id)->first();
            if ($existing) {
                return $existing;
            }
            $pc = ProjectContractor::create(array_merge([
                'project_id' => $project->id,
                'external_party_id' => $party->id,
                'role' => $role,
                'qualification_status' => ProjectContractor::STATUS_DRAFT,
                'created_by_id' => $userId,
            ], $attributes));
            $this->recordEvent($pc, 'created', ['role' => $role, 'party_id' => $party->id], $userId);
            return $pc;
        });
    }

    public function attachAndNotify(Project $project, ExternalParty $party, string $role = ProjectContractor::ROLE_MAIN, ?int $userId = null, array $attributes = []): ProjectContractor
    {
        $pc = $this->attach($project, $party, $role, $userId, $attributes);
        if ($pc->wasRecentlyCreated) {
            Event::dispatch(new ProjectContractorCreated($pc, $userId));
            $this->notifyParty($pc, 'رُبط طرفكم بمشروع: '.$project->name, 'حالة التأهيل: '.$pc->getStatusLabel().'. أكملوا المستندات المطلوبة (السجل التجاري، التأمين).');
        }
        return $pc;
    }

    /** يتحقق من صلاحية الدور على الانتقال (آلة الحالة) قبل التنفيذ. */
    public function transition(ProjectContractor $pc, string $toStatus, ?int $userId = null, ?string $notes = null, ?string $role = null): ProjectContractor
    {
        $from = $pc->qualification_status;
        $this->stateMachine->validate($from, $toStatus);
        if ($role !== null && !$this->stateMachine->canUserTransition($from, $toStatus, $role)) {
            throw new \App\Core\StateMachine\Exceptions\TransitionException("لا يملك دورك الانتقال من «{$pc->getStatusLabel()}» إلى «".(ProjectContractor::STATUSES[$toStatus] ?? $toStatus).'».');
        }

        return DB::transaction(function () use ($pc, $from, $toStatus, $userId, $notes) {
            $updates = ['qualification_status' => $toStatus];
            if ($toStatus === ProjectContractor::STATUS_PRE_APPROVED && !$pc->pre_approved_at) {
                $updates['pre_approved_at'] = now();
            }
            if ($toStatus === ProjectContractor::STATUS_POST_APPROVED && !$pc->post_approved_at) {
                $updates['post_approved_at'] = now();
            }
            $pc->update($updates);
            $this->recordEvent($pc, 'status_changed', ['from' => $from, 'to' => $toStatus], $userId, $notes, fromStatus: $from, toStatus: $toStatus);
            $pc->refresh();
            Event::dispatch(new ProjectContractorStatusChanged($pc, $from, $toStatus, $userId));
            $this->notifyParty($pc, 'تأهيلكم في مشروع '.$pc->project?->name.': '.$pc->getStatusLabel(), $notes);
            if (in_array($toStatus, [ProjectContractor::STATUS_PRE_REVIEW, ProjectContractor::STATUS_POST_REVIEW], true)) {
                $this->inbox->notifyRoles(['system_admin', 'system_staff'], 'contractor.review',
                    'طلب مراجعة تأهيل: '.$pc->externalParty?->name, 'المشروع: '.$pc->project?->name.' — '.$pc->getStatusLabel(), '/app/projects/'.$pc->project_id.'/contractors');
            }
            return $pc;
        });
    }

    public function suspendAllForParty(ExternalParty $party, ?int $userId = null, ?string $reason = null): int
    {
        $rows = ProjectContractor::where('external_party_id', $party->id)
            ->whereNotIn('qualification_status', [ProjectContractor::STATUS_SUSPENDED, ProjectContractor::STATUS_EXPIRED])->get();
        foreach ($rows as $pc) {
            $this->transition($pc, ProjectContractor::STATUS_SUSPENDED, $userId, $reason);
        }
        return $rows->count();
    }

    public function recordEvent(ProjectContractor $pc, string $eventType, array $changes = [], ?int $userId = null, ?string $notes = null, ?string $fromStatus = null, ?string $toStatus = null): ProjectContractorEvent
    {
        return ProjectContractorEvent::create([
            'project_contractor_id' => $pc->id,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'changes' => $changes ?: null,
            'notes' => $notes,
            'performed_by_id' => $userId,
        ]);
    }

    /** حسابات الطرف الخارجي (المقاول/مشرفه) تُنبَّه داخل النظام وبالبريد. */
    protected function notifyParty(ProjectContractor $pc, string $title, ?string $message): void
    {
        $party = $pc->externalParty;
        if (!$party) return;
        foreach ($party->users()->pluck('id') as $uid) {
            $this->inbox->create($uid, 'contractor.qualification', $title, $message, '/app/contractor');
        }
    }
}
