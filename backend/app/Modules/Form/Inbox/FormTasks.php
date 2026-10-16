<?php

namespace App\Modules\Form\Inbox;

use App\Core\Inbox\Task;
use App\Core\Inbox\TaskSource;
use App\Models\User;
use App\Modules\Form\Models\FormAssignment;
use App\Modules\Form\Models\FormRound;
use App\Modules\Form\Services\FormVisibilityService;
use App\Modules\Form\Services\RoundService;
use Illuminate\Support\Collection;

/** «نماذجي» (FormController::mine) بصيغة مهام: نموذج مكلَّف به ولم يُعبَّأ ← «عبّئه». */
class FormTasks implements TaskSource
{
    public function tasksFor(User $user): Collection
    {
        return $this->rounds($user)->merge($this->reminders($user))->merge(FormAssignment::where('assigned_to_id', $user->id)
            ->whereIn('status', [FormAssignment::STATUS_PENDING, FormAssignment::STATUS_OVERDUE])
            ->with('form:id,title,form_type')
            ->get()->toBase()
            ->map(function (FormAssignment $a) {
                $due = $a->due_date;
                $overdue = $a->status === FormAssignment::STATUS_OVERDUE || ($due && $due->endOfDay()->isPast());
                return new Task(
                    key: "form:{$a->id}",
                    module: 'النماذج',
                    question: 'نموذج «'.($a->form?->title ?? '—').'» ينتظر تعبئتك',
                    primary: ['label' => 'عبّئه', 'url' => route('forms.fill', $a->form_id)],
                    dueAt: $due,
                    isOverdue: $overdue,
                    detailsUrl: route('forms.mine'),
                    createdAt: $a->created_at,
                );
            }));
    }

    /**
     * ٢٩-٤ (قرار ٨٨): مهام الجولة.
     *   المدير: بطاقة إدارته مكتملة والجولة لدى الإدارة ← «اعتمدها: البيانات صحيحة وأعتمدها» (تختفي بالاعتماد).
     *   مسؤول السلامة والمناوب: عادت بطاقة إدارة (اعتمدها المدير) ← «افتح الجولة» (٢٩-٥: «أرسل الإقرارات»).
     */
    private function rounds(User $user): Collection
    {
        $out = collect();

        $mine = FormRound::where('state', FormRound::STATE_SENT)
            ->whereHas('assignments', fn ($q) => $q->withoutGlobalScope('building')->where('assigned_to_id', $user->id)->where('status', FormAssignment::STATUS_COMPLETED))
            ->with('organizationUnit')->get();
        foreach ($mine as $r) {
            if (!RoundService::isManagerOf($user, (int) $r->organization_unit_id)) continue;
            $out->push(new Task(
                key: "round:{$r->id}:approve",
                module: 'النماذج',
                question: 'بطاقة إدارتك «'.($r->organizationUnit?->name ?? '—').'» مكتملة — اعتمدها: «البيانات صحيحة وأعتمدها»',
                primary: ['label' => 'افتح الجولة', 'url' => route('forms.rounds.show', $r)],
                detailsUrl: route('forms.rounds.show', $r),
                createdAt: $r->created_at,
            ));
        }

        if (in_array($user->role(), ['system_admin', 'system_staff'], true) && $user->can_('form.send')) {
            $q = FormRound::where('state', FormRound::STATE_DONE)->with('organizationUnit');
            app(FormVisibilityService::class)->apply($q, $user);
            foreach ($q->get() as $r) {
                $out->push(new Task(
                    key: "round:{$r->id}:returned",
                    module: 'النماذج',
                    // ٢٩-٥: الحالة «اعتمدها المدير» تبقى حين لا يُرسل النظام الإقرار (لا «نعم» أو لا موظفين بحساب) — السبب في سجل الجولة
                    question: 'عادت بطاقة إدارة «'.($r->organizationUnit?->name ?? '—').'» (الجولة '.$r->round_no.') ولم يُرسل الإقرار السري — انظر سجل الجولة',
                    primary: ['label' => 'افتح الجولة', 'url' => route('forms.rounds.show', $r)],
                    detailsUrl: route('forms.rounds.show', $r),
                    createdAt: $r->approved_at ?? $r->created_at,
                ));
            }
        }

        return $out;
    }

    /**
     * ٢٧-ب (قرار ٦٧): نموذج أرسلتُه وفات موعده وفيه من لم يعبّئ ولم يُذكَّر ← «ذكّرهم» مرة (التذكير يختم `reminded_at` فتختفي).
     * الموعد هو `due_date` الذي أدخله المرسل — تكليف بلا موعد لا يتأخر. لمن يملك الإرسال (سياسة النموذج send).
     */
    private function reminders(User $user): Collection
    {
        $late = FormAssignment::where('assigned_by_id', $user->id)
            ->whereIn('status', [FormAssignment::STATUS_PENDING, FormAssignment::STATUS_OVERDUE])
            ->whereNull('reminded_at')
            ->where(fn ($q) => $q->where('status', FormAssignment::STATUS_OVERDUE)->orWhere('due_date', '<', now()->toDateString()))
            ->with('form')->get()->toBase()->groupBy('form_id'); // مجموعة عادية: الناتج مهام لا نماذج

        return $late->filter(fn ($list) => $list->first()->form && $user->can('send', $list->first()->form))
            ->map(function ($list, $formId) {
                $form = $list->first()->form;
                return new Task(
                    key: "formremind:{$formId}",
                    module: 'النماذج',
                    question: 'نموذج «'.$form->title.'»: فات موعده ولم يعبّئه: '.$list->count().' — ذكّرهم',
                    primary: ['label' => 'ذكّرهم', 'url' => route('forms.remind-all', $form), 'method' => 'POST'],
                    secondary: ['label' => 'المتابعة', 'url' => route('forms.tracking', $form)],
                    dueAt: $list->min('due_date'),
                    isOverdue: true,
                    detailsUrl: route('forms.tracking', $form),
                    createdAt: $list->min('created_at'),
                );
            })->values();
    }
}
