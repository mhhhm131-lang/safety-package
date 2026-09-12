<?php

namespace App\Modules\Incident\Inbox;

use App\Core\Inbox\Task;
use App\Core\Inbox\TaskSource;
use App\Models\User;
use App\Modules\Incident\Models\Incident;
use App\Modules\Incident\Services\IncidentVisibilityService;
use Illuminate\Support\Collection;

/**
 * بلاغات الشاغلين بصيغة مهام. يعيد استخدام IncidentVisibilityService::getVisibleIncidents ومنطق الأزرار في
 * صفحة البلاغ (detail.blade: isCenter / isField / isCoord / isCommittee / المبلّغ). مهمة واحدة لكل بلاغ لكل شخص.
 */
class IncidentTasks implements TaskSource
{
    public function __construct(private IncidentVisibilityService $visibility) {}

    public function tasksFor(User $user): Collection
    {
        $role = $user->role();
        $isCenter = in_array($role, ['system_admin', 'system_staff'], true);
        $isCommittee = in_array($role, ['safety_committee', 'system_admin'], true);

        return $this->visibility->getVisibleIncidents($user->id)
            ->whereNotIn('status', Incident::TERMINAL)->with('place')->orderBy('created_at')->get()
            ->map(fn (Incident $i) => $this->taskFor($i, $user, $isCenter, $isCommittee))
            ->filter()->values();
    }

    private function taskFor(Incident $i, User $user, bool $isCenter, bool $isCommittee): ?Task
    {
        $show = route('incidents.show', $i);
        $place = $i->place ? $i->place->code.' '.$i->place->name : null;
        $where = $place ? ' في '.$place : '';
        $mk = fn (string $who, string $q, array $primary, ?array $secondary = null) => new Task(
            key: "incident:{$i->id}:{$who}", module: 'بلاغات الشاغلين', question: $q, primary: $primary, secondary: $secondary,
            dueAt: $i->deadline_at, isOverdue: $i->isOverdue(), place: $place, detailsUrl: $show, createdAt: $i->created_at);

        // الفني المعيَّن: فتح = استلام (١١-١ ب)؛ ثم «عولج» بصورة
        if ($i->incident_field_team_id === $user->id) {
            if ($i->status === 'forwarded') return $mk('field', 'بلاغ '.$i->code.$where.': '.$i->title, ['label' => 'افتحه', 'url' => $show]);
            if (in_array($i->status, ['field_received', 'in_progress'], true)) return $mk('field', 'بلاغ '.$i->code.$where.': عولج؟ أرسل صورة بعد المعالجة', ['label' => 'عولج', 'url' => $show], ['label' => 'تعذّر — صعّد', 'url' => $show]);
        }
        // المبلّغ بحساب: موافقة الإغلاق
        if ($i->actor_id === $user->id && $i->status === 'resolved' && $i->pending_closure && !$i->reporter_approved_closure) {
            return $mk('reporter', 'بلاغك '.$i->code.': عولج — هل عولج فعلاً؟', ['label' => 'نعم، عولج', 'url' => route('incidents.approveClosure', $i), 'method' => 'POST'], ['label' => 'لا، أعِده', 'url' => $show]);
        }
        // المنسق المعيَّن
        if ($i->incident_coordinator_id === $user->id && $i->status === 'escalated_to_coord') {
            return $mk('coord', 'بلاغ '.$i->code.$where.': صُعّد إليك — تتولّى المعالجة؟', ['label' => 'أتولّى', 'url' => route('incidents.resolveEscalation', $i), 'method' => 'POST'], ['label' => 'صعّد للجنة', 'url' => $show]);
        }
        // اللجنة (وحتى تشكيلها مسؤول السلامة)
        if ($isCommittee && $i->status === 'escalated_to_manager') {
            return $mk('committee', 'بلاغ '.$i->code.$where.': صُعّد للجنة — تتولّى المعالجة؟', ['label' => 'أتولّى', 'url' => route('incidents.resolveEscalation', $i), 'method' => 'POST'], ['label' => 'التفاصيل', 'url' => $show]);
        }
        // المركز
        if ($isCenter) {
            if (in_array($i->status, ['new', 'received'], true)) return $mk('center', 'بلاغ '.$i->code.$where.': لا فني للمكان — أحِله', ['label' => 'أحِله', 'url' => $show]);
            if ($i->status === 'referred') return $mk('center', 'بلاغ '.$i->code.$where.': عند المنسق بلا فني — حوّله', ['label' => 'حوّله', 'url' => $show]);
            if ($i->status === 'resolved') {
                // قواعد الإغلاق القائمة (IncidentClosureService::close): مبلّغ بحساب ← موافقته أولاً؛ وإلا ← تحقق شخص غير المنفّذ
                $needsReporter = $i->actor_id !== null && in_array($i->incident_type, ['normal', 'urgent'], true);
                if ($needsReporter && !$i->reporter_approved_closure) {
                    if ($i->pending_closure) return null; // بانتظار المبلّغ — مهمته هو
                    return $mk('center', 'بلاغ '.$i->code.$where.': عولج — اطلب موافقة المبلّغ على الإغلاق', ['label' => 'اطلب موافقته', 'url' => route('incidents.close', $i), 'method' => 'POST'], ['label' => 'التفاصيل / رفض', 'url' => $show]);
                }
                if (!$needsReporter && !$i->coord_verified_at) {
                    return $mk('center', 'بلاغ '.$i->code.$where.': عولج — تحقق ميدانياً قبل الإغلاق', ['label' => 'تحققتُ ميدانياً', 'url' => route('incidents.verify', $i), 'method' => 'POST'], ['label' => 'التفاصيل / رفض', 'url' => $show]);
                }
                return $mk('center', 'بلاغ '.$i->code.$where.': عولج — أغلقه؟', ['label' => 'أغلق', 'url' => route('incidents.close', $i), 'method' => 'POST'], ['label' => 'التفاصيل / رفض', 'url' => $show]);
            }
            if (!$i->risk_id) return $mk('center', 'بلاغ '.$i->code.$where.': لم يُصنَّف بعد — صنّف الخطر', ['label' => 'صنّف', 'url' => $show]);
        }
        return null;
    }
}
