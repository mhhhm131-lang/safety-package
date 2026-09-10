<?php

namespace App\Modules\Incident\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Governance\Models\Place;
use App\Modules\Incident\Models\Incident;
use App\Modules\Incident\Models\IncidentAttachment;
use App\Modules\Incident\Services\IncidentClosureService;
use App\Modules\Incident\Services\IncidentEmergencyBridge;
use App\Modules\Incident\Services\IncidentService;
use App\Modules\Incident\Services\IncidentVisibilityService;
use App\Modules\Incident\Services\OccSync;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskPhase;
use App\Modules\Risk\Models\RiskSubCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * بلاغ الشاغل: الصفحات العامة الثلاث (بلا دخول، بمرشح معدل) وتتبع بالرمز، وشاشات مركز السلامة.
 * منقول من OHSMS بلا tenant. الصفحة العامة تحل محل report.html (BACKEND.md ٥-٢).
 */
class IncidentController extends Controller
{
    public function __construct(
        protected IncidentService $incidentService,
        protected IncidentVisibilityService $visibilityService,
        protected IncidentClosureService $closureService,
        protected OccSync $occSync,
    ) {}

    // ───────────── الصفحات العامة ─────────────

    public function landing(Request $request)
    {
        return view('modules.incidents.landing', ['places' => Place::orderBy('sort')->get(), 'place' => $request->query('place')]);
    }

    public function form(Request $request, string $type)
    {
        abort_unless(in_array($type, ['normal', 'urgent', 'secret'], true), 404);
        return view('modules.incidents.form', [
            'type' => $type,
            'places' => Place::orderBy('sort')->get(),
            'preset' => $request->query('place'),
            'riskCategories' => RiskCategory::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, string $type)
    {
        abort_unless(in_array($type, ['normal', 'urgent', 'secret'], true), 404);
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'description' => ['required', 'string', 'min:5', 'max:5000'],
            'risk_id' => [$type === 'secret' ? 'nullable' : 'required', 'integer', 'exists:risks,id'],
            'place_id' => ['required', 'integer', 'exists:places,id'], // المكان إلزامي: عليه يقوم التوجيه إلى فني المكان
            'location_text' => ['nullable', 'string', 'max:200'],
            'reporter_name' => ['nullable', 'string', 'max:120'],
            'reporter_phone' => ['nullable', 'string', 'max:30'],
            'secrecy_reason' => ['nullable', 'string', 'max:1000'],
            'photo' => ['nullable', 'string', 'max:4500000'],
        ], [
            'description.required' => 'اكتب ما رأيته قبل الإرسال.',
            'place_id.required' => 'اختر المكان — عليه تُحال البلاغات إلى فني المكان.',
            'risk_id.required' => 'اختر نوع الخطر من التصنيف (الفئة ← الفرعية ← الخطر).',
        ]);

        try {
            if ($type === 'secret') {
                $result = $this->incidentService->createSecretIncident($validated);
                $incident = $result['incident'];
            } else {
                $incident = $this->incidentService->createIncident($type, Auth::id(), $validated);
            }
            $this->occSync->refresh(Auth::id());
            return redirect()->route('incident.success', ['code' => $incident->secret_tracking_code ?: $incident->code, 'id' => Auth::id() ? $incident->id : null]);
        } catch (\Throwable $e) {
            report($e);
            return redirect()->back()->withInput()->with('error', 'تعذّر إرسال البلاغ: '.$e->getMessage());
        }
    }

    public function success(Request $request)
    {
        $code = (string) $request->query('code');
        $incident = Incident::where('secret_tracking_code', $code)->orWhere('code', $code)->first();
        return view('modules.incidents.success', ['incident' => $incident, 'code' => $code, 'id' => $request->query('id')]);
    }

    /** التتبع بالرمز: الخط الزمني نفسه بلا هوية. */
    public function track(Request $request)
    {
        $incident = null;
        $code = trim((string) $request->input('tracking_code', $request->query('code', '')));
        if ($code !== '') {
            $incident = Incident::with(['events' => fn ($q) => $q->orderBy('id'), 'place'])->where('secret_tracking_code', strtoupper($code))->first();
            if (!$incident && $request->isMethod('post')) {
                return redirect()->back()->withInput()->withErrors(['tracking_code' => 'لا يوجد بلاغ بهذا الرمز.']);
            }
        }
        return view('modules.incidents.track', ['incident' => $incident, 'tracking_code' => $code]);
    }

