<?php

namespace App\Modules\Governance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Emergency\Models\ResponsePlan;
use App\Modules\Emergency\Models\ResponsePlanStep;
use App\Modules\Emergency\Services\PlaceProfile as P;
use App\Modules\Emergency\Services\ResponsePlanSync;
use App\Modules\Governance\Models\AuditLog;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Support\PlaceWordsAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * المرحلة ٣٠-٢ (قرار ٨٩ «كلام المكان»): خطة الاستجابة في ملف المكان من الصفوف — المسارات والخطوات كما في الورقة،
 * وبجانب كلام المكان (من، أين، كيف، الهاتف) «تعديل» و«لا ينطبق هنا» لمنسق المكان ومديره؛ متى والنافذة والعنوان للصنف لا تُعدَّل؛
 * القائمة الحية وقت الطوارئ تقرأ هذه الصفوف. مسؤول السلامة يرى من عدّل ومتى وفي كذا.
 */
class PlaceResponsePlanController extends Controller
{
    public function show(Request $request, Place $place): View
    {
        PlaceFileController::guardBuilding($place); // قرار ٨٣
        abort_if($place->category === 'HZ-00', 404, 'مركز السلامة مركز قيادة بلا خطة استجابة.');
        $user = $request->user();
        app(ResponsePlanSync::class)->syncPlace($place);
        $plan = ResponsePlan::where('place_id', $place->id)->with('steps')->first();
        abort_unless($plan, 404, 'لم تُقرأ وثيقة خطة هذا الصنف بعد.');
        $canEdit = PlaceWordsAccess::canEdit($user, $place);
        $log = PlaceWordsAccess::seesLog($user)
            ? AuditLog::with('user')->where('model_name', 'ResponsePlanStep')->whereIn('object_id', $plan->steps->pluck('id'))->latest('id')->limit(30)->get()
            : collect();
        return view('governance.places.response-plan', [
            'place' => $place, 'plan' => $plan, 'byPath' => $plan->stepsByPath(),
            'pl' => P::get($place->category, $place->building_id)['plans'],
            'canEdit' => $canEdit, 'log' => $log, 'openStep' => (int) old('_step', 0),
        ]);
    }

    public function update(Request $request, Place $place, ResponsePlanStep $step): RedirectResponse
    {
        $this->guard($request, $place, $step);
        $in = $request->validate([
            'who_text' => 'nullable|string|max:255', 'where_text' => 'nullable|string|max:255', 'how_text' => 'nullable|string|max:2000',
            'phone_text' => 'nullable|string|max:60', 'not_applicable' => 'nullable|boolean', '_step' => 'nullable',
        ]); // العنوان ومتى والنافذة ليست في القواعد فلا تُقبل
        $diff = $step->applyWords($in, (bool) ($in['not_applicable'] ?? false), $request->user());
        if (!$diff) return $this->back($place, $step, 'لا تغيير.');
        $step->save();
        $step->plan->refreshCounts();
        $labels = ResponsePlanStep::WORD_LABELS + ['not_applicable' => 'لا ينطبق هنا'];
        $parts = [];
        foreach ($diff as $f => [$old, $new]) $parts[] = $labels[$f].': «'.($old ?? '—').'» ← «'.($new ?? '—').'»';
        app('audit.logger')->log($request, 'edit_place_words', 'ResponsePlanStep', $step->id,
            $place->code.' · خطة الاستجابة · '.$step->path_title.' · الخطوة '.$step->label.' «'.mb_substr($step->title, 0, 60).'» — '.implode('؛ ', $parts));
        return $this->back($place, $step, 'حُفظ كلام '.$place->name.' في هذه الخطوة'.($step->isEdited() ? '.' : ' — عاد كما في الوثيقة.'));
    }

    public function restore(Request $request, Place $place, ResponsePlanStep $step): RedirectResponse
    {
        $this->guard($request, $place, $step);
        if (!$step->isEdited()) return $this->back($place, $step, 'هذه الخطوة كما في الوثيقة أصلاً.');
        $step->restoreSource();
        $step->save();
        $step->plan->refreshCounts();
        app('audit.logger')->log($request, 'restore_place_words', 'ResponsePlanStep', $step->id,
            $place->code.' · خطة الاستجابة · الخطوة '.$step->label.' «'.mb_substr($step->title, 0, 60).'» — استعادة الأصل من الوثيقة');
        return $this->back($place, $step, 'استُعيد الأصل من الوثيقة.');
    }

    private function guard(Request $request, Place $place, ResponsePlanStep $step): void
    {
        PlaceFileController::guardBuilding($place);
        abort_unless(PlaceWordsAccess::canEdit($request->user(), $place), 403, 'تعديل كلام الخطة '.PlaceWordsAccess::EDITORS.'.');
        abort_unless($step->plan && (int) $step->plan->place_id === (int) $place->id, 404, 'هذه الخطوة ليست في خطة هذا المكان.');
    }

    private function back(Place $place, ResponsePlanStep $step, string $msg): RedirectResponse
    {
        return redirect(route('app.places.units.response', $place, false).'#s'.$step->id)->with('ok', $msg);
    }
}
