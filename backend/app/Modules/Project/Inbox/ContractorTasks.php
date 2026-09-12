<?php

namespace App\Modules\Project\Inbox;

use App\Core\Inbox\Task;
use App\Core\Inbox\TaskSource;
use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Project\Models\ExternalPartyDocument;
use App\Modules\Project\Models\ProjectContractor;
use App\Modules\Worker\Models\Worker;
use App\Modules\Worker\Services\WorkerService;
use Illuminate\Support\Collection;

/**
 * المقاولون والعمال بصيغة مهام: عامل مقدَّم للاعتماد (WorkerService::getApprovalQueue)، تأهيل مقاول ينتظر المراجعة
 * (ProjectContractor pre/post_review)، ووثيقة طرف خارجي لم تُتحقق (ExternalPartyDocument is_verified=false).
 */
class ContractorTasks implements TaskSource
{
    public function __construct(private WorkerService $workers) {}

    public function tasksFor(User $user): Collection
    {
        $role = $user->role();
        $out = collect();

        if (PermissionRegistry::hasPermission($role, 'worker.approve')) {
            foreach ($this->workers->getApprovalQueue() as $w) {
                /** @var Worker $w */
                $out->push(new Task(
                    key: "worker:{$w->id}:approve", module: 'المقاولون',
                    question: 'عامل «'.$w->full_name.'»'.($w->externalParty ? ' من '.$w->externalParty->name : '').' مقدَّم للاعتماد',
                    primary: ['label' => 'راجعه', 'url' => route('workers.show', $w)],
                    detailsUrl: route('workers.approval-queue'), createdAt: $w->updated_at ?? $w->created_at,
                ));
            }
        }
        if (PermissionRegistry::hasPermission($role, 'project.edit')) {
            $pending = ProjectContractor::whereIn('qualification_status', [ProjectContractor::STATUS_PRE_REVIEW, ProjectContractor::STATUS_POST_REVIEW])
                ->with(['project', 'externalParty'])->get();
            foreach ($pending as $pc) {
                $out->push(new Task(
                    key: "contractor:{$pc->id}:review", module: 'المقاولون',
                    question: 'تأهيل «'.($pc->externalParty?->name ?? '—').'» في مشروع «'.($pc->project?->name ?? '—').'» ينتظر مراجعتك',
                    primary: ['label' => 'راجعه', 'url' => route('projects.contractors', $pc->project_id)],
                    detailsUrl: route('projects.contractors', $pc->project_id), createdAt: $pc->updated_at ?? $pc->created_at,
                ));
            }
        }
        if (PermissionRegistry::hasPermission($role, 'external_party.edit')) {
            foreach (ExternalPartyDocument::where('is_verified', false)->with('externalParty')->get() as $d) {
                $out->push(new Task(
                    key: "epdoc:{$d->id}:verify", module: 'المقاولون',
                    question: 'وثيقة من «'.($d->externalParty?->name ?? '—').'» تنتظر تحققك',
                    primary: ['label' => 'تحقق منها', 'url' => route('external-parties.documents', $d->external_party_id)],
                    dueAt: $d->expiry_date, isOverdue: (bool) ($d->expiry_date && $d->expiry_date->isPast()),
                    detailsUrl: route('external-parties.documents', $d->external_party_id), createdAt: $d->created_at,
                ));
            }
        }
        return $out;
    }
}
