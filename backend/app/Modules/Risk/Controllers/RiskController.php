<?php

namespace App\Modules\Risk\Controllers;

use App\Core\Traits\AppliesOrgUnitScope;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Risk\Models\AffectedGroup;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskCause;
use App\Modules\Risk\Models\RiskNote;
use App\Modules\Risk\Models\RiskSubCategory;
use App\Modules\Risk\Services\RiskCopyService;
use App\Modules\Risk\Services\RiskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * السجل العام (reference) وسجل الإدارات/الأماكن (active) والاعتماد — منقول من OHSMS
 * بلا tenant/سياق المشاريع/المهن. نطاق الرؤية بالوحدة التنظيمية عبر AppliesOrgUnitScope.
 */
class RiskController extends Controller
{
    use AppliesOrgUnitScope;

    public function __construct(protected RiskService $riskService, protected RiskCopyService $riskCopyService) {}

    // ── السجل الفعلي (active) ──

    public function index(Request $request)
    {
        return $this->activeIndex($request);
    }

    public function activeIndex(Request $request)
    {
        $query = Risk::where('risk_type', 'active')->with([
            'category', 'organizationUnit', 'place', 'createdBy', 'assignedCoordinator', 'assignedFieldTeam',
            'phases', 'phases.causes', 'phases.affectedGroups', 'phases.responsibleOrgUnit', 'phases.responsibleUser',
        ]);
        $this->scopeToUserOrgUnit($query);
        if ($place = $request->input('place')) {
            $query->whereHas('place', fn ($q) => $q->where('code', $place));
        }
        $this->applyFilters($query, $request);
        $risks = $query->latest()->paginate(25);
        $categories = RiskCategory::where('is_active', true)->orderBy('name')->get();
        return view('modules.risks.active', compact('risks', 'categories'));
    }

