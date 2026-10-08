<?php

namespace App\Modules\Governance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Governance\Models\Place;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * الأماكن: الأصناف التسعة ثابتة (SOURCE.md)، ولكل مبنى أماكنه منها (٢٨-٢، قرار ٧٨).
 * الاسم يُعدَّل؛ والصنف الغائب في مبنى يُعطَّل لا يُحذف. أماكن الملز التسعة ثابتة لا تُعطَّل.
 */
class PlacesController extends Controller
{
    public function index(): View
    {
        $places = Place::with('building.branchUnit')->withCount('units')->orderBy('building_id')->orderBy('sort')->get();
        return view('governance.places.index', ['groups' => $places->groupBy('building_id'), 'mainId' => EmergencyBuilding::main()?->id]);
    }

    /** رمز QR لكل مكان (قرار ٢٠٢٦-٠٩-٠٨): يفتح صفحة بلاغ الشاغل والمكان محدد مسبقاً. ٢٨-٢: الفعّال فقط، مجمَّعاً بالمبنى */
    public function qr(?Place $place = null): View
    {
        $places = $place ? collect([$place->load('building')]) : Place::active()->with('building')->orderBy('building_id')->orderBy('sort')->get();
        abort_if($places->isEmpty(), 404);
        $places->each(fn (Place $p) => $p->qr_url = url('/incident?place='.$p->code));
        return view('governance.places.qr', ['places' => $places, 'groups' => $places->groupBy('building_id')]);
    }

    public function update(Request $request, Place $place): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:120']);
        $place->update($data);
        return back()->with('ok', "حُدّث اسم {$place->code}");
    }

    /** ٢٨-٢: تعطيل صنف لا يوجد في المبنى (أو إعادته). الملز ثابت بالمرجعية. */
    public function toggleActive(Place $place): RedirectResponse
    {
        if ($place->building_id === EmergencyBuilding::main()?->id) {
            return back()->with('error', 'أماكن الملز التسعة ثابتة بالمرجعية (٨+١) ولا تُعطَّل.');
        }
        $place->update(['is_active' => !$place->is_active]);
        return back()->with('ok', $place->is_active ? "أُعيد {$place->code} إلى القوائم" : "عُطّل {$place->code}: لا يوجد هذا الصنف في مبناه، ولا يظهر في القوائم");
    }
}
