<?php

namespace App\Modules\Risk\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Governance\Models\Place;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskPhase;
use Illuminate\Http\Request;

/**
 * المرحلة ١٢-٢ (قرار ٣٥): كتاب المعهد صفحة عامة للتوعية — بلا دخول.
 * ٨ أصناف ← فروع ← أخطار (السجل العام المعتمد)، بحث، ولكل خطر «رأيت هذا؟ بلّغ» يفتح البلاغ والخطر محدد.
 * قراءة فقط من الكتاب القائم؛ لا كتابة ولا تفاصيل داخلية (الدرجات والجهات تبقى خلف الدخول).
 */
class HazardsController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $place = (string) $request->query('place', '');
        $risks = Risk::where('risk_type', 'reference')->whereIn('status', ['approved', 'active'])
            ->with(['category:id,name', 'subCategory:id,name,category_id', 'phases' => fn ($p) => $p->where('phase', RiskPhase::PHASE_OPERATIONAL)])
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('title', 'like', "%$q%")->orWhere('code', 'like', "%$q%")->orWhere('description', 'like', "%$q%")))
            ->orderBy('code')->get();
        $tree = $risks->groupBy(fn (Risk $r) => $r->category?->name ?? '—')->map(fn ($rs) => $rs->groupBy(fn (Risk $r) => $r->subCategory?->name ?? '—'));
        $categories = RiskCategory::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        return view('modules.risks.hazards', [
            'tree' => $tree, 'q' => $q, 'place' => $place, 'total' => $risks->count(), 'categories' => $categories,
            'places' => Place::orderBy('sort')->get(['code', 'name']),
        ]);
    }
}
