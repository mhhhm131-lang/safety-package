<?php

namespace App\Modules\Governance\Inbox;

use App\Core\Inbox\Task;
use App\Core\Inbox\TaskSource;
use App\Models\User;
use App\Modules\Emergency\Models\BuildingComplianceItem;
use App\Modules\Governance\Services\BuildingContext;
use App\Modules\Governance\Support\PlaceWordsAccess;
use Illuminate\Support\Collection;

/**
 * المرحلة ٣١-٢ (قرار ٩٠): موعد مادة في المقارنة — بطاقة واحدة لكل مبنى بعدد مواده التي يحين موعدها خلال سبعة أيام
 * (الرقم القائم في `DeadlineTasks::DAYS`) أو فات، ولم تصر «مستوفى» ولا «لا ينطبق هنا»؛ لمن يعدّل كلام المبنى
 * (`PlaceWordsAccess::canEditBuilding`)؛ تفتح شاشة المقارنة على أقرب مادة، وتختفي حين لا يبقى موعد.
 * قراءة فقط؛ الفعل في شاشة المقارنة بصلاحيتها.
 */
class ComplianceDeadlineTasks implements TaskSource
{
    public function tasksFor(User $user): Collection
    {
        $profile = $user->profile;
        if (!$profile || !$profile->is_active) return collect();
        $until = now()->addDays(DeadlineTasks::DAYS)->toDateString();
        $out = collect();
        foreach (BuildingContext::choices($user) as $building) {
            if (!PlaceWordsAccess::canEditBuilding($user, $building)) continue;
            $due = BuildingComplianceItem::where('building_id', $building->id)
                ->whereNotNull('due_date')->whereDate('due_date', '<=', $until)
                ->whereNotIn('status', ['met', 'na'])
                ->orderBy('due_date')->orderBy('sort')->get();
            if ($due->isEmpty()) continue;
            $first = $due->first();
            $late = $due->filter(fn ($r) => $r->due_date->lt(today()));
            $label = fn (BuildingComplianceItem $r) => ($r->isItem() ? 'البند '.$r->key : 'المادة '.$r->key).' «'.$r->title.'»';
            $url = route('app.compliance', ['b' => $building->id]).'#r'.$first->id;
            $out->push(new Task(
                key: "compliance:{$building->id}", module: 'الطوارئ',
                question: 'مقارنة '.$building->name.' مع اللائحة: '.$due->count().' '.($due->count() === 1 ? 'مادة' : 'مواد').' '
                    .($late->isNotEmpty() ? 'فات موعدها'.($late->count() < $due->count() ? ' أو يحين' : '') : 'يحين موعدها')
                    .' — أقربها '.$label($first).' ('.$first->due_date->format('Y-m-d').') — قيّمها',
                primary: ['label' => 'المقارنة', 'url' => $url],
                dueAt: $first->due_date, isOverdue: $late->isNotEmpty(),
                detailsUrl: $url, createdAt: $first->updated_at,
            ));
        }
        return $out;
    }
}
