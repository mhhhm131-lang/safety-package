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
use App\Modules\Risk\Services\RiskService;
use App\Modules\Risk\Support\RiskApproval;
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

    /** قرار ٦٩ */
    private const NOT_APPROVER = 'خطر الإدارة يعتمده مديرها، والسجل العام يعتمده مسؤول السلامة.';

    /** قرار ٧٤ */
    private const GENERAL_EDIT = 'السجل العام يعدّله مسؤول السلامة — ولغيره مقترحه إذا أُعيد له.';

    /** قرارا ٧٥ و٧٦: ما يقال للمقترِح عند «حفظ» */
    private const PROPOSAL_SENT = 'أُرسل مقترحك إلى مسؤول السلامة. يظهر في السجل العام بعد اعتماده.';

    public function __construct(protected RiskService $riskService)
    {
        $this->globalScopeRoles = RiskApproval::REGISTER_WIDE; // قرار ٧٠: منسق السلامة نطاقه نطاق مديره
    }

    // ── السجل الفعلي (active) ──

    public function index(Request $request)
    {
        return $this->activeIndex($request);
    }

    public function activeIndex(Request $request)
    {
        $query = Risk::where('risk_type', 'active')->with([
            'category', 'organizationUnit', 'place', 'createdBy', 'assignedCoordinator', 'assignedFieldTeam',
            'parentReference.handlingUnit', 'parentReference.handlerUser', 'parentReference.handlerSetBy', // الخطوة ٤: النسخة تقرأ المعالج من أصلها
            'phases', 'phases.causes', 'phases.affectedGroups', 'phases.responsibleOrgUnit', 'phases.responsibleUser',
        ]);
        $this->scopeToUserOrgUnit($query);
        if ($place = $request->input('place')) {
            $query->ofPlace(Place::idByCode((string) $place)); // مخاطر المكان: ما كُتب عليه، وخطر الوحدة التي تشغله
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
        $validated = $request->validate(array_merge($this->referenceValidationRules(), $this->activeExtraRules()), $this->assignMessages());
        $reference = $this->referenceFor($validated);
        if ($reference === false) return redirect()->back()->withInput()->withErrors(['parent_reference_id' => 'الخطر المرجعي المختار ليس تحت هذه الفئة الفرعية.']);
        // قرار ٧٠: الباب الثاني للفعل نفسه بالنطاق نفسه — المدير ومنسق السلامة يكتبان لوحدتهما وما تحتها فقط
        $validated = $this->withinUnitScope($validated);
        if (is_string($validated)) return redirect()->back()->withInput()->with('error', $validated);
        try {
            $validated['title'] = trim((string) ($validated['title'] ?? '')) ?: ($reference?->title ?: $this->deriveTitle($validated['risk_type_category_id'] ?? null, $validated['sub_category_id'] ?? null));
            $validated['description'] = trim((string) ($validated['description'] ?? '')) ?: ($reference?->description ?: $validated['title']);
            $validated['parent_reference_id'] = $reference?->id;
            if ($reference) {
                // ١٨-٢: خطر من السجل العام ← نسخة كاملة بمراحلها وأسبابها وإجراءاتها (كالتفعيل)، وما كتبه المستخدم في النموذج يغلب
                $risk = app(\App\Modules\Risk\Services\RiskCopyService::class)->referenceToActive($reference, Auth::id(),
                    collect($validated)->except(['phases', 'parent_reference_id'])->all() + ['status' => 'draft']);
                $this->riskService->persistAllPhases($risk, $this->typedPhasesOnly($validated['phases'] ?? []));
            } else {
                $risk = $this->riskService->createRisk(Auth::id(), collect($validated)->except(['phases'])->all(), 'active');
                $this->riskService->persistAllPhases($risk, $validated['phases'] ?? []);
            }
            return redirect()->route('risk.active.index')->with('success', 'أُنشئ الخطر في السجل الفعلي بمراحله الثلاث.');
        } catch (\Throwable $e) {
            return redirect()->back()->withInput()->with('error', 'حدث خطأ: '.$e->getMessage());
        }
    }

    public function activeEdit(int $risk)
    {
        $risk = Risk::findOrFail($risk);
        abort_unless($risk->risk_type === 'active', 404); // قرار ٧٤: باب الخاص لا يفتح خطراً من السجل العام
        $this->authorizeScope($risk);
        $this->riskService->ensurePhases($risk);
        $risk->load(['phases.causes', 'phases.affectedGroups', 'phases.affectedGroupDetails']);
        return view('modules.risks.active_edit', $this->formData() + compact('risk'));
    }

    public function activeUpdate(Request $request, int $risk)
    {
        $risk = Risk::findOrFail($risk);
        abort_unless($risk->risk_type === 'active', 404); // قرار ٧٤
        $this->authorizeScope($risk);
        $validated = $request->validate(array_merge($this->referenceValidationRules(), $this->activeExtraRules()), $this->assignMessages());
        // قرار ٧٠: يبقى الخطر حيث هو، أو يُنقل إلى وحدة في نطاق صاحب الحساب — لا إلى وحدة غيره
        if ((int) ($validated['organization_unit_id'] ?? 0) !== (int) $risk->organization_unit_id) {
            $validated = $this->withinUnitScope($validated);
            if (is_string($validated)) return redirect()->back()->withInput()->with('error', $validated);
        }
        $reference = $this->referenceFor($validated);
        if ($reference === false) return redirect()->back()->withInput()->withErrors(['parent_reference_id' => 'الخطر المرجعي المختار ليس تحت هذه الفئة الفرعية.']);
        try {
            $typed = trim((string) ($validated['title'] ?? ''));
            if ($typed !== '') {
                $validated['title'] = $typed;
            } elseif ($reference) {
                $validated['title'] = $reference->title;
            } else {
                $derived = $this->deriveTitle($validated['risk_type_category_id'] ?? null, $validated['sub_category_id'] ?? null);
                $validated['title'] = $derived !== 'خطر غير محدد' ? $derived : ($risk->title ?: 'خطر غير محدد');
            }
            $validated['parent_reference_id'] = $reference?->id;
            $validated['description'] = trim((string) ($validated['description'] ?? '')) ?: $validated['title'];
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
        // قرار ٧٤: مقترح في السجل العام يحذفه صاحبه أو مسؤول السلامة
        abort_if($risk->risk_type !== 'active' && !RiskApproval::canEditGeneral(Auth::user(), $risk), 403, self::GENERAL_EDIT);
        $this->authorizeScope($risk);
        if ($risk->status !== 'draft') {
            return redirect()->route('risk.active.index')->with('error', 'لا يُحذف إلا خطر في حالة مسودة.');
        }
        $risk->delete();
        return redirect()->route('risk.active.index')->with('success', 'حُذف الخطر.');
    }

    // ── السجل العام (reference) ──

    public function referenceIndex(Request $request)
    {
        // قرار ٧٥: المقترح لا يظهر في السجل العام حتى يعتمده مسؤول السلامة
        $query = Risk::where('risk_type', 'reference')->adopted()->with([
            'category', 'assignedCoordinator', 'assignedFieldTeam', 'organizationUnit', 'handlingUnit', 'handlerUser', 'handlerSetBy',
            'phases', 'phases.causes', 'phases.affectedGroups', 'phases.responsibleOrgUnit', 'phases.responsibleUser',
        ]);
        $this->applyFilters($query, $request);
        $risks = $query->latest()->paginate(25);
        $categories = RiskCategory::where('is_active', true)->orderBy('name')->get();
        // قرار ٧١: لمن يملك التفعيل — ما يُفعَّل دفعةً (المعتمد في السجل العام بفئته وفرعيته)، وخانتا النافذة
        $bulk = null;
        if (\App\Core\Permissions\PermissionRegistry::hasPermission(Auth::user()->role(), 'risk.activate')) {
            $scopeIds = $this->scopeUnitIds();
            $bulk = [
                'map' => Risk::where('risk_type', 'reference')->whereIn('status', ['approved', 'active'])->orderBy('id')
                    ->get(['id', 'category_id'])->map(fn (Risk $r) => [$r->id, $r->category_id])->all(),
                'scopeUnits' => $scopeIds === null ? null : OrganizationUnit::whereIn('id', $scopeIds)->where('is_active', true)->orderBy('order')->get(),
                'myUnitId' => $this->userProfile()?->organization_unit_id,
                'orgUnits' => OrganizationUnit::where('is_active', true)->orderBy('order')->get(),
                'users' => User::whereHas('profile', fn ($q) => $q->where('is_active', true))->orderBy('name')->get(['id', 'name']),
            ];
        }
        return view('modules.risks.reference', compact('risks', 'categories', 'bulk'));
    }

    public function referenceCreate()
    {
        return view('modules.risks.reference_create', $this->formData());
    }

    public function referenceStore(Request $request)
    {
        $validated = $this->withHandlingUnit($request->validate($this->referenceValidationRules()));
        try {
            // قرار ٢١: العنوان حر في السجل العام (اسم الخطر الدقيق)؛ يُشتق من التصنيف فقط إن تُرك فارغاً
            $validated['title'] = trim((string) ($validated['title'] ?? '')) ?: $this->deriveTitle($validated['risk_type_category_id'] ?? null, $validated['sub_category_id'] ?? null);
            $validated['description'] = trim((string) ($validated['description'] ?? '')) ?: $validated['title'];
            $risk = $this->riskService->createRisk(Auth::id(), collect($validated)->except(['phases'])->all(), 'reference');
            $this->riskService->persistAllPhases($risk, $validated['phases'] ?? []);
            // قرار ٧٦: «حفظ» ضغطة واحدة — مسؤول السلامة يضيف فيُعتمد (هو المعتمد)، وغيره يُرسَل مقترحه إليه ويُقال له ذلك (قرار ٧٥)
            if (Auth::user()->role() === RiskApproval::GENERAL_APPROVER) {
                $this->riskService->approveOwn($risk, Auth::id());
                return redirect()->route('risk.reference.index')->with('success', 'أُضيف الخطر إلى السجل العام.');
            }
            $this->riskService->submitForApproval($risk, Auth::id());
            return redirect()->route('risk.reference.index')->with('success', self::PROPOSAL_SENT);
        } catch (\Throwable $e) {
            return redirect()->back()->withInput()->with('error', 'حدث خطأ: '.$e->getMessage());
        }
    }

    public function referenceEdit(Risk $risk)
    {
        $this->authorizeGeneralEdit($risk);
        $this->riskService->ensurePhases($risk);
        $risk->load(['phases.causes', 'phases.affectedGroups', 'phases.affectedGroupDetails']);
        return view('modules.risks.reference_edit', $this->formData() + compact('risk'));
    }

    public function referenceUpdate(Request $request, Risk $risk)
    {
        $this->authorizeGeneralEdit($risk);
        $validated = $this->withHandlingUnit($request->validate($this->referenceValidationRules()));
        try {
            $typed = trim((string) ($validated['title'] ?? ''));
            if ($typed !== '') {
                $validated['title'] = $typed;
            } else {
                $derived = $this->deriveTitle($validated['risk_type_category_id'] ?? null, $validated['sub_category_id'] ?? null);
                $validated['title'] = $derived !== 'خطر غير محدد' ? $derived : ($risk->title ?: 'خطر غير محدد');
            }
            $validated['description'] = trim((string) ($validated['description'] ?? '')) ?: $validated['title'];
            // خطة المعالج — الخطوة ١: تغيّرت الإدارة المعالجة ← معالج الإدارة السابقة لا ينتقل؛ مدير الإدارة الجديدة يسمّي من عنده
            if (array_key_exists('handling_unit_id', $validated) && (int) $validated['handling_unit_id'] !== (int) $risk->handling_unit_id) {
                foreach (Risk::HANDLER_FIELDS as $f) $validated[$f] = null;
            }
            $this->riskService->updateRisk($risk, Auth::id(), collect($validated)->except(['phases'])->all());
            $this->riskService->persistAllPhases($risk, $validated['phases'] ?? []);
            $risk = $risk->fresh();
            if (!in_array($risk->status, Risk::PROPOSED, true)) {
                return redirect()->route('risk.reference.index')->with('success', 'حُدّث الخطر المرجعي بمراحله الثلاث.');
            }
            if (Auth::user()->role() === RiskApproval::GENERAL_APPROVER) {
                return redirect()->route('risk.reference.index')->with('success', 'حُفظ التعديل. المقترح يظهر في السجل العام بعد اعتماده.');
            }
            // قرار ٧٦: صاحب المقترح صحّحه (مسودة أُعيدت له، أو مرفوض) ← الحفظ يعيد إرساله إلى مسؤول السلامة
            if ($risk->status === 'rejected') $risk = $this->riskService->changeStatus($risk, Auth::id(), 'draft');
            $this->riskService->submitForApproval($risk, Auth::id());
            return redirect()->route('risk.reference.index')->with('success', self::PROPOSAL_SENT);
        } catch (\Throwable $e) {
            return redirect()->back()->withInput()->with('error', 'فشل التحديث: '.$e->getMessage());
        }
    }

    /**
     * خطة المعالج — الخطوة ١ (٢٠٢٦-١٠-٠٨): «الإدارة المعالجة» يكتبها مسؤول السلامة وحده، وتُحفظ باسمها
     * (لتُوجد بالاسم داخل فرع مكان البلاغ حين تدخل الفروع). غيره لا تُقرأ منه هذه الخانة: مقترحه بلا إدارة معالجة.
     * «المعالج» لا يُكتب من هذا النموذج أصلاً — يكتبه مدير الإدارة المعالجة من «إدارتي» (HandlersController).
     */
    private function withHandlingUnit(array $validated): array
    {
        if (Auth::user()->role() !== RiskApproval::GENERAL_APPROVER) {
            unset($validated['handling_unit_id']);
            return $validated;
        }
        if (!array_key_exists('handling_unit_id', $validated)) return $validated;
        $unit = $validated['handling_unit_id'] ? OrganizationUnit::find((int) $validated['handling_unit_id']) : null;
        $validated['handling_unit_id'] = $unit?->id;
        $validated['handling_unit_name'] = $unit?->name;
        return $validated;
    }

    /** قرار ٧٤: باب السجل العام لخطر عام وحده، ويعدّله مسؤول السلامة — ولغيره مقترحه ما دام مسودة */
    private function authorizeGeneralEdit(Risk $risk): void
    {
        abort_if($risk->risk_type === 'active', 404);
        abort_unless(RiskApproval::canEditGeneral(Auth::user(), $risk), 403, self::GENERAL_EDIT);
    }

    // ── التفعيل: من السجل العام إلى سجل إدارة/مكان ──

    /** قرار ٧٥: يُفعَّل من السجل العام ما اعتمده مسؤول السلامة وحده — الحكم نفسه في التفعيل دفعةً */
    private function activatable(int $id): Risk
    {
        return Risk::where('risk_type', 'reference')->whereIn('status', ['approved', 'active'])->findOrFail($id);
    }

    public function activateForm(int $risk)
    {
        $risk = $this->activatable($risk);
        $this->riskService->ensurePhases($risk);
        $risk->load(['category', 'subCategory', 'phases.causes', 'phases.affectedGroups', 'phases.affectedGroupDetails']);
        return view('modules.risks.activate', $this->formData() + compact('risk'));
    }

    public function activate(Request $request, int $risk)
    {
        $reference = $this->activatable($risk);
        $validated = $request->validate(array_merge([
            'title' => ['nullable', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:10000'],
            'contact_channel' => ['nullable', 'string', 'max:200'],
            'scope_type' => ['required', 'string', 'in:general,org_unit'],
            'organization_unit_id' => ['nullable', 'required_if:scope_type,org_unit', 'integer', 'exists:organization_units,id'],
            'place_id' => ['nullable', 'integer', 'exists:places,id'],
            'severity' => ['required', 'integer', 'min:1', 'max:5'],
            'likelihood' => ['required', 'integer', 'min:1', 'max:5'],
            'legal_reference' => ['nullable', 'string', 'max:500'],
        ], $this->activeExtraRules(), $this->phaseRules()), $this->assignMessages() + ['organization_unit_id.required_if' => 'اختر الوحدة التنظيمية التي يُفعَّل لها الخطر.']);
        // المدير ومنسق السلامة يفعّلان لوحدتهما وما تحتها فقط (قرار ٧٠)
        $validated = $this->withinUnitScope($validated);
        if (is_string($validated)) return redirect()->back()->withInput()->with('error', $validated);
        try {
            // قرار ٧٠: من يعتمد هذا الخطر إن فعّله بنفسه صار نشطاً؛ غيره ينتظر المعتمد
            $unitId = !empty($validated['organization_unit_id']) ? (int) $validated['organization_unit_id'] : null;
            $await = !RiskApproval::activatesDirectly(Auth::user(), $unitId);
            $active = $this->riskService->activateFromReference($reference, Auth::id(), $validated, $await);
            return redirect()->route('risk.active.index')->with('success', $await ? $this->awaitingMessage($active) : 'فُعّل الخطر في سجل الإدارة بمراحله الثلاث.');
        } catch (\Throwable $e) {
            return redirect()->back()->withInput()->with('error', 'حدث خطأ: '.$e->getMessage());
        }
    }

    /**
     * قرار ٧١: تفعيل المحدَّد من السجل العام دفعةً — وحدة واحدة، ومنسقها منسق سلامة الوحدة تلقائياً (الخطوة ٤: المعالج من السجل العام لا من الدفعة).
     * النطاق والاعتماد كالتفعيل المفرد (قرار ٧٠). يعيد JSON لنافذة الشاشة (ترسل على دفعات)، أو يعود برسالة.
     */
    public function activateBulk(Request $request)
    {
        $validated = $request->validate([
            'risk_ids' => ['required', 'array', 'min:1', 'max:500'],
            'risk_ids.*' => ['integer'],
            'scope_type' => ['required', 'string', 'in:general,org_unit'],
            'organization_unit_id' => ['nullable', 'required_if:scope_type,org_unit', 'integer', 'exists:organization_units,id'],
            'assigned_field_team_id' => ['nullable', 'integer', 'exists:users,id'], // الخطوة ٤: لا معالج في الدفعة — من السجل العام
            'assigned_coordinator_id' => ['nullable', 'integer', 'exists:users,id'],
            // الشاشة ترسل الدفعة الكبيرة أجزاءً: التنبيه يُمسك حتى الجزء الأخير ويحمل المجموع
            'hold_notify' => ['nullable', 'boolean'],
            'created_before' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ], $this->assignMessages() + ['organization_unit_id.required_if' => 'اختر الوحدة التنظيمية التي تُفعَّل لها الأخطار.', 'risk_ids.required' => 'حدّد خطراً واحداً على الأقل.']);
        $refuse = fn (string $msg) => $request->expectsJson() ? response()->json(['message' => $msg], 422) : redirect()->back()->with('error', $msg);

        $validated = $this->withinUnitScope($validated);
        if (is_string($validated)) return $refuse($validated);
        $unitId = !empty($validated['organization_unit_id']) ? (int) $validated['organization_unit_id'] : null;
        $coordinator = $validated['assigned_coordinator_id'] ?? $this->unitCoordinatorId($unitId);
        if (!$coordinator) {
            return $refuse($unitId ? 'لا منسق سلامة لهذه الوحدة — رشّح منسقاً لها أولاً، ثم فعّل.' : 'سمِّ منسق السلامة للأخطار العامة للمعهد كله.');
        }

        $await = !RiskApproval::activatesDirectly(Auth::user(), $unitId);
        try {
            $result = $this->riskService->activateManyFromReference($validated['risk_ids'], Auth::id(), array_filter([
                'scope_type' => $validated['scope_type'], 'organization_unit_id' => $unitId,
                'assigned_coordinator_id' => (int) $coordinator, 'assigned_field_team_id' => !empty($validated['assigned_field_team_id']) ? (int) $validated['assigned_field_team_id'] : null,
            ], fn ($v) => $v !== null), $await);
        } catch (\Throwable $e) {
            return $refuse('حدث خطأ: '.$e->getMessage());
        }

        $n = count($result['created']);
        // قرار ٧٠: المعتمد يُنبَّه مرة واحدة للدفعة كلها بعددها
        $total = $n + (int) ($validated['created_before'] ?? 0);
        if ($await && $total > 0 && !$request->boolean('hold_notify')) {
            $this->riskService->notifyActivationAwaiting($total === 1 && $n === 1 ? $result['created'][0]
                : new Risk(['risk_type' => 'active', 'organization_unit_id' => $unitId]), $total);
        }
        $skipped = $result['existing'] + $result['unapproved'];
        $msg = 'فُعّل '.$n.($skipped ? ' وتُخطّي '.$skipped : '').'.';
        if ($result['existing']) $msg .= ' موجود في سجل الوحدة: '.$result['existing'].'.';
        if ($result['unapproved']) $msg .= ' غير معتمد في السجل العام: '.$result['unapproved'].'.';
        if ($n && $await) $msg .= ' '.$this->awaitingMessage($result['created'][0], true);
        $payload = ['created' => $n, 'existing' => $result['existing'], 'unapproved' => $result['unapproved'], 'awaiting' => $n > 0 && $await, 'message' => $msg,
            'awaiting_message' => $await ? $this->awaitingMessage($result['created'][0] ?? new Risk(['risk_type' => 'active', 'organization_unit_id' => $unitId]), true) : null];

        return $request->expectsJson() ? response()->json($payload) : redirect()->route('risk.active.index')->with('success', $msg);
    }

    /**
     * قرار ٧١: منسق الخطر في الدفعة منسق سلامة الوحدة — صاحب الحساب إن كان هو منسقها، وإلا منسق الوحدة نفسها، وإلا أقرب منسق فوقها.
     * بلا وحدة (نطاق المعهد كله) لا منسق تلقائياً.
     */
    private function unitCoordinatorId(?int $unitId): ?int
    {
        if (!$unitId) return null;
        $me = $this->userProfile();
        if ($me && $me->role === 'safety_coordinator' && $me->is_active) return (int) $me->user_id;
        for ($u = OrganizationUnit::find($unitId), $n = 0; $u && $n < 10; $u = $u->parent_id ? OrganizationUnit::find($u->parent_id) : null, $n++) {
            $id = \App\Modules\Governance\Models\UserProfile::where('role', 'safety_coordinator')->where('is_active', true)
                ->where('organization_unit_id', $u->id)->orderBy('id')->value('user_id');
            if ($id) return (int) $id;
        }
        return null;
    }

    /** قرار ٧٠: ما يقال لمن فعّل وليس هو المعتمد — من ينتظر؛ وإن لم يكن للمعتمد حساب قيل ذلك، لا صمت */
    private function awaitingMessage(Risk $risk, bool $many = false): string
    {
        $who = RiskApproval::isGeneral($risk) ? 'مسؤول السلامة' : 'مدير «'.($risk->organizationUnit?->name ?? 'الوحدة').'»';
        $msg = $many ? 'أُرسلت إلى '.$who.' ليعتمدها — تصير نشطة باعتماده.' : 'فُعّل الخطر وأُرسل إلى '.$who.' ليعتمده — يصير نشطاً باعتماده.';
        return RiskApproval::approverIds($risk) ? $msg : $msg.' تنبيه: لا حساب مفعّلاً لمن يعتمده — أبلغ مسؤول السلامة.';
    }

    // ── الاعتماد ──

    public function approvalQueue()
    {
        // قرار ٦٩: الطابور ما يعتمده هذا الشخص — مدير الوحدة مخاطر وحدته وما تحتها، ومسؤول السلامة العام وما بلا وحدة
        $query = Risk::where('status', 'pending_approval')->with(['category', 'subCategory', 'createdBy']);
        $profile = $this->userProfile();
        if ($profile && $profile->role === RiskApproval::GENERAL_APPROVER) {
            $query->where(fn ($q) => $q->where('risk_type', '!=', 'active')->orWhereNull('organization_unit_id'));
        } else {
            $allowed = $profile?->organization_unit_id ? OrganizationUnit::descendantIdsOf($profile->organization_unit_id) : [];
            $query->where('risk_type', 'active')->whereIn('organization_unit_id', $allowed);
        }
        $risks = $query->latest()->paginate(25);
        return view('modules.risks.approval', compact('risks'));
    }

    public function submit(Risk $risk)
    {
        // قرار ٦٩: يرفع الخطر من يملك الإنشاء (المنسق والمركز)، أو مدير الإدارة مسودته هو في نطاقه — كان المسار يرفضه فتبقى مسودته بلا مخرج
        $can = fn (string $p) => \App\Core\Permissions\PermissionRegistry::hasPermission(Auth::user()->role(), $p);
        abort_unless($can('risk.create') || ($can('risk.activate') && (int) $risk->created_by_id === (int) Auth::id()), 403, 'رفع الخطر لمن كتبه أو لمنسق السلامة.');
        $this->authorizeScope($risk);
        try {
            $this->riskService->submitForApproval($risk, Auth::id());
            return redirect()->back()->with('success', 'قُدّم الخطر للاعتماد.');
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', 'تعذّر التقديم: '.$e->getMessage());
        }
    }

    public function approve(Request $request, Risk $risk)
    {
        abort_unless(RiskApproval::canApprove(Auth::user(), $risk), 403, self::NOT_APPROVER);
        $request->validate(['note' => ['nullable', 'string', 'max:5000'], 'notes' => ['nullable', 'string', 'max:5000']]);
        try {
            $this->riskService->approve($risk, Auth::id(), $request->input('note', $request->input('notes')));
            return redirect()->back()->with('success', 'اعتُمد الخطر.');
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', 'تعذّر الاعتماد: '.$e->getMessage());
        }
    }

    /** قرار ٧١: «اعتمدها كلها» من بطاقة الدفعة — يعتمد مما ينتظر ما يملك هذا الحساب اعتماده، ويترك غيره كما هو */
    public function approveBulk(Request $request)
    {
        $validated = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer']]);
        $n = 0;
        foreach (Risk::whereIn('id', $validated['ids'])->where('status', 'pending_approval')->orderBy('id')->get() as $risk) {
            if (!RiskApproval::canApprove(Auth::user(), $risk)) continue;
            try {
                $this->riskService->approve($risk, Auth::id(), null);
                $n++;
            } catch (\Throwable $e) {
                // خطر تغيّرت حالته بين العرض والضغطة يُترك؛ الباقي يُعتمد
            }
        }
        return redirect()->back()->with($n ? 'success' : 'error', $n ? 'اعتُمدت الأخطار: '.$n.'.' : 'لا خطر مما ينتظر تملك اعتماده.');
    }

    public function reject(Request $request, Risk $risk)
    {
        abort_unless(RiskApproval::canApprove(Auth::user(), $risk), 403, self::NOT_APPROVER);
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
        abort_unless(RiskApproval::canApprove(Auth::user(), $risk), 403, self::NOT_APPROVER);
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
        // قرار ٧٠: الاعتماد والرفض لمن يعتمد هذا الخطر وحده — لا يُعتمد خطر بتغيير حالته من هذا الباب
        abort_if(in_array($validated['status'], ['approved', 'rejected'], true) && !RiskApproval::canApprove(Auth::user(), $risk), 403, self::NOT_APPROVER);
        // قرار ٧٤: حالة الخطر في السجل العام يغيّرها مسؤول السلامة — ولصاحب المقترح المرفوض إعادته إلى المسودة
        if ($risk->risk_type !== 'active' && Auth::user()->role() !== RiskApproval::GENERAL_APPROVER) {
            abort_unless((int) $risk->created_by_id === (int) Auth::id() && $risk->status === 'rejected' && $validated['status'] === 'draft', 403, self::GENERAL_EDIT);
        }
        // قرار ٦٩: من لا يملك الإنشاء (مدير الإدارة) يعيد خطره المرفوض إلى المسودة فقط — لا حالة أخرى من هذا الباب
        if (!\App\Core\Permissions\PermissionRegistry::hasPermission(Auth::user()->role(), 'risk.create')) {
            abort_unless(\App\Core\Permissions\PermissionRegistry::hasPermission(Auth::user()->role(), 'risk.activate')
                && (int) $risk->created_by_id === (int) Auth::id() && $risk->status === 'rejected' && $validated['status'] === 'draft', 403, 'تغيير الحالة لمن يملك إنشاء المخاطر.');
        }
        $this->authorizeScope($risk);
        try {
            $this->riskService->changeStatus($risk, Auth::id(), $validated['status'], $validated['note'] ?? null);
            return redirect()->back()->with('success', 'حُدّثت حالة الخطر.');
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', 'تعذّر تغيير الحالة: '.$e->getMessage());
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
        $scopeIds = $this->scopeUnitIds();
        return [
            // قرار ٧٠: خانة النطاق — null لأدوار السجل كله (عام أو أي وحدة)؛ ولغيرهم وحدته وما تحتها، ووحدته مختارة سلفاً
            'scopeUnits' => $scopeIds === null ? null : OrganizationUnit::whereIn('id', $scopeIds)->where('is_active', true)->orderBy('order')->get(),
            'myUnitId' => $this->userProfile()?->organization_unit_id,
            'categories' => RiskCategory::where('is_active', true)->with('subCategories')->orderBy('name')->get(),
            'orgUnits' => OrganizationUnit::where('is_active', true)->orderBy('order')->get(),
            // ٢٨-٥ (قرار ٧٨): أماكن مباني الحساب (مدير إدارة في الشرقية: أماكن الشرقية؛ مسؤول السلامة: الكل)
            'places' => Place::active()->whereIn('building_id', \App\Modules\Governance\Services\BuildingContext::choices(auth()->user())->pluck('id'))->orderBy('building_id')->orderBy('sort')->get(),
            'units' => \App\Modules\Governance\Models\PlaceUnit::where('is_active', true)->orderBy('type')->orderBy('sort')->orderBy('name')->get(), // ١٨-٣ (ج)
            'tenantUsers' => User::whereHas('profile', fn ($q) => $q->where('is_active', true))->orderBy('name')->get(),
            'masterAndTenantGroups' => AffectedGroup::orderBy('id')->get(),
            // خطة المعالج — الخطوة ١: قائمة الهيكل بترتيب الشجرة لخانة «الإدارة المعالجة» (مسؤول السلامة وحده)
            'unitTree' => OrganizationUnit::treeOptions(),
            'canSetHandlingUnit' => Auth::user()->role() === RiskApproval::GENERAL_APPROVER,
        ];
    }

    /** قرار ٧٠: وحدات نطاق صاحب الحساب في سجل الإدارات — null لأدوار السجل كله، وإلا وحدته وما تحتها (فارغ إن لم يُربط حسابه بوحدة) */
    private function scopeUnitIds(): ?array
    {
        if ($this->isGlobalScopeRole()) return null;
        return OrganizationUnit::descendantIdsOf($this->userProfile()?->organization_unit_id);
    }

    /**
     * قرار ٧٠: المدير ومنسق السلامة يكتبان ويفعّلان لوحدتهما وما تحتها فقط — لا «عام» ولا وحدة غيرهما.
     * يعيد المدخلات بنطاقها الصحيح، أو نص الرفض.
     */
    private function withinUnitScope(array $validated): array|string
    {
        $allowed = $this->scopeUnitIds();
        if ($allowed === null) {
            if (($validated['scope_type'] ?? null) === 'general') $validated['organization_unit_id'] = null; // «عام» بلا وحدة: معتمده مسؤول السلامة
            return $validated;
        }
        if (!$allowed) return 'حسابك غير مربوط بوحدة تنظيمية — اطلب من مسؤول السلامة ربطه بوحدتك لتفعّل لها.';
        if (!in_array((int) ($validated['organization_unit_id'] ?? 0), $allowed, true)) return 'يمكنك تفعيل الخطر لإدارتك أو أقسامها فقط.';
        $validated['scope_type'] = 'org_unit';
        return $validated;
    }

    /** الخطر الفعلي خارج نطاق وحدة المستخدم لا يُفتح (المدير ومنسق السلامة يريان وحدتهما وما تحتها). */
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
            'title' => ['nullable', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:10000'],
            'contact_channel' => ['nullable', 'string', 'max:200'],
            'risk_type_category_id' => ['nullable', 'integer', 'exists:risk_causes,id'],
            // المرحلة ١٨-٢: المستوى الثالث = خطر مرجعي من السجل العام (أو فارغ = خطر جديد غير موجود)
            'parent_reference_id' => ['nullable', 'integer', 'exists:risks,id'],
            'category_id' => ['required', 'integer', 'exists:risk_categories,id'],
            'sub_category_id' => ['nullable', 'integer', 'exists:risk_sub_categories,id'],
            'severity' => ['required', 'integer', 'min:1', 'max:5'],
            'likelihood' => ['required', 'integer', 'min:1', 'max:5'],
            'benefit' => ['nullable', 'string', 'max:5000'],
            'legal_reference' => ['nullable', 'string', 'max:500'],
            'scope_type' => ['nullable', 'string', 'in:general,org_unit'],
            'organization_unit_id' => ['nullable', 'integer', 'exists:organization_units,id'],
            'place_id' => ['nullable', 'integer', 'exists:places,id'],
            // ١٨-٣ (ج): الوحدة اختيارية ومن المكان نفسه
            'place_unit_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('place_units', 'id')->where('place_id', (int) request()->input('place_id'))->where('is_active', true)],
            // خطة المعالج — الخطوة ١: الإدارة المعالجة من الهيكل (تُقرأ من مسؤول السلامة وحده: withHandlingUnit)
            'handling_unit_id' => ['nullable', 'integer', 'exists:organization_units,id'],
        ], $this->phaseRules());
    }

    private function assignMessages(): array
    {
        return ['assigned_coordinator_id.required' => 'سمِّ منسق السلامة لهذا الخطر — إليه يصل بلاغ الشاغل أولاً.'];
    }

    private function activeExtraRules(): array
    {
        return [
            // ٢١-٤ (قرار ٥٤): المنسق شرط في الإعداد — إليه يصل بلاغ الشاغل أولاً
            'assigned_coordinator_id' => ['required', 'integer', 'exists:users,id'],
            // خطة المعالج — الخطوة ٤ (٢٠٢٦-١٠-٠٨): خانة «المعالج» خرجت من نماذج الخاص؛ المعالج يُقرأ من السجل العام (HandlerResolver).
            // تبقى مقبولة اختيارياً للكود القديم (تعبئة التجربة) حتى تُنقل — لا تُعرض ولا تُطلب
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
            'phases.*.affected_detail' => ['nullable', 'array'],
            'phases.*.affected_detail.*' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * المرحلة ١٨-٢: الخطر المرجعي المختار في المستوى الثالث. null = خطر جديد غير موجود؛ false = مختار لكنه ليس تحت الفئة الفرعية.
     * @return Risk|null|false
     */
    private function referenceFor(array $validated): Risk|null|false
    {
        $id = $validated['parent_reference_id'] ?? null;
        if (!$id) return null;
        $ref = Risk::where('risk_type', 'reference')->find((int) $id);
        if (!$ref) return false;
        $sub = $validated['sub_category_id'] ?? null;
        if ($sub && (int) $ref->sub_category_id !== (int) $sub) return false;
        if (!$sub && (int) $ref->category_id !== (int) ($validated['category_id'] ?? 0)) return false;
        return $ref;
    }

    /** ١٨-٢: من مدخلات المراحل ما كتبه المستخدم فقط — الحقول الفارغة لا تمحو ما نُسخ من المرجعي. */
    private function typedPhasesOnly(array $phases): array
    {
        $out = [];
        foreach ($phases as $key => $p) {
            if (!is_array($p)) continue;
            $t = [];
            foreach ($p as $k => $v) {
                if (is_array($v)) { $v = array_values(array_filter($v, fn ($x) => trim((string) $x) !== '')); if ($v) $t[$k] = $v; }
                elseif (trim((string) $v) !== '') $t[$k] = $v;
            }
            if ($t) $out[$key] = $t;
        }
        return $out;
    }

    /** المرحلة ١٨-٢: مسؤول السلامة يضيف خطراً فعلياً جديداً (بلا مرجع) إلى السجل العام — قراره هو. */
    public function toReference(int $risk, \App\Modules\Risk\Services\RiskCopyService $copy)
    {
        $risk = Risk::findOrFail($risk);
        abort_unless(Auth::user()->role() === RiskApproval::GENERAL_APPROVER, 403, 'إضافة خطر إلى السجل العام قرار مسؤول السلامة.'); // قرار ٦٩
        if ($risk->risk_type !== 'active') return redirect()->route('risk.show', $risk->id)->with('error', 'هذا ليس خطراً فعلياً.');
        if ($risk->parent_reference_id) return redirect()->route('risk.show', $risk->id)->with('success', 'هذا الخطر موجود في السجل العام أصلاً.');
        $ref = $copy->activeToReference($risk, Auth::id());
        return redirect()->route('risk.show', $risk->id)->with('success', 'أُضيف إلى السجل العام برمز '.$ref->code.' — يستفيد منه باقي الأماكن.');
    }

    private function deriveTitle(?int $riskTypeCategoryId, ?int $subCategoryId): string
    {
        if ($riskTypeCategoryId && ($name = RiskCause::find($riskTypeCategoryId)?->name)) return $name;
        if ($subCategoryId && ($name = RiskSubCategory::find($subCategoryId)?->name)) return $name;
        return 'خطر غير محدد';
    }
}
