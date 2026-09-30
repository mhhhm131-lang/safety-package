<?php

namespace App\Modules\Form\Inbox;

use App\Core\Inbox\Task;
use App\Core\Inbox\TaskSource;
use App\Models\User;
use App\Modules\Form\Models\FormAssignment;
use Illuminate\Support\Collection;

/** «نماذجي» (FormController::mine) بصيغة مهام: نموذج مكلَّف به ولم يُعبَّأ ← «عبّئه». */
class FormTasks implements TaskSource
{
    public function tasksFor(User $user): Collection
    {
        return $this->reminders($user)->merge(FormAssignment::where('assigned_to_id', $user->id)
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
