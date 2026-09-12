<?php

namespace App\Modules\Permit\Controllers;

use App\Core\StateMachine\Exceptions\TransitionException;
use App\Core\Traits\AppliesOrgUnitScope;
use App\Http\Controllers\Controller;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Permit\Models\Equipment;
use App\Modules\Permit\Models\Permit;
use App\Modules\Permit\Models\PermitAttachment;
use App\Modules\Permit\Models\PermitDeviation;
use App\Modules\Permit\Models\PermitRequirement;
use App\Modules\Permit\Models\PermitType;
use App\Modules\Permit\Models\PermitWorker;
use App\Modules\Permit\Services\PermitConflictService;
use App\Modules\Permit\Services\PermitEligibilityService;
use App\Modules\Permit\Services\PermitNotifier;
use App\Modules\Permit\Services\PermitService;
use App\Modules\Permit\Services\PostClosureService;
use App\Modules\Permit\Services\RequiredPermitTypesService;
use App\Modules\Permit\Services\SmartJsaService;
use App\Modules\Permit\Services\WorkerGapRiskService;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Project\Models\Project;
use App\Modules\Risk\Models\Risk;
use App\Modules\Worker\Models\Trade;
use App\Modules\Worker\Models\Worker;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * مركز التصاريح: القائمة، المعالج (ثلاث خطوات)، صفحة التصريح، الانتقالات،
 * البنود وأدلتها، العمال، المرفقات، الانحرافات، التفعيل الميداني، التقييم البعدي.
 *
 * الصلاحيات على المسارات (`permit.*`) والسياسة `PermitPolicy` معاً:
 * المسار يمنع من لا يملك الصلاحية أصلاً، والسياسة تفحص الحالة وملكية الطرف.
 */
class PermitController extends Controller
{
    use AuthorizesRequests;
    use AppliesOrgUnitScope;

    public function __construct(
        private readonly PermitService $permits,
        private readonly PermitEligibilityService $eligibility,
        private readonly PermitConflictService $conflicts,
        private readonly SmartJsaService $smartJsa,
        private readonly WorkerGapRiskService $workerGaps,
        private readonly PostClosureService $postClosure,
        private readonly PermitNotifier $notifier,
        private readonly RequiredPermitTypesService $requiredTypes,
    ) {}

    /** قائمة التصاريح مع عدّادات الفئات ومرشّحات الحالة والمكان. */
    public function index(Request $request)
    {
        $query = Permit::query()->with(['type', 'project', 'externalParty', 'place'])->latest('id');

        if (($category = $request->query('category')) && isset(PermitType::CATEGORIES[$category])) {
            $query->where('permit_category', $category);
        }
        if (($status = $request->query('status')) && isset(Permit::STATUS_LABELS[$status])) {
            $query->where('status', $status);
        }
        if (($scope = $request->query('scope')) && isset(Permit::SCOPE_LABELS[$scope])) {
            $query->where('scope', $scope);
        }
        if ($placeId = $this->placeIdFromRequest($request)) {
            $query->where('place_id', $placeId);
        }
        if ($request->filled('project_id')) {
            $query->where('project_id', (int) $request->query('project_id'));
        }
        if ($request->filled('external_party_id')) {
            $query->where('external_party_id', (int) $request->query('external_party_id'));
        }
        if ($request->boolean('mine')) {
            $query->where('requested_by_id', Auth::id());
        }

        // حساب الطرف الخارجي يرى تصاريح طرفه فقط.
        $this->scopeToExternalParty($query);

        $facets = Permit::query()
            ->when($this->contractorPartyId(), fn ($q, $pid) => $q->where('external_party_id', $pid))
            ->selectRaw('permit_category, COUNT(*) as n')
            ->groupBy('permit_category')
            ->pluck('n', 'permit_category')
            ->all();

        return view('modules.permits.index', [
            'permits'        => $query->paginate(25)->withQueryString(),
            'facets'         => $facets,
            'totalFacet'     => array_sum($facets),
            'activeCategory' => $category,
            'activeStatus'   => $status,
            'activeScope'    => $scope,
            'activePlace'    => $placeId,
            'places'         => Place::orderBy('sort')->get(['id', 'code', 'name']),
        ]);
    }

