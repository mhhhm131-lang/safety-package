<?php

namespace App\Modules\Project\Inbox;

use App\Core\Inbox\Task;
use App\Core\Inbox\TaskSource;
use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Project\Models\ExternalPartyDocument;
use App\Modules\Project\Models\ExternalPartyEvaluation;
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
        // ٢٧-ب (قرار ٦٧): عامل في التعريف أو التدريب — الخطوة التالية بيد من يعتمد العمال (WorkerService::APPROVAL_TRANSITIONS)
        if (PermissionRegistry::hasPermission($role, 'worker.approve')) {
            foreach (Worker::whereIn('status', ['induction', 'training'])->with('externalParty')->get() as $w) {
                $induction = $w->status === 'induction';
                $out->push(new Task(
                    key: "worker:{$w->id}:{$w->status}", module: 'المقاولون',
                    question: 'عامل «'.$w->full_name.'»'.($w->externalParty ? ' من '.$w->externalParty->name : '')
                        .($induction ? ' في التعريف — سجّل إتمام تعريفه' : ' في التدريب — اعتمده حين يتم تدريبه'),
                    primary: ['label' => 'افتحه', 'url' => route('workers.show', $w)],
                    detailsUrl: route('workers.show', $w), createdAt: $w->updated_at ?? $w->created_at,
                ));
            }
        }
        // ٢٧-ب: طرف خارجي «قيد التسجيل» — يُكمَل تسجيله ويُفعَّل
        if (PermissionRegistry::hasPermission($role, 'external_party.edit')) {
            foreach (ExternalParty::where('status', 'pending')->get() as $party) {
                $out->push(new Task(
                    key: "party:{$party->id}:pending", module: 'المقاولون',
                    question: 'الطرف «'.$party->name.'» قيد التسجيل — أكمل تسجيله',
                    primary: ['label' => 'أكمله', 'url' => route('external-parties.edit', $party)],
                    detailsUrl: route('external-parties.show', $party), createdAt: $party->created_at,
                ));
            }
        }
        // ٢٧-ب: مشروع اكتمل ولم يُقيَّم طرفه عليه
        if (PermissionRegistry::hasPermission($role, 'external_party.evaluate')) {
            $done = ProjectContractor::whereHas('project', fn ($q) => $q->where('status', 'completed'))->with(['project', 'externalParty'])->get();
            $evaluated = ExternalPartyEvaluation::whereNotNull('project_id')->get(['external_party_id', 'project_id'])
                ->map(fn ($e) => $e->external_party_id.':'.$e->project_id)->flip();
            foreach ($done as $pc) {
                if (!$pc->externalParty || !$pc->project || $evaluated->has($pc->external_party_id.':'.$pc->project_id)) continue;
                $out->push(new Task(
                    key: "party:{$pc->external_party_id}:eval:{$pc->project_id}", module: 'المقاولون',
                    question: 'اكتمل مشروع «'.$pc->project->name.'» — قيّم «'.$pc->externalParty->name.'» عليه',
                    primary: ['label' => 'قيّمه', 'url' => route('external-parties.evaluation.create', ['externalParty' => $pc->external_party_id, 'project_id' => $pc->project_id])],
                    detailsUrl: route('external-parties.show', $pc->external_party_id), createdAt: $pc->project->updated_at,
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
                    // المرحلة ١٨-١ (هـ، قرار ٤٦): إلى الصف المعني لا القائمة كلها
                    primary: ['label' => 'راجعه', 'url' => route('projects.contractors', $pc->project_id).'#contractor-'.$pc->id],
                    detailsUrl: route('projects.contractors', $pc->project_id), createdAt: $pc->updated_at ?? $pc->created_at,
                ));
            }
        }
        if (PermissionRegistry::hasPermission($role, 'external_party.edit')) {
            foreach (ExternalPartyDocument::where('is_verified', false)->with('externalParty')->get() as $d) {
                $out->push(new Task(
                    key: "epdoc:{$d->id}:verify", module: 'المقاولون',
                    question: 'وثيقة من «'.($d->externalParty?->name ?? '—').'» تنتظر تحققك',
                    primary: ['label' => 'تحقق منها', 'url' => route('external-parties.documents', $d->external_party_id).'#doc-'.$d->id],
                    dueAt: $d->expiry_date, isOverdue: (bool) ($d->expiry_date && $d->expiry_date->isPast()),
                    detailsUrl: route('external-parties.documents', $d->external_party_id), createdAt: $d->created_at,
                ));
            }
        }
        return $out;
    }
}
