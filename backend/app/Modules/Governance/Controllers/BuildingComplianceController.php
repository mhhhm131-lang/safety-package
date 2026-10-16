<?php

namespace App\Modules\Governance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Emergency\Models\BuildingComplianceItem;
use App\Modules\Emergency\Services\ComplianceSync;
use App\Modules\Governance\Models\AuditLog;
use App\Modules\Governance\Services\BuildingContext;
use App\Modules\Governance\Support\PlaceWordsAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * المرحلة ٣١-١ (قرار ٩٠): شاشة «المقارنة» لمبنى الجلسة من الصفوف — المواد الست عشرة وبنود ١٢ و١٣ و١٤، رقم الجاهزية،
 * «تعديل» على الحالة والوضع والإجراء والمكلّف والموعد والملاحظات لمن يحرّر كلام المبنى، والقراءة لكل حساب في مبناه.
 */
class BuildingComplianceController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();
        if ($request->filled('b')) BuildingContext::set($user, (int) $request->query('b'));
        $building = BuildingContext::current($user);
        app(ComplianceSync::class)->syncBuilding($building);
        $rows = BuildingComplianceItem::forBuilding($building->id);
        $articles = $rows->filter(fn ($r) => !$r->isItem())->values();
        $canEdit = PlaceWordsAccess::canEditBuilding($user, $building);
        $log = PlaceWordsAccess::seesLog($user) || $canEdit
            ? AuditLog::with('user')->where('model_name', 'BuildingComplianceItem')->whereIn('object_id', $rows->pluck('id'))->latest('id')->limit(30)->get()
            : collect();
        return view('governance.compliance', [
            'building' => $building, 'articles' => $articles, 'readiness' => BuildingComplianceItem::readiness($building->id),
            'canEdit' => $canEdit, 'log' => $log, 'openRow' => (int) old('_row', 0),
        ]);
    }

    public function update(Request $request, BuildingComplianceItem $item): RedirectResponse
    {
        $this->guard($request, $item);
        abort_if($item->hasItems(), 422, 'هذه المادة تُقرأ من بنودها — قيّم بنودها.');
        $in = $request->validate([
            'status' => 'nullable|string|in:'.implode(',', array_keys(BuildingComplianceItem::STATUSES)),
            'current_state' => 'nullable|string|max:2000', 'action' => 'nullable|string|max:2000', 'notes' => 'nullable|string|max:2000',
            'holder_name' => 'nullable|string|max:120', 'due_date' => 'nullable|date_format:Y-m-d', '_row' => 'nullable',
        ]); // العنوان ونص اللائحة وما أعدّه مسؤول السلامة ليست في القواعد فلا تُقبل
        $diff = $item->applyWords($in, $request->user());
        if (!$diff) return $this->back($item, 'لا تغيير.');
        $item->save();
        BuildingComplianceItem::flush();
        $labels = BuildingComplianceItem::WORD_LABELS;
        $parts = [];
        foreach ($diff as $f => [$old, $new]) {
            if ($f === 'status') { $old = BuildingComplianceItem::STATUSES[$old] ?? $old; $new = BuildingComplianceItem::STATUSES[$new] ?? $new; }
            $parts[] = $labels[$f].': «'.mb_substr((string) ($old ?? '—'), 0, 80).'» ← «'.mb_substr((string) ($new ?? '—'), 0, 80).'»';
        }
        app('audit.logger')->log($request, 'edit_building_words', 'BuildingComplianceItem', $item->id,
            ($item->building?->code ?? $item->building_id).' · المقارنة · '.($item->isItem() ? 'البند '.$item->key : 'المادة '.$item->key).' «'.mb_substr($item->title, 0, 60).'» — '.implode('؛ ', $parts));
        return $this->back($item, 'حُفظ كلام '.($item->building?->name ?? 'المبنى').' في '.($item->isItem() ? 'البند' : 'المادة').' «'.$item->title.'»'.($item->isEdited() ? '.' : ' — عاد كما في الوثيقة.'));
    }

    public function restore(Request $request, BuildingComplianceItem $item): RedirectResponse
    {
        $this->guard($request, $item);
        if (!$item->isEdited()) return $this->back($item, 'هذا الصف كما في الوثيقة أصلاً.');
        $item->restoreSource();
        $item->save();
        BuildingComplianceItem::flush();
        app('audit.logger')->log($request, 'restore_building_words', 'BuildingComplianceItem', $item->id,
            ($item->building?->code ?? $item->building_id).' · المقارنة · '.$item->key.' «'.mb_substr($item->title, 0, 60).'» — استعادة الأصل من الوثيقة');
        return $this->back($item, 'استُعيد الأصل من الوثيقة.');
    }

    private function guard(Request $request, BuildingComplianceItem $item): void
    {
        $user = $request->user();
        abort_unless(BuildingContext::choices($user)->contains('id', (int) $item->building_id), 403, 'هذا الصف في مبنى آخر.');
        abort_unless(PlaceWordsAccess::canEditBuilding($user, $item->building), 403, 'تعديل كلام المبنى لمدير الفرع ومنسق سلامته ومدير المرافق ومسؤول السلامة.');
    }

    private function back(BuildingComplianceItem $item, string $msg): RedirectResponse
    {
        return redirect(route('app.compliance', ['b' => $item->building_id], false).'#r'.$item->id)->with('ok', $msg);
    }
}
