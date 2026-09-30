<?php

namespace App\Modules\Permit\Inbox;

use App\Core\Inbox\Task;
use App\Core\Inbox\TaskSource;
use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Permit\Models\Permit;
use App\Modules\Permit\Models\PermitDeviation;
use Illuminate\Support\Collection;

/**
 * طابور التصاريح (Permit::pendingDecision، الاستعلام نفسه الذي تعرضه شاشة الطابور) بصيغة مهام:
 * مقدَّم/قيد المراجعة ← «راجعه» لمن يملك permit.review؛ معتمد من السلامة ← «الاعتماد النهائي» لمن يملك permit.final_approve.
 *
 * المرحلة ٢٧-ب (قرار ٦٧): بقية ما يُراد من شخص في دورة التصريح — كل حالة تنتظر فعلاً تصل صاحبها بطاقةً، والحارس سياسة التصريح نفسها:
 *   معتمد بشرط   ← الاعتماد النهائي (مسؤول السلامة والمناوب)
 *   معتمد         ← «فعّله» لمن يملك التفعيل، بعدد البنود الإلزامية الباقية
 *   معتمد وبنوده ناقصة ← «استوفِها» لمقدّم الطلب وحسابات طرفه ممن لا يفعّلون (المقاول يرفع أدلته)
 *   نشط وفيه انحراف مفتوح ← «عالجه» لمن يملك التفعيل (الإغلاق ممنوع حتى يُعالج)
 *   مكتمل بلا تقييم ← «قيّمه» لمن يملك المراجعة
 *   مسودة         ← «قدّمه» لمقدّم الطلب
 * «المرفوض» (٥٧ في الجرد) ليس هنا: الرفض نهاية في آلة الحالة ولا مسار لإعادة التقديم — مقدّم الطلب يُشعَر به ويبدأ طلباً جديداً.
 */
