<?php

namespace App\Modules\Permit\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Governance\Models\Place;
use App\Modules\Permit\Models\PermitType;
use App\Modules\Permit\Models\PermitTypeConflictRule;
use App\Modules\Permit\Services\PermitDashboardService;
use Illuminate\Http\Request;

/** لوحة التصاريح، التقرير الشهري، وإعدادات سعة الأماكن وقواعد التعارض. */
class PermitDashboardController extends Controller
{
    public function __construct(private readonly PermitDashboardService $dashboard) {}

    public function index(Request $request)
    {
        $data = [
            'counts'           => $this->dashboard->countsByCategoryAndStatus(),
            'expiring'         => $this->dashboard->expiringSoon(30),
            'queue'            => $this->dashboard->reviewQueue(),
            'places'           => $this->dashboard->placeCapacities(),
            'conflicts'        => $this->dashboard->activeConflicts(),
            'deviations'       => $this->dashboard->deviations(),
            'equipment'        => $this->dashboard->equipment(),
            'gate'             => $this->dashboard->gateToday(),
            'controlsToReview' => $this->dashboard->controlsNeedingReview(),
        ];

        if ($request->wantsJson()) {
            return response()->json($data);
        }

        return view('modules.permits.dashboard', $data);
    }

    public function monthlyReport(Request $request)
    {
        $year  = (int) $request->query('year', now()->year);
        $month = max(1, min(12, (int) $request->query('month', now()->month)));
        $data  = $this->dashboard->monthlyReport($year, $month);

        if ($request->wantsJson()) {
            return response()->json($data);
        }

        return view('modules.permits.report', compact('data', 'year', 'month'));
    }

    // ── الإعدادات: سعة الأماكن وقواعد التعارض (صلاحية permit.zones) ──

    public function settings()
    {
        return view('modules.permits.settings', [
            'places' => Place::orderBy('sort')->get(),
            'rules'  => PermitTypeConflictRule::with(['permitTypeA', 'permitTypeB', 'place'])->orderByDesc('is_active')->get(),
            'types'  => PermitType::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    /** سعة المكان: قرار المستخدم بلا قيم افتراضية — الفراغ يعني «بلا حد». */
    public function updateCapacity(Request $request, Place $place)
    {
        $data = $request->validate([
            'max_workers'   => ['nullable', 'integer', 'min:0', 'max:9999'],
            'max_equipment' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ], [], ['max_workers' => 'حد العمال', 'max_equipment' => 'حد المعدات']);

        $place->update([
            'max_workers'   => ($data['max_workers'] ?? null) ?: null,
            'max_equipment' => ($data['max_equipment'] ?? null) ?: null,
        ]);

        return back()->with('ok', "حُدّثت سعة «{$place->name}».");
    }

    public function storeConflictRule(Request $request)
    {
        $data = $request->validate([
            'permit_type_a_id' => ['required', 'integer', 'exists:permit_types,id'],
            'permit_type_b_id' => ['required', 'integer', 'exists:permit_types,id', 'different:permit_type_a_id'],
            'place_id'         => ['nullable', 'integer', 'exists:places,id'],
            'severity'         => ['required', 'in:block,warn'],
            'reason'           => ['nullable', 'string', 'max:500'],
        ], ['permit_type_b_id.different' => 'لا يتعارض النوع مع نفسه.']);

        PermitTypeConflictRule::updateOrCreate([
            'permit_type_a_id' => $data['permit_type_a_id'],
            'permit_type_b_id' => $data['permit_type_b_id'],
            'place_id'         => $data['place_id'] ?? null,
        ], [
            'severity'  => $data['severity'],
            'reason'    => $data['reason'] ?? null,
            'is_active' => true,
        ]);

        return back()->with('ok', 'حُفظت قاعدة التعارض.');
    }

    public function toggleConflictRule(PermitTypeConflictRule $rule)
    {
        $rule->update(['is_active' => !$rule->is_active]);

        return back()->with('ok', $rule->is_active ? 'فُعّلت القاعدة.' : 'أُوقفت القاعدة.');
    }
}
