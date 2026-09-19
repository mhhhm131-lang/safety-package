<?php

namespace App\Modules\Governance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\PlaceUnit;
use App\Modules\Governance\Models\UserProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * المرحلة ١٨-٣ (قرار ٤٧): «وحدات المكان» — شاشة واحدة لكل مكان يُدخل فيها صاحبه وحداته مرة واحدة.
 * المكاتب: الإدارات من الهيكل التنظيمي (لا تُكرَّر) ولكلٍّ دور وموقع. القاعات: لصق جدول (الرقم، الدور، السعة).
 */
class PlaceUnitsController extends Controller
{
    /** فهرس الأماكن التسعة بعدد وحداتها — مدخل «أريد أن… وحدات مكاني». */
    public function hub(Request $request): View
    {
        // المرحلة ١٩-٤ (قرار ٤٨): صفحة «الأماكن» = فسيفساء اللوحة + «صورة المبنى» لأدوار القرار (dashboard.html:602-632)
        $R = \App\Modules\Store\Services\InspectionDocReader::class;
        $user = Auth::user(); $role = $user->role();
        $ui = \App\Core\Permissions\PermissionRegistry::uiRole($role);
        $tiles = $R::placeTiles();
        $byCode = Place::all()->keyBy('code');
        // ٢٠-٥ (قرار ٥١): النطاق من الحساب — المعهد كله، أو الفرع، أو الوحدة، أو ما يغطيه، أو مكانه (كان: مدير الإدارة مكان إدارته وحده)
        $scope = \App\Modules\Governance\Services\ScopeService::forUser($user);
        if (!$scope->isAll()) $tiles = array_intersect_key($tiles, array_flip($scope->codes()));
        $decision = in_array($ui, ['fm', 'adm', 'exec', 'safety'], true);
        $k = $decision && array_key_exists((string) $request->query('k'), $R::KPI) ? (string) $request->query('k') : null;
        $counts = PlaceUnit::where('is_active', true)->selectRaw('place_id, count(*) as c')->groupBy('place_id')->pluck('c', 'place_id');
        return view('governance.places.units_hub', ['tiles' => $tiles, 'byCode' => $byCode, 'counts' => $counts, 'ui' => $ui,
            'kpis' => $decision ? $R::buildingKpis() : null, 'k' => $k, 'kList' => $k ? $R::reportsByKpi($k) : []]);
    }

    public function index(Place $place): View
    {
        $user = Auth::user();
        $types = PlaceUnit::typesFor($place->code);
        $units = PlaceUnit::where('place_id', $place->id)->where('is_active', true)->orderBy('type')->orderBy('sort')->orderBy('name')->get();
        $departments = collect();
        if (in_array('department', $types, true)) {
            $mine = UserProfile::where('user_id', $user->id)->value('organization_unit_id');
            $q = OrganizationUnit::where('place_id', $place->id)->where('is_active', true)->orderBy('order')->orderBy('name');
            // مدير الإدارة يرى إدارته فقط؛ من يدير الكل يرى الكل
            if (!in_array($user->role(), ['system_admin', 'system_staff', 'facilities_manager'], true)) $q->where('id', (int) $mine);
            $byUnit = $units->where('type', 'department')->keyBy('organization_unit_id');
            $departments = $q->get()->map(fn (OrganizationUnit $u) => ['unit' => $u, 'row' => $byUnit->get($u->id),
                'can' => PlaceUnit::canManage($user, $place, 'department', $u->id)]);
        }
        $manageTypes = array_values(array_filter($types, fn ($t) => $t !== 'department' && PlaceUnit::canManage($user, $place, $t)));
        return view('governance.places.units', [
            'place' => $place, 'types' => $types, 'manageTypes' => $manageTypes,
            'units' => $units->where('type', '!=', 'department')->values(), 'departments' => $departments,
            'canAny' => PlaceUnit::canManageAny($user, $place),
        ]);
    }

