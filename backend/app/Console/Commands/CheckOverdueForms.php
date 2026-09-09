<?php

namespace App\Console\Commands;

use App\Modules\Form\Models\FormAssignment;
use App\Modules\Form\Services\FormNotifier;
use Illuminate\Console\Command;

/**
 * مسح يومي: تكليف بانتظار التعبئة مضت مهلته يصير «متأخراً» ويُنبَّه صاحبه ومن كلّفه.
 * **إصلاح ٥-٨:** حالة «متأخر» كانت معرَّفة في OHSMS ولا يضبطها شيء.
 */
class CheckOverdueForms extends Command
{
    protected $signature = 'forms:check-overdue {--dry-run : عرض ما سيُعلَّم بلا تغيير}';

    protected $description = 'تعليم تكليفات النماذج التي تجاوزت مهلتها بأنها متأخرة وتنبيه أصحابها.';

    public function handle(FormNotifier $notifier): int
    {
        $dry = (bool) $this->option('dry-run');

        $overdue = FormAssignment::query()
            ->where('status', FormAssignment::STATUS_PENDING)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->with(['form:id,title', 'assignedTo:id,name'])
            ->get();

        $count = 0;
        foreach ($overdue as $assignment) {
            $this->line("التكليف #{$assignment->id} — {$assignment->form?->title} — {$assignment->assignedTo?->name}"
                ." (المهلة {$assignment->due_date?->format('Y-m-d')}) ← متأخر");
            if ($dry) {
                continue;
            }
            $assignment->update(['status' => FormAssignment::STATUS_OVERDUE]);
            $notifier->overdue($assignment);
            $count++;
        }

        $this->info(sprintf('%s: %d تكليفاً متأخراً.', $dry ? 'عرض فقط' : 'تم', $dry ? $overdue->count() : $count));

        return self::SUCCESS;
    }
}
