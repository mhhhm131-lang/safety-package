<?php

namespace App\Modules\Permit\Inbox;

use App\Core\Inbox\Task;
use App\Core\Inbox\TaskSource;
use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Permit\Models\Permit;
use Illuminate\Support\Collection;

/**
 * طابور التصاريح (Permit::pendingDecision، الاستعلام نفسه الذي تعرضه شاشة الطابور) بصيغة مهام:
 * مقدَّم/قيد المراجعة ← «راجعه» لمن يملك permit.review؛ معتمد من السلامة ← «الاعتماد النهائي» لمن يملك permit.final_approve.
 */
class PermitTasks implements TaskSource
{
    public function tasksFor(User $user): Collection
    {
        $role = $user->role();
        $canReview = PermissionRegistry::hasPermission($role, 'permit.review');
        $canFinal = PermissionRegistry::hasPermission($role, 'permit.final_approve');
        if (!$canReview && !$canFinal) return collect();

        return Permit::pendingDecision()->with(['place', 'type'])->get()
            ->filter(fn (Permit $p) => $p->status === Permit::STATUS_SAFETY_APPROVED ? $canFinal : $canReview)
            ->map(function (Permit $p) {
                $final = $p->status === Permit::STATUS_SAFETY_APPROVED;
                return new Task(
                    key: "permit:{$p->id}:".($final ? 'final' : 'review'),
                    module: 'التصاريح',
                    question: 'تصريح '.$p->code.' «'.$p->title.'» '.($final ? 'ينتظر اعتمادك النهائي' : 'ينتظر مراجعتك'),
                    primary: ['label' => $final ? 'الاعتماد النهائي' : 'راجعه', 'url' => $final ? route('permits.show', $p) : route('permits.review', $p)],
                    dueAt: $p->expires_at,
                    isOverdue: (bool) ($p->submitted_at && $p->submitted_at->lt(now()->subDay())),
                    place: $p->place ? $p->place->code.' '.$p->place->name : null,
                    detailsUrl: route('permits.show', $p),
                    createdAt: $p->submitted_at ?? $p->created_at,
                );
            })->values();
    }
}