class PermitTasks implements TaskSource
{
    public function tasksFor(User $user): Collection
    {
        $role = $user->role();
        $can = fn (string $p) => PermissionRegistry::hasPermission($role, $p);
        $canReview = $can('permit.review');
        $canFinal = $can('permit.final_approve');
        $canActivate = $can('permit.activate');
        $requester = $can('permit.create') || $can('permit.edit');
        if (!$canReview && !$canFinal && !$canActivate && !$requester) return collect();

        $out = collect();
        $place = fn (Permit $p) => $p->place ? $p->place->code.' '.$p->place->name : null;
        $name = fn (Permit $p) => 'تصريح '.$p->code.' «'.$p->title.'»';

        if ($canReview || $canFinal) {
            foreach (Permit::pendingDecision()->with(['place', 'type'])->get() as $p) {
                $final = $p->status === Permit::STATUS_SAFETY_APPROVED;
                if ($final ? !$canFinal : !$canReview) continue;
                $out->push(new Task(
                    key: "permit:{$p->id}:".($final ? 'final' : 'review'),
                    module: 'التصاريح',
                    question: $name($p).' '.($final ? 'ينتظر اعتمادك النهائي' : 'ينتظر مراجعتك'),
                    primary: ['label' => $final ? 'الاعتماد النهائي' : 'راجعه', 'url' => $final ? route('permits.show', $p) : route('permits.review', $p)],
                    dueAt: $p->expires_at,
                    isOverdue: (bool) ($p->submitted_at && $p->submitted_at->lt(now()->subDay())),
                    place: $place($p),
                    detailsUrl: route('permits.show', $p),
                    createdAt: $p->submitted_at ?? $p->created_at,
                ));
            }
        }

        // معتمد بشرط: الاعتماد النهائي حين يُستوفى الشرط (السياسة approve تشمله، والطابور لم يكن يعرضه)
        if ($canFinal) {
            foreach (Permit::where('status', Permit::STATUS_CONDITIONAL)->with('place')->get() as $p) {
                $out->push(new Task(
                    key: "permit:{$p->id}:final", module: 'التصاريح',
                    question: $name($p).' معتمد بشرط — اعتمده نهائياً حين يُستوفى الشرط',
                    primary: ['label' => 'الاعتماد النهائي', 'url' => route('permits.show', $p)],
                    dueAt: $p->expires_at, place: $place($p), detailsUrl: route('permits.show', $p), createdAt: $p->reviewed_at ?? $p->updated_at,
                ));
            }
        }

        // معتمد: التفعيل الميداني، أو استيفاء بنوده لمن لا يفعّل
        foreach (Permit::where('status', Permit::STATUS_APPROVED)->with('place')->get() as $p) {
            $left = $p->blockingRequirementsCount();
            if ($canActivate && $user->can('activate', $p)) {
                $out->push(new Task(
                    key: "permit:{$p->id}:activate", module: 'التصاريح',
                    question: $name($p).' معتمد — فعّله ميدانياً'.($left ? ' (بنود إلزامية باقية: '.$left.')' : ''),
                    primary: ['label' => 'فعّله', 'url' => route('permits.activate', $p)],
                    dueAt: $p->starts_at, isOverdue: (bool) ($p->starts_at && $p->starts_at->isPast()),
                    place: $place($p), detailsUrl: route('permits.show', $p), createdAt: $p->approved_at ?? $p->updated_at,
                ));
            } elseif ($left && $this->mine($user, $p) && $user->can('completeRequirement', $p)) {
                $out->push(new Task(
                    key: "permit:{$p->id}:reqs", module: 'التصاريح',
                    question: 'تصريحك '.$p->code.' «'.$p->title.'» معتمد: بنود إلزامية باقية قبل التفعيل: '.$left.' — استوفِها',
                    primary: ['label' => 'استوفِها', 'url' => route('permits.show', $p)],
                    dueAt: $p->starts_at, isOverdue: (bool) ($p->starts_at && $p->starts_at->isPast()),
                    place: $place($p), detailsUrl: route('permits.show', $p), createdAt: $p->approved_at ?? $p->updated_at,
                ));
            }
        }

        // نشط وفيه انحراف مفتوح: لا يُغلق التصريح حتى يُعالج أو يُقبل بمبرر
        if ($canActivate) {
            $withOpen = Permit::where('status', Permit::STATUS_ACTIVE)
                ->whereHas('deviations', fn ($q) => $q->where('status', PermitDeviation::STATUS_OPEN))->with(['place', 'deviations'])->get();
            foreach ($withOpen as $p) {
                $open = $p->deviations->where('status', PermitDeviation::STATUS_OPEN);
                $out->push(new Task(
                    key: "permit:{$p->id}:deviations", module: 'التصاريح',
                    question: $name($p).': انحرافات مفتوحة: '.$open->count().' — عالجها أو اقبلها بمبرر',
                    primary: ['label' => 'عالجها', 'url' => route('permits.show', $p)],
                    isOverdue: $open->contains('severity', PermitDeviation::SEVERITY_HIGH),
                    place: $place($p), detailsUrl: route('permits.show', $p), createdAt: $open->min('recorded_at'),
                ));
            }
        }

        // مكتمل بلا تقييم بعدي
        if ($canReview) {
            foreach (Permit::where('status', Permit::STATUS_COMPLETED)->with('place')->get() as $p) {
                if (!empty($p->metadata['evaluation'])) continue;
                $out->push(new Task(
                    key: "permit:{$p->id}:evaluate", module: 'التصاريح',
                    question: $name($p).' اكتمل — قيّمه',
                    primary: ['label' => 'قيّمه', 'url' => route('permits.evaluate', $p)],
                    place: $place($p), detailsUrl: route('permits.show', $p), createdAt: $p->updated_at,
                ));
            }
        }

        // مسودة عند صاحبها
        if ($requester) {
            foreach (Permit::where('status', Permit::STATUS_DRAFT)->where('requested_by_id', $user->id)->with('place')->get() as $p) {
                if (!$user->can('submit', $p)) continue;
                $out->push(new Task(
                    key: "permit:{$p->id}:draft", module: 'التصاريح',
                    question: 'تصريحك '.$p->code.' «'.$p->title.'» مسودة لم تُقدَّم — قدّمه',
                    primary: ['label' => 'قدّمه', 'url' => route('permits.transition', ['permit' => $p, 'to_status' => Permit::STATUS_SUBMITTED]), 'method' => 'POST'],
                    secondary: ['label' => 'أكمله', 'url' => $user->can('update', $p) ? route('permits.edit', $p) : route('permits.show', $p)],
                    place: $place($p), detailsUrl: route('permits.show', $p), createdAt: $p->created_at,
                ));
            }
        }

        return $out->values();
    }

    /** صاحب الطلب: من قدّمه، أو حساب من طرفه الخارجي */
    private function mine(User $user, Permit $p): bool
    {
        return (int) $p->requested_by_id === (int) $user->id
            || ($user->external_party_id !== null && (int) $p->external_party_id === (int) $user->external_party_id);
    }
}