    private function applyFilters($query, Request $request): void
    {
        if ($search = $request->input('search')) {
            $query->where(fn ($q) => $q->where('title', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")->orWhere('description', 'like', "%{$search}%"));
        }
        if ($categoryId = $request->input('category_id')) $query->where('category_id', $categoryId);
        if ($status = $request->input('status')) $query->where('status', $status);
        if ($sev = $request->input('severity_filter')) {
            match ($sev) {
                'critical' => $query->where('risk_score', '>=', 15),
                'high' => $query->whereBetween('risk_score', [7, 14]),
                'low' => $query->where('risk_score', '<', 7),
                default => null,
            };
        }
    }

    public function create()
    {
        return $this->activeCreate();
    }

    public function store(Request $request)
    {
        return $this->activeStore($request);
    }

    public function activeCreate()
    {
        return view('modules.risks.active_create', $this->formData());
    }

    public function activeStore(Request $request)
    {
        $validated = $request->validate(array_merge($this->referenceValidationRules(), $this->activeExtraRules()));
        try {
            $validated['title'] = $this->deriveTitle($validated['risk_type_category_id'] ?? null, $validated['sub_category_id'] ?? null);
            $validated['description'] = $validated['title'];
            $risk = $this->riskService->createRisk(Auth::id(), collect($validated)->except(['phases'])->all(), 'active');
            $this->riskService->persistAllPhases($risk, $validated['phases'] ?? []);
            return redirect()->route('risk.active.index')->with('success', 'أُنشئ الخطر في السجل الفعلي بمراحله الثلاث.');
        } catch (\Throwable $e) {
            return redirect()->back()->withInput()->with('error', 'حدث خطأ: '.$e->getMessage());
        }
    }

    public function activeEdit(int $risk)
    {
        $risk = Risk::findOrFail($risk);
        $this->authorizeScope($risk);
        $this->riskService->ensurePhases($risk);
        $risk->load(['phases.causes', 'phases.affectedGroups', 'phases.affectedGroupDetails']);
        return view('modules.risks.active_edit', $this->formData() + compact('risk'));
    }

    public function activeUpdate(Request $request, int $risk)
    {
        $risk = Risk::findOrFail($risk);
        $this->authorizeScope($risk);
        $validated = $request->validate(array_merge($this->referenceValidationRules(), $this->activeExtraRules()));
        try {
            $derived = $this->deriveTitle($validated['risk_type_category_id'] ?? null, $validated['sub_category_id'] ?? null);
            $validated['title'] = $derived !== 'خطر غير محدد' ? $derived : ($risk->title ?: 'خطر غير محدد');
            $validated['description'] = $validated['title'];
            $this->riskService->updateRisk($risk, Auth::id(), collect($validated)->except(['phases'])->all());
            $this->riskService->persistAllPhases($risk, $validated['phases'] ?? []);
            return redirect()->route('risk.active.index')->with('success', 'حُدّث الخطر الفعلي بمراحله الثلاث.');
        } catch (\Throwable $e) {
            return redirect()->back()->withInput()->with('error', 'فشل التحديث: '.$e->getMessage());
        }
    }

    public function edit(Risk $risk)
    {
        return $this->activeEdit($risk->id);
    }

    public function update(Request $request, Risk $risk)
    {
        return $this->activeUpdate($request, $risk->id);
    }

    public function destroy(Risk $risk)
    {
        if ($risk->status !== 'draft') {
            return redirect()->route('risk.index')->with('error', 'لا يُحذف إلا خطر في حالة مسودة.');
        }
        $risk->delete();
        return redirect()->route('risk.index')->with('success', 'حُذف الخطر.');
    }

    // ── السجل العام (reference) ──

    public function referenceIndex(Request $request)
    {
        $query = Risk::where('risk_type', 'reference')->with([
            'category', 'assignedCoordinator', 'assignedFieldTeam',
            'phases', 'phases.causes', 'phases.affectedGroups', 'phases.responsibleOrgUnit', 'phases.responsibleUser',
        ]);
        $this->applyFilters($query, $request);
        $risks = $query->latest()->paginate(25);
        $categories = RiskCategory::where('is_active', true)->orderBy('name')->get();
        return view('modules.risks.reference', compact('risks', 'categories'));
    }

    public function referenceCreate()
    {
        return view('modules.risks.reference_create', $this->formData());
    }

    public function referenceStore(Request $request)
    {
        $validated = $request->validate($this->referenceValidationRules());
        try {
            $validated['title'] = $this->deriveTitle($validated['risk_type_category_id'] ?? null, $validated['sub_category_id'] ?? null);
            $validated['description'] = $validated['title'];
            $risk = $this->riskService->createRisk(Auth::id(), collect($validated)->except(['phases'])->all(), 'reference');
            $this->riskService->persistAllPhases($risk, $validated['phases'] ?? []);
            return redirect()->route('risk.reference.index')->with('success', 'أُنشئ الخطر في السجل العام بمراحله الثلاث.');
        } catch (\Throwable $e) {
            return redirect()->back()->withInput()->with('error', 'حدث خطأ: '.$e->getMessage());
        }
    }

    public function referenceEdit(Risk $risk)
    {
        $this->riskService->ensurePhases($risk);
        $risk->load(['phases.causes', 'phases.affectedGroups', 'phases.affectedGroupDetails']);
        return view('modules.risks.reference_edit', $this->formData() + compact('risk'));
    }

    public function referenceUpdate(Request $request, Risk $risk)
    {
        $validated = $request->validate($this->referenceValidationRules());
        try {
            $derived = $this->deriveTitle($validated['risk_type_category_id'] ?? null, $validated['sub_category_id'] ?? null);
            $validated['title'] = $derived !== 'خطر غير محدد' ? $derived : ($risk->title ?: 'خطر غير محدد');
            $validated['description'] = $validated['title'];
            $this->riskService->updateRisk($risk, Auth::id(), collect($validated)->except(['phases'])->all());
            $this->riskService->persistAllPhases($risk, $validated['phases'] ?? []);
            return redirect()->route('risk.reference.index')->with('success', 'حُدّث الخطر المرجعي بمراحله الثلاث.');
        } catch (\Throwable $e) {
            return redirect()->back()->withInput()->with('error', 'فشل التحديث: '.$e->getMessage());
        }
    }

    // ── التفعيل: من السجل العام إلى سجل إدارة/مكان ──

    public function activateForm(int $risk)
    {
        $risk = Risk::findOrFail($risk);
        $this->riskService->ensurePhases($risk);
        $risk->load(['category', 'subCategory', 'phases.causes', 'phases.affectedGroups', 'phases.affectedGroupDetails']);
        return view('modules.risks.activate', $this->formData() + compact('risk'));
    }

    public function activate(Request $request, int $risk)
    {
        $validated = $request->validate(array_merge([
            'scope_type' => ['required', 'string', 'in:general,org_unit'],
            'organization_unit_id' => ['nullable', 'integer', 'exists:organization_units,id'],
            'place_id' => ['nullable', 'integer', 'exists:places,id'],
            'severity' => ['required', 'integer', 'min:1', 'max:5'],
            'likelihood' => ['required', 'integer', 'min:1', 'max:5'],
            'legal_reference' => ['nullable', 'string', 'max:500'],
        ], $this->activeExtraRules(), $this->phaseRules()));
        // مدير الإدارة يفعّل لوحدته فقط
        $profile = $this->userProfile();
        if ($profile && !$this->isGlobalScopeRole()) {
            $allowed = OrganizationUnit::descendantIdsOf($profile->organization_unit_id);
            if (($validated['scope_type'] ?? '') !== 'org_unit' || !in_array((int) ($validated['organization_unit_id'] ?? 0), $allowed, true)) {
                return redirect()->back()->withInput()->with('error', 'يمكنك تفعيل الخطر لإدارتك أو أقسامها فقط.');
            }
        }
        try {
            $reference = Risk::findOrFail($risk);
            $this->riskService->activateFromReference($reference, Auth::id(), $validated);
            return redirect()->route('risk.active.index')->with('success', 'فُعّل الخطر في سجل الإدارة بمراحله الثلاث.');
        } catch (\Throwable $e) {
            return redirect()->back()->withInput()->with('error', 'حدث خطأ: '.$e->getMessage());
        }
    }

    // ── الاعتماد ──

    public function approvalQueue()
    {
        $query = Risk::where('status', 'pending_approval')->with(['category', 'subCategory', 'createdBy']);
        $this->scopeToUserOrgUnit($query);
        $risks = $query->latest()->paginate(25);
        return view('modules.risks.approval', compact('risks'));
    }

    public function submit(Risk $risk)
    {
        try {
            $this->riskService->submitForApproval($risk, Auth::id());
            return redirect()->back()->with('success', 'قُدّم الخطر للاعتماد.');
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', 'تعذّر التقديم: '.$e->getMessage());
        }
    }

    public function approve(Request $request, Risk $risk)
    {
        $request->validate(['note' => ['nullable', 'string', 'max:5000'], 'notes' => ['nullable', 'string', 'max:5000']]);
        try {
            $this->riskService->approve($risk, Auth::id(), $request->input('note', $request->input('notes')));
            return redirect()->back()->with('success', 'اعتُمد الخطر.');
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', 'تعذّر الاعتماد: '.$e->getMessage());
        }
    }

    public function reject(Request $request, Risk $risk)
    {
        $request->validate(['note' => ['nullable', 'string', 'max:5000'], 'notes' => ['nullable', 'string', 'max:5000']]);
        try {
            $this->riskService->reject($risk, Auth::id(), $request->input('note', $request->input('notes')));
            return redirect()->back()->with('success', 'رُفض الخطر.');
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', 'تعذّر الرفض: '.$e->getMessage());
        }
    }

    public function requestModification(Request $request, Risk $risk)
    {
        $validated = $request->validate(['notes' => ['required', 'string', 'max:5000']]);
        try {
            $this->riskService->reject($risk, Auth::id(), $validated['notes']);
            $this->riskService->changeStatus($risk->fresh(), Auth::id(), 'draft', $validated['notes']);
            return redirect()->back()->with('success', 'أُعيد الخطر إلى المسودة للتعديل.');
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', 'تعذّر: '.$e->getMessage());
        }
    }

    public function changeStatus(Request $request, Risk $risk)
    {
        $validated = $request->validate(['status' => ['required', 'string'], 'note' => ['nullable', 'string', 'max:5000']]);
        try {
            $this->riskService->changeStatus($risk, Auth::id(), $validated['status'], $validated['note'] ?? null);
            return redirect()->back()->with('success', 'حُدّثت حالة الخطر.');
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', 'تعذّر تغيير الحالة: '.$e->getMessage());
        }
    }

    // ── النسخ من الكتاب ──

    public function copyFromMaster(int $risk, Request $request)
    {
        try {
            $source = Risk::findOrFail($risk);
            $this->riskCopyService->masterToReference($source, Auth::id());
            if ($request->expectsJson()) {
                return response()->json(['success' => true, 'message' => 'نُسخ إلى السجل العام'], 200, [], JSON_UNESCAPED_UNICODE);
            }
            return redirect()->route('risk.reference.index')->with('success', 'نُسخ الخطر إلى السجل العام.');
        } catch (\Throwable $e) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422, [], JSON_UNESCAPED_UNICODE);
            }
            return redirect()->back()->with('error', 'تعذّر النسخ: '.$e->getMessage());
        }
    }

    public function bulkCopyFromMaster(Request $request)
    {
        $validated = $request->validate(['risk_ids' => ['required', 'array', 'min:1'], 'risk_ids.*' => ['integer']]);
        try {
            $copied = 0;
            foreach ($validated['risk_ids'] as $id) {
                $this->riskCopyService->masterToReference(Risk::findOrFail($id), Auth::id());
                $copied++;
            }
            if ($request->expectsJson()) {
                return response()->json(['success' => true, 'copied' => $copied], 200, [], JSON_UNESCAPED_UNICODE);
            }
            return redirect()->route('risk.reference.index')->with('success', "نُسخ {$copied} خطراً إلى السجل العام.");
        } catch (\Throwable $e) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422, [], JSON_UNESCAPED_UNICODE);
            }
            return redirect()->back()->with('error', 'تعذّر النسخ: '.$e->getMessage());
        }
    }

    // ── التفاصيل والملاحظات ──

    public function show(int $risk)
    {
        $risk = Risk::with(['category', 'subCategory', 'events.actor', 'notes.createdBy', 'createdBy', 'approvedBy', 'organizationUnit', 'place',
            'phases.causes', 'phases.affectedGroups', 'phases.affectedGroupDetails'])->findOrFail($risk);
        if ($risk->risk_type === 'active') $this->authorizeScope($risk);
        return view('modules.risks.detail', compact('risk'));
    }

    public function details(int $risk)
    {
        return $this->show($risk);
    }

    public function addNote(Request $request, Risk $risk)
    {
        $validated = $request->validate(['note' => ['required', 'string', 'max:5000']]);
        RiskNote::create(['risk_id' => $risk->id, 'note' => $validated['note'], 'created_by_id' => Auth::id(), 'created_at' => now()]);
        return redirect()->route('risk.show', $risk)->with('success', 'أُضيفت الملاحظة.');
    }

    // ── AJAX ──

    public function ajaxSubcategories(Request $request): JsonResponse
    {
        $request->validate(['category_id' => ['required', 'integer', 'exists:risk_categories,id']]);
        return response()->json(RiskSubCategory::where('category_id', $request->input('category_id'))->select('id', 'name', 'name_en')->orderBy('name')->get());
    }

    public function ajaxCauses(Request $request): JsonResponse
    {
        $request->validate(['sub_category_id' => ['required', 'integer', 'exists:risk_sub_categories,id']]);
        return response()->json(RiskCause::where('type_category_id', $request->input('sub_category_id'))->select('id', 'name', 'name_en')->orderBy('name')->get());
    }

    public function ajaxCategoryTree(): JsonResponse
    {
        return response()->json(RiskCategory::where('is_active', true)->with('subCategories.causes')->orderBy('name')->get());
    }

    public function ajaxRisksByRegistry(Request $request): JsonResponse
    {
        $query = Risk::select('id', 'title', 'risk_type', 'category_id', 'severity', 'likelihood', 'risk_score', 'status');
        if ($request->filled('risk_type')) $query->where('risk_type', $request->input('risk_type'));
        if ($request->filled('category_id')) $query->where('category_id', $request->input('category_id'));
        if ($request->filled('status')) $query->where('status', $request->input('status'));
        return response()->json($query->orderBy('title')->limit(100)->get());
    }

    // ── تصدير ──

    public function export(): StreamedResponse
    {
        $query = Risk::with(['category', 'subCategory', 'createdBy', 'phases', 'organizationUnit', 'place'])->orderByDesc('created_at');
        $this->scopeToUserOrgUnit($query);
        $risks = $query->get();
        $headers = ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="risks_'.now()->format('Y-m-d_His').'.csv"'];
        return response()->stream(function () use ($risks) {
            $h = fopen('php://output', 'w');
            fprintf($h, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($h, ['الرقم', 'الكود', 'العنوان', 'السجل', 'الحالة', 'الفئة', 'الفئة الفرعية', 'الخطورة', 'الاحتمالية', 'الدرجة', 'الوحدة', 'المكان', 'الإجراء التصحيحي', 'الإجراء الوقائي', 'أنشأه', 'التاريخ']);
            foreach ($risks as $r) {
                fputcsv($h, [$r->id, $r->code, $r->title, $r->risk_type, $r->status_label, $r->category?->name, $r->subCategory?->name,
                    $r->severity, $r->likelihood, $r->risk_score, $r->organizationUnit?->name, $r->place?->name,
                    $r->phases->pluck('corrective_action')->filter()->join(' | '), $r->phases->pluck('preventive_action')->filter()->join(' | '),
                    $r->createdBy?->name, $r->created_at?->toDateTimeString()]);
            }
            fclose($h);
        }, 200, $headers);
    }

    // ── مساعدات ──

    private function formData(): array
    {
        return [
            'categories' => RiskCategory::where('is_active', true)->with('subCategories')->orderBy('name')->get(),
            'orgUnits' => OrganizationUnit::where('is_active', true)->orderBy('order')->get(),
            'places' => Place::orderBy('sort')->get(),
            'tenantUsers' => User::whereHas('profile', fn ($q) => $q->where('is_active', true))->orderBy('name')->get(),
            'masterAndTenantGroups' => AffectedGroup::orderBy('id')->get(),
        ];
    }

    /** الخطر الفعلي خارج نطاق وحدة المستخدم لا يُفتح (المدير يرى إدارته وما تحتها). */
    private function authorizeScope(Risk $risk): void
    {
        if ($this->isGlobalScopeRole()) return;
        $profile = $this->userProfile();
        $allowed = $profile?->organization_unit_id ? OrganizationUnit::descendantIdsOf($profile->organization_unit_id) : [];
        abort_unless($risk->organization_unit_id === null || in_array($risk->organization_unit_id, $allowed, true), 403, 'هذا الخطر خارج نطاق إدارتك.');
    }

    private function referenceValidationRules(): array
    {
        return array_merge([
            'risk_type_category_id' => ['nullable', 'integer', 'exists:risk_causes,id'],
            'category_id' => ['required', 'integer', 'exists:risk_categories,id'],
            'sub_category_id' => ['nullable', 'integer', 'exists:risk_sub_categories,id'],
            'severity' => ['required', 'integer', 'min:1', 'max:5'],
            'likelihood' => ['required', 'integer', 'min:1', 'max:5'],
            'benefit' => ['nullable', 'string', 'max:5000'],
            'legal_reference' => ['nullable', 'string', 'max:500'],
            'scope_type' => ['nullable', 'string', 'in:general,org_unit'],
            'organization_unit_id' => ['nullable', 'integer', 'exists:organization_units,id'],
            'place_id' => ['nullable', 'integer', 'exists:places,id'],
        ], $this->phaseRules());
    }

    private function activeExtraRules(): array
    {
        return [
            'assigned_coordinator_id' => ['nullable', 'integer', 'exists:users,id'],
            'assigned_field_team_id' => ['nullable', 'integer', 'exists:users,id'],
            'target_closure_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    private function phaseRules(): array
    {
        return [
            'phases' => ['nullable', 'array'],
            'phases.*.preventive_action' => ['nullable', 'string', 'max:5000'],
            'phases.*.corrective_action' => ['nullable', 'string', 'max:5000'],
            'phases.*.residual_assessment' => ['nullable', 'string', 'max:5000'],
            'phases.*.responsible_org_unit_id' => ['nullable', 'integer', 'exists:organization_units,id'],
            'phases.*.responsible_org_unit_text' => ['nullable', 'string', 'max:200'],
            'phases.*.responsible_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'phases.*.responsible_user_text' => ['nullable', 'string', 'max:200'],
            'phases.*.cause_names' => ['nullable', 'array'],
            'phases.*.cause_names.*' => ['nullable', 'string', 'max:200'],
            'phases.*.affected_group_ids' => ['nullable', 'array'],
            'phases.*.affected_group_ids.*' => ['integer', 'exists:affected_groups,id'],
            'phases.*.affected_impact' => ['nullable', 'array'],
            'phases.*.affected_rep_scope' => ['nullable', 'array'],
        ];
    }

    private function deriveTitle(?int $riskTypeCategoryId, ?int $subCategoryId): string
    {
        if ($riskTypeCategoryId && ($name = RiskCause::find($riskTypeCategoryId)?->name)) return $name;
        if ($subCategoryId && ($name = RiskSubCategory::find($subCategoryId)?->name)) return $name;
        return 'خطر غير محدد';
    }
}