    /** طابور من ينتظر إجراءً: للمراجعة، وما يوشك على الانتهاء، وما عولج حديثاً. */
    public function queue()
    {
        // المرحلة ١١-٢: الاستعلام نفسه يغذّي «ما ينتظرك» (Permit::pendingDecision)
        $pending = Permit::pendingDecision()
            ->with(['type', 'project', 'externalParty', 'place', 'requestedBy'])
            ->get();

        $expiring = Permit::query()
            ->whereIn('status', [Permit::STATUS_APPROVED, Permit::STATUS_ACTIVE])
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays(7)])
            ->with(['type', 'place'])
            ->orderBy('expires_at')
            ->get();

        $recent = Permit::query()
            ->whereIn('status', [Permit::STATUS_APPROVED, Permit::STATUS_ACTIVE, Permit::STATUS_REJECTED, Permit::STATUS_COMPLETED])
            ->where('updated_at', '>=', now()->subHours(48))
            ->with(['type', 'place'])
            ->latest('updated_at')
            ->limit(10)
            ->get();

        return view('modules.permits.queue', compact('pending', 'expiring', 'recent'));
    }

    /** المعالج: خطوة ١ اختيار النوع، خطوة ٢ السياق. */
    public function create(Request $request)
    {
        $step   = max(1, min(2, (int) $request->query('step', 1)));
        $typeId = (int) $request->query('permit_type_id', 0);
        $type   = $typeId ? PermitType::find($typeId) : null;

        if ($type === null) {
            $step = 1;
        }

        return view('modules.permits.create', [
            'step'          => $step,
            'type'          => $type,
            'types'         => PermitType::where('is_active', true)->orderBy('category')->orderBy('name')->get(),
            'scopeVal'      => $request->query('scope', ''),
            'places'        => Place::orderBy('sort')->get(['id', 'code', 'name']),
            'projects'      => Project::orderBy('name')->get(['id', 'name', 'place_id']),
            'parties'       => $this->partiesForUser(),
            'orgUnits'      => OrganizationUnit::orderBy('name')->get(['id', 'name']),
            'workers'       => Worker::orderBy('full_name')->limit(500)->get(['id', 'full_name', 'national_id', 'external_party_id']),
            'equipmentList' => Equipment::where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'trades'        => Trade::where('is_active', true)->orderBy('name')->get(['id', 'name', 'level']),
            'parentPermits' => Permit::whereIn('scope', [Permit::SCOPE_PROJECT, Permit::SCOPE_CONTRACTOR_PRE, Permit::SCOPE_CONTRACTOR_POST])
                ->whereIn('status', [Permit::STATUS_APPROVED, Permit::STATUS_ACTIVE])
                ->latest('id')->limit(50)->get(['id', 'code', 'title', 'scope']),
        ]);
    }

    /** حفظ المسودة ثم الانتقال لمراجعة البنود المولَّدة (خطوة ٣). */
    public function store(Request $request)
    {
        $data = $this->validatePayload($request, creating: true);
        $type = PermitType::findOrFail($data['permit_type_id']);

        // فحص الأهلية قبل الإنشاء: يمنع مسودة لا يمكن أن تمضي.
        $result = $this->eligibility->canIssue($type->code, $data);
        if (!$result->eligible) {
            return back()->withInput()->with('err', 'لا يمكن إصدار هذا التصريح: '.implode(' · ', $result->blockers));
        }

        $data['requested_by_id'] = Auth::id();

        try {
            $permit = $this->permits->create($type, $data);
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('err', 'بيانات ناقصة: '.$e->getMessage());
        }

        $message = 'أُنشئت مسودة التصريح '.$permit->code.'.';
        if ($result->hasWarnings()) {
            $message .= ' تنبيه: '.implode(' · ', $result->warnings);
        }

        return redirect()->route('permits.review', $permit)->with('ok', $message);
    }

    /** خطوة ٣: مراجعة البنود المولَّدة قبل التقديم. */
    public function review(Permit $permit)
    {
        $this->authorize('view', $permit);
        $permit->load(['type', 'project', 'externalParty', 'place', 'risks', 'workers.worker']);

        return view('modules.permits.review', [
            'permit'              => $permit,
            'groupedRequirements' => $this->smartJsa->groupedRequirements($permit),
            'completionStats'     => $this->smartJsa->completionStats($permit),
            'availableWorkers'    => $this->workersForPermit($permit),
        ]);
    }

    public function show(Permit $permit)
    {
        $this->authorize('view', $permit);

        $permit->load([
            'type', 'project', 'externalParty', 'organizationUnit', 'place', 'safetyApprovedBy',
            'risks', 'requirements.riskControl', 'events.performedBy', 'workers.worker.trade',
            'parent', 'children', 'attachments.uploadedBy', 'deviations.recordedBy', 'deviations.resolvedBy', 'trades',
        ]);

        $conflict = $this->conflicts->check($permit);

        return view('modules.permits.show', [
            'permit'               => $permit,
            'blockers'             => $this->eligibility->blockers($permit),
            'conflicts'            => $conflict['conflicts'],
            'hasBlockingConflicts' => $conflict['has_blocks'],
            'groupedRequirements'  => $this->smartJsa->groupedRequirements($permit),
            'completionStats'      => $this->smartJsa->completionStats($permit),
            'workerGapReport'      => $permit->workers->isNotEmpty() ? $this->workerGaps->reportForPermit($permit) : null,
            'availableWorkers'     => $this->workersForPermit($permit),
            'requiresTwoStage'     => $this->permits->requiresTwoStage($permit),
            'availableRisks'       => $this->risksForPermit($permit),
            // أنواع تصاريح تستلزمها مخاطر هذا التصريح ولم تُصدَر بعد
            'requiredTypes'        => $this->requiredTypes->forPermit($permit),
        ]);
    }

    public function edit(Permit $permit)
    {
        $this->authorize('update', $permit);
        $permit->load('trades');

        return view('modules.permits.edit', [
            'permit'        => $permit,
            'places'        => Place::orderBy('sort')->get(['id', 'code', 'name']),
            'projects'      => Project::orderBy('name')->get(['id', 'name']),
            'parties'       => $this->partiesForUser(),
            'orgUnits'      => OrganizationUnit::orderBy('name')->get(['id', 'name']),
            'trades'        => Trade::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'equipmentList' => Equipment::where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function update(Request $request, Permit $permit)
    {
        $this->authorize('update', $permit);
        $data = $this->validatePayload($request, creating: false);

        $tradeIds = $data['trade_ids'] ?? null;
        unset($data['trade_ids'], $data['permit_type_id']);

        $permit->update($data);
        if ($tradeIds !== null) {
            $permit->trades()->sync($tradeIds);
        }

        return redirect()->route('permits.show', $permit)->with('ok', 'حُفظت التعديلات.');
    }

    /** نقل الحالة — الفعل الوحيد الذي يحرّك التصريح. */
    public function transition(Request $request, Permit $permit)
    {
        $to = (string) $request->input('to_status');

        $policyMethod = match ($to) {
            Permit::STATUS_SUBMITTED       => 'submit',
            Permit::STATUS_UNDER_REVIEW    => 'review',
            Permit::STATUS_SAFETY_APPROVED => 'safetyApprove',
            Permit::STATUS_APPROVED        => 'approve',
            Permit::STATUS_CONDITIONAL     => 'conditional',
            Permit::STATUS_REJECTED        => 'reject',
            Permit::STATUS_ACTIVE          => $permit->status === Permit::STATUS_SUSPENDED ? 'resume' : 'activate',
            Permit::STATUS_COMPLETED       => 'complete',
            Permit::STATUS_SUSPENDED       => 'suspend',
            Permit::STATUS_CANCELLED       => 'cancel',
            default                        => null,
        };
        abort_if($policyMethod === null, 422, 'حالة غير معروفة.');
        $this->authorize($policyMethod, $permit);

        $critical = in_array($to, [Permit::STATUS_REJECTED, Permit::STATUS_SUSPENDED, Permit::STATUS_CANCELLED], true);
        $data = $request->validate([
            'to_status'                    => ['required', 'string'],
            'notes'                        => [$critical ? 'required' : 'nullable', 'string', 'max:1000'],
            'activation_weather'           => ['nullable', 'string', 'max:100'],
            'activation_workers_confirmed' => ['nullable'],
            'activation_equipment_checked' => ['nullable'],
            'activation_notes'             => ['nullable', 'string', 'max:500'],
        ], ['notes.required' => 'يجب كتابة سبب القرار.']);

        // لقطة ظروف الموقع لحظة التفعيل (تُحفظ قبل الانتقال ليضمّها السجل).
        if ($to === Permit::STATUS_ACTIVE && $permit->status === Permit::STATUS_APPROVED) {
            $permit->update(['activation_conditions' => [
                'weather'           => $request->input('activation_weather'),
                'workers_confirmed' => $request->boolean('activation_workers_confirmed'),
                'equipment_checked' => $request->boolean('activation_equipment_checked'),
                'notes'             => $request->input('activation_notes'),
                'confirmed_by'      => Auth::id(),
                'confirmed_at'      => now()->toIso8601String(),
            ]]);
        }

        try {
            $this->permits->transition($permit, $to, Auth::id(), $data['notes'] ?? null, $request->ip());
        } catch (TransitionException $e) {
            return redirect()->route('permits.show', $permit)->with('err', $e->getMessage());
        }

        return redirect()->route('permits.show', $permit)->with('ok', 'حُدّثت حالة التصريح.');
    }

    /** صفحة التفعيل الميداني: البنود الاستباقية الإلزامية + لقطة الظروف. */
    public function activate(Permit $permit)
    {
        $this->authorize('activate', $permit);

        $preventive = $this->smartJsa->groupedRequirements($permit)['preventive'];
        $mandatory  = $preventive->where('severity', PermitRequirement::SEVERITY_MANDATORY);

        return view('modules.permits.activate', [
            'permit'                     => $permit->load(['type', 'project', 'externalParty', 'place']),
            'preventiveRequirements'     => $preventive,
            'allMandatoryPreventiveDone' => $mandatory->every(fn ($r) => $r->isComplete()),
            'completionStats'            => $this->smartJsa->completionStats($permit),
            'blockers'                   => $this->eligibility->blockers($permit),
        ]);
    }

    // ── البنود ──

    /** توثيق إتمام بند: قيمة أو قياس أو ملف دليل. القياس يُقارن بحد بند التحكم. */
    public function completeRequirement(Request $request, Permit $permit, PermitRequirement $requirement)
    {
        $this->authorize('completeRequirement', $permit);
        abort_if($requirement->permit_id !== $permit->id, 404);

        $data = $request->validate([
            'evidence_value' => ['nullable', 'string', 'max:500'],
            'evidence_file'  => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,webp'],
            'notes'          => ['nullable', 'string', 'max:1000'],
        ], [], ['evidence_file' => 'ملف الدليل']);

        $requirement->fill([
            'evidence_value'  => $data['evidence_value'] ?? $requirement->evidence_value,
            'notes'           => $data['notes'] ?? $requirement->notes,
            'completed_by_id' => Auth::id(),
            'completed_at'    => now(),
        ]);

        if ($request->hasFile('evidence_file')) {
            $requirement->attachEvidence($request->file('evidence_file'));
        }

        // قياس دون الحد: يُسجَّل «لم يجتز» ويمنع التفعيل حتى يُعالج.
        if ($requirement->evidence_type === 'measurement' && !empty($data['evidence_value'])) {
            $passed = $this->evaluateMeasurement($data['evidence_value'], $requirement->riskControl?->measurement_threshold);
            $requirement->measurement_passed = $passed;

            if ($passed === false && $requirement->severity === PermitRequirement::SEVERITY_MANDATORY) {
                $requirement->status = PermitRequirement::STATUS_FAILED;
                $requirement->save();
                $this->permits->recordEvent($permit, 'requirement_failed', [
                    'requirement_code' => $requirement->requirement_code,
                    'value'            => $data['evidence_value'],
                ], Auth::id());

                return back()->with('err',
                    "القراءة ({$data['evidence_value']}) لا تجتاز الحد المطلوب ({$requirement->riskControl?->measurement_threshold}). "
                    .'سُجّل عدم الاجتياز — لا يُفعَّل التصريح حتى يُعالج هذا البند.');
            }
        }

        $requirement->status = PermitRequirement::STATUS_COMPLETED;
        $requirement->save();
        $this->permits->completeRequirement($requirement, Auth::id(), $data['notes'] ?? null);

        return back()->with('ok', 'وُثّق إتمام البند.');
    }

    /** تبديل حالة بند بين مكتمل ومطلوب (لمن يراجع). */
    public function toggleRequirement(Permit $permit, PermitRequirement $requirement)
    {
        $this->authorize('completeRequirement', $permit);
        abort_if($requirement->permit_id !== $permit->id, 404);

        if ($requirement->isComplete()) {
            $requirement->update([
                'status' => PermitRequirement::STATUS_REQUIRED,
                'verified_by_id' => null, 'verified_at' => null,
                'completed_by_id' => null, 'completed_at' => null,
            ]);
            $this->permits->recordEvent($permit, 'requirement_reopened', [
                'requirement_code' => $requirement->requirement_code,
            ], Auth::id());

            return back()->with('ok', 'أُعيد فتح البند.');
        }

        $this->permits->completeRequirement($requirement, Auth::id());

        return back()->with('ok', 'عُلّم البند مكتملاً.');
    }

    /** إعفاء بند بمبرر (لا يمنع التفعيل بعدها). */
    public function waiveRequirement(Request $request, Permit $permit, PermitRequirement $requirement)
    {
        $this->authorize('completeRequirement', $permit);
        abort_if($requirement->permit_id !== $permit->id, 404);

        $data = $request->validate(['notes' => ['required', 'string', 'min:5', 'max:1000']],
            ['notes.required' => 'الإعفاء يتطلب مبرراً مكتوباً.']);

        $requirement->update([
            'status'         => PermitRequirement::STATUS_WAIVED,
            'verified_by_id' => Auth::id(),
            'verified_at'    => now(),
            'notes'          => $data['notes'],
        ]);
        $this->permits->recordEvent($permit, 'requirement_completed', [
            'requirement_code' => $requirement->requirement_code, 'waived' => true,
        ], Auth::id(), $data['notes']);

        return back()->with('ok', 'أُعفي البند بمبرر مسجَّل.');
    }

    public function downloadEvidence(Permit $permit, PermitRequirement $requirement)
    {
        $this->authorize('view', $permit);
        abort_if($requirement->permit_id !== $permit->id, 404);
        abort_unless($requirement->hasEvidenceFile(), 404, 'لا ملف دليل.');

        return response(base64_decode($requirement->evidence_file_data), 200, [
            'Content-Type'        => $requirement->evidence_file_mime ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.rawurlencode($requirement->evidence_file ?: 'evidence').'"',
        ]);
    }

    // ── المخاطر والعمال والمرفقات ──

    public function attachRisk(Request $request, Permit $permit)
    {
        $this->authorize('completeRequirement', $permit);
        $data = $request->validate(['risk_id' => ['required', 'integer', 'exists:risks,id']]);

        $this->permits->attachRisks($permit, [(int) $data['risk_id']], false, Auth::id());
        $this->smartJsa->generate($permit, $permit->trades()->pluck('trades.id')->all());

        return back()->with('ok', 'رُبط الخطر وأُعيد اشتقاق بنوده.');
    }

    public function assignWorker(Request $request, Permit $permit)
    {
        $this->authorize('completeRequirement', $permit);
        $data = $request->validate([
            'worker_id'           => ['required', 'integer', 'exists:workers,id'],
            'qualification_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $worker = Worker::findOrFail($data['worker_id']);
        if ($permit->external_party_id && $worker->external_party_id !== $permit->external_party_id) {
            return back()->with('err', 'العامل ليس من عمال مقاول هذا التصريح.');
        }
        if (PermitWorker::where('permit_id', $permit->id)->where('worker_id', $worker->id)->exists()) {
            return back()->with('err', 'العامل مضاف مسبقاً.');
        }

        // حالة التأهيل لقطة وقت الإسناد من فحص الثغرات نفسه.
        $gaps = $this->workerGaps->gapsForWorker($worker);
        $status = match (true) {
            collect($gaps)->contains('severity', 'high') => 'not_qualified',
            $gaps !== []                                 => 'warning',
            default                                      => 'qualified',
        };

        PermitWorker::create([
            'permit_id'            => $permit->id,
            'worker_id'            => $worker->id,
            'qualification_status' => $status,
            'qualification_notes'  => $data['qualification_notes'] ?? collect($gaps)->pluck('label')->implode('، ') ?: null,
        ]);
        $this->permits->recordEvent($permit, 'worker_assigned', [
            'worker' => $worker->full_name, 'qualification' => $status,
        ], Auth::id());

        return back()->with('ok', 'أُسند العامل للتصريح ('.PermitWorker::STATUS_LABELS[$status].').');
    }

    public function removeWorker(Permit $permit, Worker $worker)
    {
        $this->authorize('completeRequirement', $permit);
        PermitWorker::where('permit_id', $permit->id)->where('worker_id', $worker->id)->delete();
        $this->permits->recordEvent($permit, 'worker_removed', ['worker' => $worker->full_name], Auth::id());

        return back()->with('ok', 'أُزيل العامل من التصريح.');
    }

    public function uploadAttachment(Request $request, Permit $permit)
    {
        $this->authorize('completeRequirement', $permit);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'file' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx'],
        ]);

        PermitAttachment::fromUpload($permit, $request->file('file'), $data['name'], Auth::id());
        $this->permits->recordEvent($permit, 'attachment_uploaded', ['name' => $data['name']], Auth::id());

        return back()->with('ok', 'رُفع المرفق.');
    }

    public function downloadAttachment(Permit $permit, PermitAttachment $attachment)
    {
        $this->authorize('view', $permit);
        abort_if($attachment->permit_id !== $permit->id, 404);

        return response(base64_decode($attachment->data), 200, [
            'Content-Type'        => $attachment->mime,
            'Content-Disposition' => 'inline; filename="'.rawurlencode($attachment->original_name ?: $attachment->name).'"',
        ]);
    }

    public function deleteAttachment(Permit $permit, PermitAttachment $attachment)
    {
        $this->authorize('completeRequirement', $permit);
        abort_if($attachment->permit_id !== $permit->id, 404);
        $attachment->delete();

        return back()->with('ok', 'حُذف المرفق.');
    }

    // ── الانحرافات ──

    public function recordDeviation(Request $request, Permit $permit)
    {
        $this->authorize('recordDeviation', $permit);
        $data = $request->validate([
            'description'             => ['required', 'string', 'min:10', 'max:2000'],
            'severity'                => ['required', 'in:low,medium,high'],
            'corrective_action_taken' => ['nullable', 'string', 'max:2000'],
            'permit_requirement_id'   => ['nullable', 'integer', 'exists:permit_requirements,id'],
        ]);

        PermitDeviation::create($data + [
            'permit_id'      => $permit->id,
            'status'         => PermitDeviation::STATUS_OPEN,
            'recorded_by_id' => Auth::id(),
            'recorded_at'    => now(),
            'signature_ip'   => $request->ip(),
        ]);
        $this->permits->recordEvent($permit, 'deviation_recorded', ['severity' => $data['severity']], Auth::id(), $data['description']);
        $this->notifier->deviationRecorded($permit, $data['severity'], Auth::id());

        return back()->with('ok', 'سُجّل الانحراف. لا يُغلق التصريح حتى يُعالج أو يُقبل بمبرر.');
    }

    public function resolveDeviation(Request $request, Permit $permit, PermitDeviation $deviation)
    {
        $this->authorize('recordDeviation', $permit);
        abort_if($deviation->permit_id !== $permit->id, 404);

        $data = $request->validate([
            'corrective_action_taken' => ['required', 'string', 'min:10', 'max:2000'],
            'resolution_status'       => ['required', 'in:resolved,accepted'],
        ]);

        $deviation->update([
            'corrective_action_taken' => $data['corrective_action_taken'],
            'status'                  => $data['resolution_status'],
            'resolved_at'             => now(),
            'resolved_by_id'          => Auth::id(),
        ]);
        $this->permits->recordEvent($permit, 'deviation_resolved', [
            'status' => $data['resolution_status'],
        ], Auth::id(), $data['corrective_action_taken']);

        return back()->with('ok', 'أُغلق الانحراف.');
    }

    // ── التقييم البعدي ──

    public function evaluate(Permit $permit)
    {
        $this->authorize('evaluate', $permit);
        $permit->load(['type', 'project', 'externalParty', 'place']);

        return view('modules.permits.evaluate', [
            'permit'              => $permit,
            'existing'            => $permit->metadata['evaluation'] ?? null,
            'groupedRequirements' => $this->smartJsa->groupedRequirements($permit),
            'linkedIncidents'     => $permit->incidents()->latest('created_at')->get(),
        ]);
    }

    public function saveEvaluation(Request $request, Permit $permit)
    {
        $this->authorize('evaluate', $permit);

        $data = $request->validate([
            'overall_rating'    => ['required', 'integer', 'between:1,5'],
            'severity_match'    => ['required', 'in:underestimated,accurate,overestimated'],
            'what_worked'       => ['nullable', 'string', 'max:2000'],
            'what_failed'       => ['nullable', 'string', 'max:2000'],
            'lessons_learned'   => ['nullable', 'string', 'max:2000'],
            'recommend_changes' => ['nullable', 'string', 'max:1000'],
        ]);

        $meta = $permit->metadata ?? [];
        unset($meta['learning_processed']); // تقييم جديد ← تُعاد المعالجة
        $meta['evaluation'] = $data + ['evaluated_by' => Auth::id(), 'evaluated_at' => now()->toIso8601String()];
        $permit->update(['metadata' => $meta]);

        $this->postClosure->process($permit->refresh(), Auth::id());

        $flagged = (int) ($permit->refresh()->metadata['flagged_controls'] ?? 0);
        $message = 'حُفظ التقييم وربطت بلاغات فترة التصريح.';
        if ($flagged > 0) {
            $message .= " عُلّم {$flagged} بند تحكم للمراجعة (يظهر في اللوحة عند تكرار الإخفاق).";
        }

        return redirect()->route('permits.show', $permit)->with('ok', $message);
    }

    // ── مساعدات ──

    private function validatePayload(Request $request, bool $creating): array
    {
        $rules = [
            'title'                => ['required', 'string', 'max:200'],
            'description'          => ['nullable', 'string', 'max:2000'],
            'scope'                => ['nullable', 'in:project,contractor_pre,contractor_post,individual'],
            'parent_permit_id'     => ['nullable', 'integer', 'exists:permits,id'],
            'project_id'           => ['nullable', 'integer', 'exists:projects,id'],
            'external_party_id'    => ['nullable', 'integer', 'exists:external_parties,id'],
            'organization_unit_id' => ['nullable', 'integer', 'exists:organization_units,id'],
            'place_id'             => ['nullable', 'integer', 'exists:places,id'],
            'subject_type'         => ['nullable', 'in:Worker,Equipment,OrganizationUnit,ExternalParty,Project'],
            'subject_id'           => ['nullable', 'integer'],
            'workers_count'        => ['nullable', 'integer', 'min:0', 'max:9999'],
            'equipment_count'      => ['nullable', 'integer', 'min:0', 'max:9999'],
            'location_description' => ['nullable', 'string', 'max:1000'],
            'sub_location'         => ['nullable', 'string', 'max:200'],
            'precautions'          => ['nullable', 'string', 'max:2000'],
            'additional_notes'     => ['nullable', 'string', 'max:2000'],
            'requester_name'       => ['nullable', 'string', 'max:150'],
            'requester_phone'      => ['nullable', 'string', 'max:30'],
            'starts_at'            => ['nullable', 'date'],
            'expires_at'           => ['nullable', 'date', 'after_or_equal:starts_at'],
            'trade_ids'            => ['nullable', 'array'],
            'trade_ids.*'          => ['integer', 'exists:trades,id'],
        ];
        if ($creating) {
            $rules['permit_type_id'] = ['required', 'integer', 'exists:permit_types,id'];
        }

        return $request->validate($rules, [], [
            'title' => 'عنوان التصريح', 'place_id' => 'المكان', 'project_id' => 'المشروع',
            'external_party_id' => 'المقاول', 'expires_at' => 'تاريخ الانتهاء',
        ]);
    }

    private function placeIdFromRequest(Request $request): ?int
    {
        if ($request->filled('place_id')) {
            return (int) $request->query('place_id');
        }
        if ($request->filled('place')) {
            return Place::idByCode((string) $request->query('place'));
        }

        return null;
    }

    /** الأطراف الظاهرة للمستخدم (حساب المقاول يرى طرفه فقط). */
    private function partiesForUser()
    {
        $query = ExternalParty::orderBy('name');
        $this->scopeToExternalParty($query, 'id');

        return $query->get(['id', 'name', 'party_type']);
    }

    /** عمال يصلحون للإسناد: عمال مقاول التصريح إن وُجد، وإلا كل العمال. */
    private function workersForPermit(Permit $permit)
    {
        return Worker::query()
            ->when($permit->external_party_id, fn ($q, $pid) => $q->where('external_party_id', $pid))
            ->orderBy('full_name')
            ->limit(300)
            ->get(['id', 'full_name', 'national_id', 'status', 'trade_id']);
    }

    /** مخاطر يمكن ربطها يدوياً: مخاطر المكان الفعّالة غير المرتبطة بعد. */
    private function risksForPermit(Permit $permit)
    {
        $linked = $permit->risks->pluck('id')->all();

        return Risk::query()
            ->when($permit->place_id, fn ($q, $pid) => $q->where('place_id', $pid))
            ->where('risk_type', 'active')
            ->whereNotIn('id', $linked)
            ->orderByDesc('risk_score')
            ->limit(50)
            ->get(['id', 'code', 'title', 'risk_score']);
    }

    /**
     * مقارنة قراءة بحدّ بند التحكم. يدعم «< ن» و«≥ ن» و«ن–م».
     * يعيد null إن تعذّرت المقارنة (حد مركّب) فيبقى الحكم للإنسان.
     */
    private function evaluateMeasurement(string $rawValue, ?string $threshold): ?bool
    {
        $value = trim($rawValue);
        if (!$threshold || !is_numeric($value)) {
            return null;
        }
        $value = (float) $value;
        $threshold = trim($threshold);

        if (preg_match('/^(\d+\.?\d*)\s*[–\-]\s*(\d+\.?\d*)$/u', $threshold, $m)) {
            return $value >= (float) $m[1] && $value <= (float) $m[2];
        }
        if (preg_match('/^([<>]=?|≤|≥|=)\s*(\d+\.?\d*)/u', $threshold, $m)) {
            $limit = (float) $m[2];

            return match ($m[1]) {
                '<'          => $value < $limit,
                '<=', '≤'    => $value <= $limit,
                '>'          => $value > $limit,
                '>=', '≥'    => $value >= $limit,
                '='          => abs($value - $limit) < 0.001,
                default      => null,
            };
        }

        return null;
    }
}
