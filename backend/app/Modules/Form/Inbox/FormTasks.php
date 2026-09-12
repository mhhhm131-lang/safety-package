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
        return FormAssignment::where('assigned_to_id', $user->id)
            ->whereIn('status', [FormAssignment::STATUS_PENDING, FormAssignment::STATUS_OVERDUE])
            ->with('form:id,title,form_type')
            ->get()
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
            });
    }
}
