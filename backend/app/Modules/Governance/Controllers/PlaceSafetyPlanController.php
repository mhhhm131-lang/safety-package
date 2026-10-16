<?php

namespace App\Modules\Governance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Emergency\Services\PlaceProfile as P;
use App\Modules\Governance\Models\AuditLog;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\SafetyPlan;
use App\Modules\Governance\Models\SafetyPlanTask;
use App\Modules\Governance\Services\SafetyPlanSync;
use App\Modules\Governance\Support\PlaceWordsAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * المرحلة ٣٠-١ (قرار ٨٩ «كلام المكان»): خطة السلامة في ملف المكان من الصفوف — الجدولان كما في الورقة،
 * وبجانب خانات الكلام «تعديل» و«لا ينطبق هنا» لمنسق المكان ومديره؛ المهمة لا تُعدَّل؛ ومسؤول السلامة يرى من عدّل ومتى وفي كذا.
 */
class PlaceSafetyPlanController extends Controller
{
    public function show(Request $request, Place $place): View
    {
        PlaceFileController::guardBuilding($place); // قرار ٨٣: المكان لمن هو في مبانيه
        $user = $request->user();
        app(SafetyPlanSync::class)->syncPlace($place); // الوثيقة تُزرع مرة؛ إن تغيّرت بصمتها تبع غيرُ المعدَّل
        $plan = SafetyPlan::where('place_id', $place->id)->with('tasks')->first();
        $canEdit = PlaceWordsAccess::canEdit($user, $place);
        $log = $plan && PlaceWordsAccess::seesLog($user)
            ? AuditLog::with('user')->where('model_name', 'SafetyPlanTask')->whereIn('object_id', $plan->tasks->pluck('id'))->latest('id')->limit(30)->get()
            : collect();
        return view('governance.places.safety-plan', [
            'place' => $place, 'plan' => $plan, 'byLayer' => $plan?->tasksByLayer() ?? [],
            'pl' => P::get($place->category, $place->building_id)['plans'],
            'canEdit' => $canEdit, 'log' => $log, 'openTask' => (int) old('_task', 0),
        ]);
    }

    public function update(Request $request, Place $place, SafetyPlanTask $task): RedirectResponse
    {
        $this->guard($request, $place, $task);
        $rules = ['not_applicable' => 'nullable|boolean', '_task' => 'nullable'];
        foreach (array_keys($task->columns()) as $f) $rules[$f] = $f === 'how_text' ? 'nullable|string|max:2000' : 'nullable|string|max:255';
        $in = $request->validate($rules); // «المهمة» ليست في القواعد فلا تُقبل
        $diff = $task->applyWords($in, (bool) ($in['not_applicable'] ?? false), $request->user());
        if (!$diff) return $this->back($place, $task, 'لا تغيير.');
        $task->save();
        $task->plan->refreshCounts();
        $labels = $task->columns() + ['not_applicable' => 'لا ينطبق هنا'];
        $parts = [];
        foreach ($diff as $f => [$old, $new]) $parts[] = $labels[$f].': «'.($old ?? '—').'» ← «'.($new ?? '—').'»';
        app('audit.logger')->log($request, 'edit_place_words', 'SafetyPlanTask', $task->id,
            $place->code.' · خطة السلامة · '.SafetyPlanTask::LAYERS[$task->layer].' · «'.mb_substr($task->task, 0, 60).'» — '.implode('؛ ', $parts));
        return $this->back($place, $task, 'حُفظ كلام '.$place->name.' في هذه المهمة'.($task->isEdited() ? '.' : ' — عاد كما في الوثيقة.'));
    }

    public function restore(Request $request, Place $place, SafetyPlanTask $task): RedirectResponse
    {
        $this->guard($request, $place, $task);
        if (!$task->isEdited()) return $this->back($place, $task, 'هذا الصف كما في الوثيقة أصلاً.');
        $task->restoreSource();
        $task->save();
        $task->plan->refreshCounts();
        app('audit.logger')->log($request, 'restore_place_words', 'SafetyPlanTask', $task->id,
            $place->code.' · خطة السلامة · «'.mb_substr($task->task, 0, 60).'» — استعادة الأصل من الوثيقة');
        return $this->back($place, $task, 'استُعيد الأصل من الوثيقة.');
    }

    private function guard(Request $request, Place $place, SafetyPlanTask $task): void
    {
        PlaceFileController::guardBuilding($place);
        abort_unless(PlaceWordsAccess::canEdit($request->user(), $place), 403, 'تعديل كلام الخطة '.PlaceWordsAccess::EDITORS.'.');
        abort_unless($task->plan && (int) $task->plan->place_id === (int) $place->id, 404, 'هذه المهمة ليست في خطة هذا المكان.');
    }

    private function back(Place $place, SafetyPlanTask $task, string $msg): RedirectResponse
    {
        return redirect(route('app.places.units.safety', $place, false).'#t'.$task->id)->with('ok', $msg);
    }
}
