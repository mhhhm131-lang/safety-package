<?php

namespace App\Modules\Governance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Emergency\Models\BuildingRoleCard;
use App\Modules\Emergency\Services\RoleCardSync;
use App\Modules\Emergency\Support\RoleCards;
use App\Modules\Governance\Models\AuditLog;
use App\Modules\Governance\Services\BuildingContext;
use App\Modules\Governance\Support\PlaceWordsAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * المرحلة ٣٠-٣ (قرار ٨٩ «كلام المكان»): بطاقات الأدوار الـ٢١ في مبنى الجلسة من الصفوف — الاسم والوصف والمهام والشاغل وهاتفه
 * كلام المبنى بـ«تعديل» لمن يحرّر كلام المبنى؛ الرقم والفئة والملف للصنف. القراءة لكل حساب في مبناه (مبدّل المبنى لمن يرى أكثر).
 */
class BuildingRoleCardsController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();
        if ($request->filled('b')) BuildingContext::set($user, (int) $request->query('b')); // مبنى من مبانيه وإلا تجاهُل
        $building = BuildingContext::current($user);
        app(RoleCardSync::class)->syncBuilding($building);
        $cards = BuildingRoleCard::where('building_id', $building->id)->orderBy('card_no')->get();
        $byCategory = [];
        foreach (RoleCards::CATEGORIES as $key => $label) $byCategory[$key] = $cards->where('category', $key)->values();
        $canEdit = PlaceWordsAccess::canEditBuilding($user, $building);
        $log = PlaceWordsAccess::seesLog($user) || $canEdit
            ? AuditLog::with('user')->where('model_name', 'BuildingRoleCard')->whereIn('object_id', $cards->pluck('id'))->latest('id')->limit(30)->get()
            : collect();
        return view('governance.role-cards', [
            'building' => $building, 'cards' => $cards, 'byCategory' => $byCategory, 'canEdit' => $canEdit, 'log' => $log,
            'edited' => $cards->filter(fn ($c) => $c->isEdited())->count(), 'openCard' => (int) old('_card', 0),
        ]);
    }

    public function update(Request $request, BuildingRoleCard $card): RedirectResponse
    {
        $this->guard($request, $card);
        $in = $request->validate([
            'name' => 'nullable|string|max:160', 'description' => 'nullable|string|max:255',
            'tasks_proactive' => 'nullable|string|max:4000', 'tasks_operational' => 'nullable|string|max:4000', 'tasks_response' => 'nullable|string|max:4000',
            'holder_name' => 'nullable|string|max:120', 'holder_phone' => 'nullable|string|max:60',
            'not_applicable' => 'nullable|boolean', '_card' => 'nullable',
        ]); // الرقم والفئة والملف والتبعية والسند ليست في القواعد فلا تُقبل
        $diff = $card->applyWords($in, (bool) ($in['not_applicable'] ?? false), $request->user());
        if (!$diff) return $this->back($card, 'لا تغيير.');
        $card->save();
        BuildingRoleCard::flush();
        $labels = BuildingRoleCard::WORD_LABELS + ['not_applicable' => 'لا ينطبق هنا'];
        $parts = [];
        foreach ($diff as $f => [$old, $new]) $parts[] = $labels[$f].': «'.mb_substr((string) ($old ?? '—'), 0, 80).'» ← «'.mb_substr((string) ($new ?? '—'), 0, 80).'»';
        app('audit.logger')->log($request, 'edit_building_words', 'BuildingRoleCard', $card->id,
            ($card->building?->code ?? $card->building_id).' · بطاقة '.$card->card_no.' «'.$card->name.'» — '.implode('؛ ', $parts));
        return $this->back($card, 'حُفظ كلام '.($card->building?->name ?? 'المبنى').' في بطاقة «'.$card->name.'»'.($card->isEdited() ? '.' : ' — عادت كما في الملف.'));
    }

    public function restore(Request $request, BuildingRoleCard $card): RedirectResponse
    {
        $this->guard($request, $card);
        if (!$card->isEdited()) return $this->back($card, 'هذه البطاقة كما في الملف أصلاً.');
        $card->restoreSource();
        $card->save();
        BuildingRoleCard::flush();
        app('audit.logger')->log($request, 'restore_building_words', 'BuildingRoleCard', $card->id,
            ($card->building?->code ?? $card->building_id).' · بطاقة '.$card->card_no.' «'.$card->name.'» — استعادة الأصل من الملف');
        return $this->back($card, 'استُعيد الأصل من الملف.');
    }

    private function guard(Request $request, BuildingRoleCard $card): void
    {
        $user = $request->user();
        abort_unless(BuildingContext::choices($user)->contains('id', (int) $card->building_id), 403, 'هذه البطاقة في مبنى آخر.');
        abort_unless(PlaceWordsAccess::canEditBuilding($user, $card->building), 403, 'تعديل كلام المبنى لمدير الفرع ومنسق سلامته ومدير المرافق ومسؤول السلامة.');
    }

    private function back(BuildingRoleCard $card, string $msg): RedirectResponse
    {
        return redirect(route('app.role-cards', ['b' => $card->building_id], false).'#c'.$card->id)->with('ok', $msg);
    }
}
