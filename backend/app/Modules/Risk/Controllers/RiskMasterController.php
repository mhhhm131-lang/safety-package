<?php

namespace App\Modules\Risk\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskPhase;
use App\Modules\Risk\Services\RiskService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * كتاب المخاطر (master) — المرجع الذي يغذّي السجل العام للمعهد. يملكه مسؤول السلامة.
 * منقول من OHSMS بلا القطاعات الاقتصادية والمهن.
 */
class RiskMasterController extends Controller
{
    public function __construct(protected RiskService $riskService) {}

    public function index(Request $request)
    {
        $query = Risk::where('risk_type', 'master')
            ->with(['category', 'assignedCoordinator', 'assignedFieldTeam', 'phases', 'phases.causes', 'phases.affectedGroups',
                'phases.responsibleOrgUnit', 'phases.responsibleUser']);
        if ($search = $request->get('search')) {
            $query->where(fn ($q) => $q->where('title', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"));
        }
        if ($catId = $request->get('category_id')) {
            $query->where('category_id', $catId);
        }
        if ($sev = $request->get('severity_filter')) {
            match ($sev) {
                'critical' => $query->where('risk_score', '>=', 15),
                'high' => $query->whereBetween('risk_score', [7, 14]),
                'low' => $query->where('risk_score', '<', 7),
                default => null,
            };
        }
        $risks = $query->latest()->paginate(25);
        $categories = RiskCategory::where('is_active', true)->orderBy('name')->get();
        return view('modules.risks.master', compact('risks', 'categories'));
    }

    public function create()
    {
        $categories = RiskCategory::where('is_active', true)->with('subCategories')->orderBy('name')->get();
        return view('modules.risks.master_create', compact('categories'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules());
        try {
            $risk = $this->riskService->createRisk(Auth::id(), collect($validated)->except(['phases'])->all(), 'master');
            $this->riskService->persistAllPhases($risk, $validated['phases'] ?? []);
            return redirect()->route('risk.master.index')->with('success', 'أُضيف الخطر إلى الكتاب بمراحله الثلاث.');
        } catch (\Throwable $e) {
            return redirect()->back()->withInput()->with('error', 'حدث خطأ: '.$e->getMessage());
        }
    }

    public function edit(Risk $risk)
    {
        $categories = RiskCategory::where('is_active', true)->with('subCategories')->orderBy('name')->get();
        $this->riskService->ensurePhases($risk);
        $risk->load(['phases.causes', 'phases.affectedGroups', 'phases.affectedGroupDetails']);
        return view('modules.risks.master_edit', compact('risk', 'categories'));
    }

    public function update(Request $request, Risk $risk)
    {
        $validated = $request->validate($this->rules());
        try {
            $this->riskService->updateRisk($risk, Auth::id(), collect($validated)->except(['phases'])->all());
            $this->riskService->ensurePhases($risk);
            $this->riskService->persistAllPhases($risk, $validated['phases'] ?? []);
            return redirect()->route('risk.master.index')->with('success', 'حُدّث الخطر في الكتاب بمراحله الثلاث.');
        } catch (\Throwable $e) {
            return redirect()->back()->withInput()->with('error', 'فشل التحديث: '.$e->getMessage());
        }
    }

    public function destroy(Risk $risk)
    {
        try {
            $risk->delete();
            return redirect()->route('risk.master.index')->with('success', 'حُذف الخطر من الكتاب.');
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', 'تعذّر الحذف: '.$e->getMessage());
        }
    }

    /** استيراد CSV: العنوان، الوصف، الخطورة، الاحتمالية، الإجراء التصحيحي، الإجراء الوقائي (تُوضع في المرحلة الاستباقية). */
    public function import(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);
        try {
            $count = 0;
            if (($handle = fopen($request->file('file')->getRealPath(), 'r')) !== false) {
                fgetcsv($handle);
                while (($row = fgetcsv($handle)) !== false) {
                    if (count($row) < 4) continue;
                    $risk = $this->riskService->createRisk(Auth::id(), [
                        'title' => $row[0] ?? '', 'description' => $row[1] ?? '',
                        'severity' => (int) ($row[2] ?? 1), 'likelihood' => (int) ($row[3] ?? 1),
                    ], 'master');
                    $risk->phases()->where('phase', RiskPhase::PHASE_PROACTIVE)->first()
                        ?->update(['corrective_action' => $row[4] ?? null, 'preventive_action' => $row[5] ?? null]);
                    $count++;
                }
                fclose($handle);
            }
            return redirect()->route('risk.master.index')->with('success', "استُورد {$count} خطراً.");
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', 'فشل الاستيراد: '.$e->getMessage());
        }
    }

    public function template(): StreamedResponse
    {
        $headers = ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="master_risk_template.csv"'];
        return response()->stream(function () {
            $h = fopen('php://output', 'w');
            fprintf($h, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($h, ['العنوان', 'الوصف', 'الخطورة (1-5)', 'الاحتمالية (1-5)', 'الإجراء التصحيحي', 'الإجراء الوقائي']);
            fputcsv($h, ['مثال: السقوط من ارتفاع', 'سقوط العمال من السقالات', '4', '3', 'حواجز وشباك أمان', 'تفتيش دوري لأماكن العمل المرتفعة']);
            fclose($h);
        }, 200, $headers);
    }

    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'category_id' => ['required', 'integer', 'exists:risk_categories,id'],
            'sub_category_id' => ['nullable', 'integer', 'exists:risk_sub_categories,id'],
            'risk_type_category_id' => ['nullable', 'integer', 'exists:risk_causes,id'],
            'severity' => ['required', 'integer', 'min:1', 'max:5'],
            'likelihood' => ['required', 'integer', 'min:1', 'max:5'],
            'benefit' => ['nullable', 'string', 'max:5000'],
            'contact_channel' => ['nullable', 'string', 'max:200'],
            'legal_reference' => ['nullable', 'string', 'max:500'],
            'phases' => ['nullable', 'array'],
            'phases.*.preventive_action' => ['nullable', 'string', 'max:5000'],
            'phases.*.corrective_action' => ['nullable', 'string', 'max:5000'],
            'phases.*.residual_assessment' => ['nullable', 'string', 'max:5000'],
            'phases.*.responsible_org_unit_text' => ['nullable', 'string', 'max:200'],
            'phases.*.responsible_user_text' => ['nullable', 'string', 'max:200'],
            'phases.*.cause_names' => ['nullable', 'array'],
            'phases.*.cause_names.*' => ['nullable', 'string', 'max:200'],
            'phases.*.affected_group_ids' => ['nullable', 'array'],
            'phases.*.affected_group_ids.*' => ['integer', 'exists:affected_groups,id'],
            'phases.*.affected_impact' => ['nullable', 'array'],
            'phases.*.affected_rep_scope' => ['nullable', 'array'],
        ];
    }
}