    public function store(Request $request, Place $place): RedirectResponse
    {
        $types = PlaceUnit::typesFor($place->code);
        $v = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', $types ?: ['-'])],
            'name' => ['required', 'string', 'max:120'],
            'floor' => ['nullable', 'string', 'max:60'], 'location' => ['nullable', 'string', 'max:200'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'], 'operator' => ['nullable', 'string', 'max:120'],
            'organization_unit_id' => ['nullable', 'integer', 'exists:organization_units,id'],
        ], ['type.in' => 'هذا النوع لا يخص هذا المكان.']);
        $ouId = $v['type'] === 'department' ? (int) ($v['organization_unit_id'] ?? 0) : null;
        abort_unless(PlaceUnit::canManage(Auth::user(), $place, $v['type'], $ouId), 403, 'ليس لك تعديل وحدات هذا المكان.');
        if ($v['type'] === 'department') {
            $ou = OrganizationUnit::where('id', $ouId)->where('place_id', $place->id)->firstOrFail();
            PlaceUnit::updateOrCreate(['place_id' => $place->id, 'type' => 'department', 'organization_unit_id' => $ou->id],
                ['name' => $ou->name, 'floor' => $v['floor'] ?? null, 'location' => $v['location'] ?? null, 'is_active' => true, 'created_by_id' => Auth::id()]);
            return redirect()->route('app.places.units.index', $place)->with('success', 'حُفظ موقع «'.$ou->name.'».');
        }
        PlaceUnit::updateOrCreate(['place_id' => $place->id, 'type' => $v['type'], 'name' => trim($v['name'])],
            ['floor' => $v['floor'] ?? null, 'location' => $v['location'] ?? null, 'capacity' => $v['capacity'] ?? null,
             'operator' => $v['operator'] ?? null, 'is_active' => true, 'created_by_id' => Auth::id()]);
        return redirect()->route('app.places.units.index', $place)->with('success', 'أُضيفت «'.trim($v['name']).'» ('.PlaceUnit::TYPE_LABELS[$v['type']].').');
    }

    /** لصق جدول: سطر لكل وحدة «الاسم/الرقم، الدور، السعة» (الفاصل فاصلة أو تبويب). */
    public function paste(Request $request, Place $place): RedirectResponse
    {
        $types = PlaceUnit::typesFor($place->code);
        $v = $request->validate(['type' => ['required', 'string', 'in:'.implode(',', array_diff($types, ['department']) ?: ['-'])], 'rows' => ['required', 'string', 'max:200000']],
            ['type.in' => 'هذا النوع لا يخص هذا المكان.']);
        abort_unless(PlaceUnit::canManage(Auth::user(), $place, $v['type']), 403, 'ليس لك تعديل وحدات هذا المكان.');
        $n = 0; $bad = 0;
        foreach (preg_split('/\r\n|\r|\n/', $v['rows']) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $cols = array_map('trim', preg_split('/[,\t،;]+/u', $line));
            $name = $cols[0] ?? '';
            if ($name === '') { $bad++; continue; }
            // الأرقام العربية (٢٥) تُقبل كالإنجليزية (25)
            $num = fn (?string $s) => $s === null ? null : str_replace(['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'], ['0','1','2','3','4','5','6','7','8','9'], $s);
            $cap = isset($cols[2]) && is_numeric($num($cols[2])) ? (int) $num($cols[2]) : null;
            PlaceUnit::updateOrCreate(['place_id' => $place->id, 'type' => $v['type'], 'name' => $name],
                ['floor' => $cols[1] ?? null, 'capacity' => $cap, 'is_active' => true, 'created_by_id' => Auth::id()]);
            $n++;
        }
        return redirect()->route('app.places.units.index', $place)->with('success', "أُدخلت {$n} وحدة".($bad ? " وتُجوهل {$bad} سطراً بلا اسم" : '').'.');
    }

    public function update(Request $request, Place $place, PlaceUnit $unit): RedirectResponse
    {
        abort_unless($unit->place_id === $place->id, 404);
        abort_unless(PlaceUnit::canManage(Auth::user(), $place, $unit->type, $unit->organization_unit_id), 403, 'ليس لك تعديل وحدات هذا المكان.');
        $v = $request->validate(['name' => ['required', 'string', 'max:120'], 'floor' => ['nullable', 'string', 'max:60'], 'location' => ['nullable', 'string', 'max:200'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'], 'operator' => ['nullable', 'string', 'max:120']]);
        if ($unit->type === 'department') unset($v['name']); // اسم الإدارة من الهيكل
        $unit->update($v);
        return redirect()->route('app.places.units.index', $place)->with('success', 'حُدّثت «'.$unit->name.'».');
    }

    public function destroy(Place $place, PlaceUnit $unit): RedirectResponse
    {
        abort_unless($unit->place_id === $place->id, 404);
        abort_unless(PlaceUnit::canManage(Auth::user(), $place, $unit->type, $unit->organization_unit_id), 403, 'ليس لك تعديل وحدات هذا المكان.');
        $unit->update(['is_active' => false]); // لا حذف: ما ارتبط بها من جولات وبلاغات يبقى
        return redirect()->route('app.places.units.index', $place)->with('success', 'أُزيلت «'.$unit->name.'» من القائمة.');
    }
}
