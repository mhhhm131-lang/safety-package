<?php

namespace App\Modules\Emergency\Inbox;

use App\Core\Inbox\Task;
use App\Core\Inbox\TaskSource;
use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Models\EmergencyIncidentStep;
use App\Modules\Emergency\Models\EmergencyNotification;
use App\Modules\Emergency\Models\PanicAlert;
use App\Modules\Emergency\Models\WearableAlert;
use App\Modules\Emergency\Services\EmergencyMessagingService;
use App\Modules\Emergency\Services\IncidentStepsService;
use Illuminate\Support\Collection;

/**
 * الطوارئ بصيغة مهام: خطوتك في خطة المكان (IncidentStepsService::ownersOf)، رسالة جماعية تنتظر إقرارك
 * (EmergencyMessagingService::getPendingResponses)، نداء هاتفي معلّق للمركز، تنبيه ذعر أو سوار مفتوح لمن يستجيب.
 */
class EmergencyTasks implements TaskSource
{
    public function __construct(private IncidentStepsService $steps, private EmergencyMessagingService $messages) {}

    public function tasksFor(User $user): Collection
    {
        $role = $user->role();
        $out = collect();
        $open = EmergencyIncident::open()->with('place')->get();

        // خطواتي في الحالات المفتوحة
        foreach ($open as $inc) {
            $mine = $this->steps->ownersOf($inc)[$user->id] ?? [];
            foreach ($mine as $step) {
                /** @var EmergencyIncidentStep $step */
                if (!$step->isPending()) continue;
                $out->push(new Task(
                    key: "estep:{$step->id}", module: 'الطوارئ',
                    question: $inc->incident_code.' '.$inc->getTypeLabel().' في '.($inc->place?->name ?? '').': خطوتك «'.$step->title.'» '.($step->when_text ? '— '.$step->when_text : '').' — تمت؟',
                    primary: ['label' => 'تم', 'url' => route('emergency.incidents.steps.done', [$inc, $step->id]), 'method' => 'POST'],
                    secondary: ['label' => 'شاشة الحالة', 'url' => route('emergency.incidents.live', $inc)],
                    dueAt: $step->due_at, isOverdue: $step->isOverdue(), place: $inc->place?->name,
                    detailsUrl: route('emergency.incidents.live', $inc), createdAt: $inc->triggered_at,
                ));
            }
        }
        // نداءات هاتفية معلّقة — للمركز
        if (in_array($role, ['system_admin', 'system_staff'], true) && $open->isNotEmpty()) {
            foreach (EmergencyNotification::whereIn('incident_id', $open->pluck('id'))->manual()->get() as $n) {
                $inc = $open->firstWhere('id', $n->incident_id);
                $out->push(new Task(
                    key: "ecall:{$n->id}", module: 'الطوارئ',
                    question: ($inc?->incident_code ?? '').': نادِ هاتفياً «'.$n->recipient_name.'»'.($n->recipient_contact ? ' '.$n->recipient_contact : ' — بلا رقم'),
                    primary: ['label' => 'شاشة الحالة', 'url' => route('emergency.incidents.live', $n->incident_id)],
                    isOverdue: true, place: $inc?->place?->name, detailsUrl: route('emergency.incidents.live', $n->incident_id), createdAt: $n->created_at,
                ));
            }
        }
        // رسالة جماعية تنتظر إقراري
        foreach ($this->messages->getPendingResponses($user) as $m) {
            $out->push(new Task(
                key: "emsg:{$m->id}", module: 'الطوارئ',
                question: 'رسالة من المركز تنتظر ردّك: '.mb_substr((string) ($m->subject ?? $m->body ?? ''), 0, 70),
                primary: ['label' => 'أقرّ', 'url' => route('emergency.dashboard')],
                createdAt: $m->sent_at,
            ));
        }
        // تنبيهات الذعر والأساور المفتوحة — لمن يستجيب
        if (PermissionRegistry::hasPermission($role, 'emergency.respond')) {
            foreach (PanicAlert::active()->with(['user', 'place'])->get() as $a) {
                $out->push(new Task(
                    key: "panic:{$a->id}", module: 'الطوارئ',
                    question: 'تنبيه ذعر من «'.($a->user?->name ?? '—').'»'.($a->place ? ' في '.$a->place->name : '').' — عالجه',
                    primary: ['label' => 'افتحه', 'url' => route('emergency.panic.show', $a)],
                    isOverdue: true, place: $a->place?->name, detailsUrl: route('emergency.panic.dashboard'), createdAt: $a->created_at,
                ));
            }
            foreach (WearableAlert::active()->with('wearable.user')->get() as $w) {
                $out->push(new Task(
                    key: "wearable:{$w->id}", module: 'الطوارئ',
                    question: 'تنبيه سوار «'.$w->getTypeLabel().'» من «'.($w->wearable?->user?->name ?? '—').'» — عالجه',
                    primary: ['label' => 'افتحه', 'url' => route('emergency.iot.wearables.alerts')],
                    isOverdue: true, detailsUrl: route('emergency.iot.wearables.dashboard'), createdAt: $w->created_at,
                ));
            }
        }
        return $out;
    }
}