    public function apiSubCategories(Request $request): JsonResponse
    {
        $request->validate(['category_id' => ['required', 'integer', 'exists:risk_categories,id']]);
        return response()->json(RiskSubCategory::where('category_id', $request->integer('category_id'))->orderBy('name')->get(['id', 'name']));
    }

    /** المبلّغ يختار من السجل العام للمعهد (المرجعي المعتمد)؛ التوجيه عبر الفعلي يتم في الخدمة. */
    public function apiRisks(Request $request): JsonResponse
    {
        $request->validate(['sub_category_id' => ['required', 'integer', 'exists:risk_sub_categories,id']]);
        $risks = Risk::where('sub_category_id', $request->integer('sub_category_id'))
            ->where('risk_type', 'reference')->whereIn('status', ['approved', 'active'])
            ->orderBy('title')
            ->with(['phases' => fn ($q) => $q->where('phase', RiskPhase::PHASE_PROACTIVE)])
            ->get(['id', 'code', 'title', 'risk_score', 'severity', 'likelihood']);
        return response()->json($risks->map(fn ($r) => [
            'id' => $r->id, 'code' => $r->code, 'title' => $r->title, 'risk_score' => $r->risk_score,
            'corrective_action' => $r->phases->first()?->corrective_action,
            'preventive_action' => $r->phases->first()?->preventive_action,
        ])->values());
    }

    // ───────────── شاشات المركز ─────────────

    public function index(Request $request)
    {
        $user = Auth::user();
        $counts = $this->incidentService->getDashboardData($user->id, $this->visibilityService);
        $q = $this->visibilityService->getVisibleIncidents($user->id)->with(['organizationUnit', 'place', 'actor', 'incidentCoordinator', 'incidentFieldTeam', 'risk']);
        if ($s = $request->input('status')) {
            if ($s === 'open') $q->whereNotIn('status', Incident::TERMINAL);
            elseif ($s === 'overdue') $q->whereIn('status', Incident::BEFORE_FIELD)->whereNotNull('deadline_at')->where('deadline_at', '<', now());
            else $q->where('status', $s);
        }
        if ($t = $request->input('type')) $q->where('incident_type', $t);
        if ($p = $request->input('place')) $q->whereHas('place', fn ($x) => $x->where('code', $p));
        if ($d = $request->input('date_from')) $q->whereDate('created_at', '>=', $d);
        if ($d = $request->input('date_to')) $q->whereDate('created_at', '<=', $d);
        if ($search = $request->input('search')) {
            $q->where(fn ($x) => $x->where('code', 'like', "%$search%")->orWhere('title', 'like', "%$search%")->orWhere('description', 'like', "%$search%"));
        }
        $incidents = $q->latest()->paginate(25);
        return view('modules.incidents.dashboard', ['counts' => $counts, 'incidents' => $incidents, 'places' => Place::orderBy('sort')->get()]);
    }

    public function show(Incident $incident)
    {
        $user = Auth::user();
        if (!$this->visibilityService->canView($incident, $user->id)) {
            abort(403, 'لا تملك صلاحية عرض هذا البلاغ.');
        }
        $incident = $this->incidentService->getDetail($incident->id) ?? $incident;
        $fieldWorkers = User::whereHas('profile', fn ($q) => $q->where('is_active', true)->where('role', 'field_worker'))
            ->with('profile.place')->orderBy('name')->get()
            ->sortByDesc(fn ($u) => (int) ($u->profile?->place_id && $u->profile->place_id === $incident->place_id));
        $coordinators = User::whereHas('profile', fn ($q) => $q->where('is_active', true)->where('role', 'safety_coordinator'))->orderBy('name')->get();
        $role = $user->role();
        $bridge = app(IncidentEmergencyBridge::class);
        return view('modules.incidents.detail', [
            'incident' => $incident, 'fieldWorkers' => $fieldWorkers, 'coordinators' => $coordinators,
            // المرحلة ١٠-٣: الطبقة بنوع البلاغ، والحالة الطارئة المفعَّلة منه، والنوع المقترح
            'layer' => $bridge->riskLayer($incident), 'linkedEmergency' => $bridge->linkedEmergency($incident),
            'proposedType' => $bridge->proposeType($incident), 'emergencyTypes' => IncidentEmergencyBridge::TYPE_OPTIONS,
            'canTrigger' => $user->can('manage', $incident) && \App\Core\Permissions\PermissionRegistry::hasPermission($role, 'emergency.trigger'),
            'isCenter' => in_array($role, ['system_admin', 'system_staff'], true),
            'isField' => $incident->incident_field_team_id === $user->id,
            'isCoord' => $incident->incident_coordinator_id === $user->id || ($role === 'safety_coordinator' && !$incident->incident_coordinator_id),
            'isCommittee' => $role === 'safety_committee' || $role === 'system_admin',
            'canManage' => $user->can('manage', $incident),
            'referenceRisks' => $incident->risk_id ? collect() : Risk::where('risk_type', 'reference')->whereIn('status', ['approved', 'active'])->orderBy('code')->get(['id', 'code', 'title']),
        ]);
    }

    public function attachment(Incident $incident, IncidentAttachment $attachment)
    {
        abort_unless($attachment->incident_id === $incident->id, 404);
        abort_unless($this->visibilityService->canView($incident, Auth::id()), 403);
        return response(base64_decode($attachment->data), 200, ['Content-Type' => $attachment->mime, 'Cache-Control' => 'private, max-age=3600']);
    }

    public function refer(Request $request, Incident $incident)
    {
        $v = $request->validate(['field_worker_id' => ['required', 'integer', 'exists:users,id'],
            'coordinator_id' => ['nullable', 'integer', 'exists:users,id'], 'note' => ['nullable', 'string', 'max:2000']]);
        return $this->act(fn () => $this->incidentService->referToField($incident, Auth::id(), (int) $v['field_worker_id'], $v['coordinator_id'] ?? null, $v['note'] ?? null), 'أُحيل البلاغ إلى الفني.');
    }

    public function closeWithNote(Request $request, Incident $incident)
    {
        $v = $request->validate(['note' => ['required', 'string', 'min:5', 'max:5000']]);
        return $this->act(fn () => $this->closureService->closeWithNote($incident, Auth::id(), $v['note']), 'أُغلق البلاغ بملاحظة.');
    }

    /** المرحلة ١٠-٣ (و): المركز يفعّل حالة طارئة من البلاغ — القرار بيد المركز، النوع مقترح من صنف الخطر ويغيّره. */
    public function triggerEmergency(Request $request, Incident $incident)
    {
        abort_unless(Auth::user()->can('manage', $incident), 403, 'التفعيل من البلاغ لمركز السلامة.');
        $v = $request->validate([
            'incident_type' => ['required', 'in:'.implode(',', IncidentEmergencyBridge::TYPE_OPTIONS)],
            'severity' => ['required', 'in:low,medium,high,critical'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        try {
            $emergency = app(IncidentEmergencyBridge::class)->trigger($incident, Auth::user(), $v['incident_type'], $v['severity'], $v['note'] ?? null);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
        $this->occSync->refresh(Auth::id());
        return redirect()->route('emergency.incidents.live', $emergency)->with('success', 'فُعّلت الحالة الطارئة '.$emergency->incident_code.' من البلاغ '.$incident->code.' — خطوات خطة المكان تعمل الآن');
    }

    public function fieldReceive(Incident $incident)
    {
        return $this->act(fn () => $this->incidentService->fieldReceive($incident, Auth::id()), 'سُجّل استلامك للبلاغ.');
    }

    public function beginWork(Incident $incident)
    {
        return $this->act(fn () => $this->incidentService->beginWork($incident, Auth::id()), 'بدأت المعالجة.');
    }

    public function addNote(Request $request, Incident $incident)
    {
        $v = $request->validate(['note' => ['required', 'string', 'max:5000']]);
        return $this->act(fn () => $this->incidentService->addNote($incident, Auth::id(), $v['note']), 'أُضيفت الملاحظة.');
    }

    public function upload(Request $request, Incident $incident)
    {
        $request->validate(['file' => ['required', 'file', 'max:3072', 'mimes:jpg,jpeg,png,webp,pdf']]);
        $f = $request->file('file');
        return $this->act(fn () => $this->incidentService->addAttachment($incident, Auth::id(), 'evidence', $f->getMimeType(), file_get_contents($f->getRealPath()), $f->getClientOriginalName()), 'رُفع المرفق.');
    }

    public function updateActions(Request $request, Incident $incident)
    {
        if (in_array($incident->status, Incident::TERMINAL, true)) {
            return redirect()->route('incidents.show', $incident)->with('error', 'لا تُحدَّث إجراءات بلاغ مغلق أو خارج النطاق.');
        }
        $v = $request->validate(['corrective_action' => ['nullable', 'string', 'max:5000'], 'preventive_action' => ['nullable', 'string', 'max:5000']]);
        $this->incidentService->updateActions($incident, $v);
        return redirect()->route('incidents.show', $incident)->with('success', 'حُدّثت الإجراءات لهذا البلاغ.');
    }

    public function resolve(Request $request, Incident $incident)
    {
        $v = $request->validate(['resolution_summary' => ['required', 'string', 'min:30', 'max:5000']]);
        return $this->act(fn () => $this->incidentService->resolve($incident, Auth::id(), $v['resolution_summary']), 'سُجّل أن البلاغ عولج.');
    }

    public function close(Incident $incident)
    {
        return $this->act(fn () => $this->closureService->close($incident, Auth::id()), 'أُغلق البلاغ.');
    }

    public function approveClosure(Incident $incident)
    {
        return $this->act(fn () => $this->closureService->approveClosure($incident, Auth::id()), 'اعتُمد الإغلاق. شكراً لك.');
    }

    public function rejectClosure(Request $request, Incident $incident)
    {
        $v = $request->validate(['note' => ['required', 'string', 'max:5000']]);
        return $this->act(fn () => $this->closureService->rejectClosure($incident, Auth::id(), $v['note']), 'رُفض الإغلاق وأُعيد البلاغ إلى المعالجة.');
    }

    public function escalateToCoordinator(Request $request, Incident $incident)
    {
        $v = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:5000']]);
        return $this->act(fn () => $this->incidentService->escalateToCoordinator($incident, Auth::id(), $v['reason']), 'صُعّد البلاغ إلى منسق السلامة.');
    }

    public function escalateToManager(Request $request, Incident $incident)
    {
        $v = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:5000']]);
        return $this->act(fn () => $this->incidentService->escalateToManager($incident, Auth::id(), $v['reason']), 'صُعّد البلاغ إلى لجنة السلامة.');
    }

    public function resolveEscalation(Incident $incident)
    {
        return $this->act(fn () => $this->incidentService->resolveEscalation($incident, Auth::id()), 'تولّيت المعالجة.');
    }

    public function verifyByCoordinator(Incident $incident)
    {
        return $this->act(fn () => $this->closureService->verifyByCoordinator($incident, Auth::id()), 'سُجّل التحقق الميداني.');
    }

    public function outOfScope(Request $request, Incident $incident)
    {
        $v = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        return $this->act(fn () => $this->incidentService->outOfScope($incident, Auth::id(), $v['note'] ?? null), 'عُلّم البلاغ خارج النطاق.');
    }

    public function linkRisk(Request $request, Incident $incident)
    {
        $v = $request->validate(['risk_id' => ['required', 'integer', 'exists:risks,id']]);
        return $this->act(fn () => $this->incidentService->linkRisk($incident, Auth::id(), (int) $v['risk_id']), 'رُبط الخطر بالبلاغ.');
    }

    public function export(): StreamedResponse
    {
        $incidents = $this->visibilityService->getVisibleIncidents(Auth::id())->with(['place', 'organizationUnit', 'risk'])->orderByDesc('created_at')->get();
        $filename = 'occupant_incidents_'.now()->format('Y-m-d_His').'.csv';
        return response()->stream(function () use ($incidents) {
            $h = fopen('php://output', 'w');
            fprintf($h, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($h, ['الرقم', 'العنوان', 'النوع', 'الحالة', 'المكان', 'الموضع', 'الخطر', 'الوحدة', 'المبلّغ', 'أُرسل', 'وصل الفني', 'عولج', 'أُغلق', 'تجاوز المهلة']);
            foreach ($incidents as $i) {
                fputcsv($h, [$i->code, $i->title, $i->type_label, $i->status_label, $i->place?->name, $i->location_text, $i->risk?->code,
                    $i->organizationUnit?->name, $i->reporterDisplay(), $i->created_at?->toDateTimeString(), $i->field_received_at?->toDateTimeString(),
                    $i->resolved_at?->toDateTimeString(), $i->closed_at?->toDateTimeString(), $i->overdue_at ? 'نعم' : '']);
            }
            fclose($h);
        }, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"$filename\""]);
    }

    private function act(callable $fn, string $ok)
    {
        try {
            $fn();
            $this->occSync->refresh(Auth::id());
            return redirect()->back()->with('success', $ok);
        } catch (\Throwable $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }
}
