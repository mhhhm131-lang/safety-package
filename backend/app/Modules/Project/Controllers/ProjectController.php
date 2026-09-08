<?php

namespace App\Modules\Project\Controllers;

use App\Core\Traits\AppliesOrgUnitScope;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Project\Models\Project;
use App\Modules\Project\Models\ProjectContractor;
use App\Modules\Project\Services\ProjectContractorService;
use App\Modules\Project\Services\ProjectService;
use Illuminate\Http\Request;

/**
 * المشاريع (من OHSMS بلا tenant/أنشطة اقتصادية/حدود الخطة). إضافة المعهد: المكان إلزامي؛ حساب المقاول يرى مشاريعه فقط.
 */
class ProjectController extends Controller
{
    use AppliesOrgUnitScope;

    public function __construct(protected ProjectService $service, protected ProjectContractorService $contractorService) {}

    public function index(Request $request)
    {
        $query = Project::with(['assignedCoordinator', 'place']);
        $this->scopeToUserOrgUnit($query);
        if ($pid = $this->contractorPartyId()) {
            $query->whereHas('projectContractors', fn ($q) => $q->where('external_party_id', $pid));
        }
        if ($request->filled('place')) {
            $query->where('place_id', Place::idByCode($request->place));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        $projects = $query->latest()->paginate(20)->withQueryString();
        return view('modules.projects.index', compact('projects'));
    }

    public function create()
    {
        return view('modules.projects.create', $this->formData());
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);
        $project = $this->service->create(auth()->id(), $validated);
        return redirect()->route('projects.show', $project)->with('success', 'أُنشئ المشروع «'.$project->name.'».');
    }

    public function show(Project $project)
    {
        $this->assertProjectAccess($project);
        $project = $this->service->getDetail($project);
        $risks = $this->service->getRisks($project);
        $project->load('projectContractors.externalParty');
        return view('modules.projects.detail', compact('project', 'risks'));
    }

    public function contractors(Project $project)
    {
        $this->assertProjectAccess($project);
        $project->load(['projectContractors.externalParty', 'projectContractors.events' => fn ($q) => $q->limit(5)]);
        $contractors = $project->projectContractors;
        if ($pid = $this->contractorPartyId()) {
            $contractors = $contractors->where('external_party_id', $pid);
        }
        return view('modules.projects.contractors', ['project' => $project, 'contractors' => $contractors]);
    }

    public function edit(Project $project)
    {
        return view('modules.projects.edit', ['project' => $project] + $this->formData());
    }

    public function update(Request $request, Project $project)
    {
        $validated = $this->validatePayload($request);
        $this->service->update($project, $validated);
        return redirect()->route('projects.show', $project)->with('success', 'حُدّث المشروع.');
    }

    public function dashboard(Project $project)
    {
        $this->assertProjectAccess($project);
        $data = $this->service->getDashboardData($project);
        return view('modules.projects.dashboard', compact('project', 'data'));
    }

    public function risks(Project $project)
    {
        $this->assertProjectAccess($project);
        $risks = $this->service->getRisks($project);
        return view('modules.projects.risks', compact('project', 'risks'));
    }

    public function comparison(Project $project)
    {
        $this->assertProjectAccess($project);
        $evaluations = $this->service->getComparison($project);
        return view('modules.projects.comparison', compact('project', 'evaluations'));
    }

    public function manhours(Project $project)
    {
        $this->assertProjectAccess($project);
        $manhours = $this->service->getManhours($project);
        $parties = $project->contractors()->orderBy('name')->get(['external_parties.id', 'name']);
        return view('modules.projects.manhours', compact('project', 'manhours', 'parties'));
    }

    public function manhourStore(Request $request, Project $project)
    {
        $validated = $request->validate([
            'date' => 'required|date', 'workers_count' => 'required|integer|min:1', 'hours_worked' => 'required|numeric|min:0',
            'external_party_id' => 'nullable|integer|exists:external_parties,id', 'incidents_count' => 'nullable|integer|min:0',
            'lost_time_incidents' => 'nullable|integer|min:0', 'notes' => 'nullable|string',
        ]);
        try {
            $this->service->storeManhour($project, auth()->id(), $validated);
            return redirect()->back()->with('success', 'أُضيف سجل ساعات العمل.');
        } catch (\Illuminate\Database\QueryException $e) {
            return redirect()->back()->withInput()->with('error', 'يوجد سجل لهذا المقاول في هذا اليوم.');
        }
    }

    /** إنشاء طرف سريع من شاشة الربط (JSON). */
    public function quickCreateParty(Request $request, Project $project)
    {
        $validated = $request->validate(['name' => 'required|string|max:200', 'party_type' => 'required|in:'.implode(',', array_keys(ExternalParty::TYPES)), 'cr_number' => 'nullable|string|max:50']);
        $party = ExternalParty::create(array_merge($validated, ['created_by_id' => auth()->id()]));
        return response()->json(['id' => $party->id, 'name' => $party->name, 'cr_number' => $party->cr_number]);
    }

    public function createNewContractor(Project $project)
    {
        return view('modules.projects.create_contractor', ['project' => $project, 'roles' => ProjectContractor::ROLES, 'types' => ExternalParty::TYPES]);
    }

    public function storeNewContractor(Request $request, Project $project)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:200', 'name_en' => 'nullable|string|max:200', 'party_type' => 'required|in:'.implode(',', array_keys(ExternalParty::TYPES)),
            'contact_person' => 'nullable|string|max:200', 'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:20', 'address' => 'nullable|string',
            'cr_number' => 'nullable|string|max:50', 'website_url' => 'nullable|url|max:500', 'registration_url' => 'nullable|url|max:500',
            'role' => 'required|in:'.implode(',', array_keys(ProjectContractor::ROLES)), 'activity_scope' => 'nullable|string|max:500',
            'contract_start_date' => 'nullable|date', 'contract_end_date' => 'nullable|date|after_or_equal:contract_start_date', 'contract_notes' => 'nullable|string|max:1000',
        ]);
        $party = ExternalParty::create([
            'name' => $validated['name'], 'name_en' => $validated['name_en'] ?? null, 'party_type' => $validated['party_type'],
            'contact_person' => $validated['contact_person'] ?? null, 'email' => $validated['email'] ?? null, 'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null, 'cr_number' => $validated['cr_number'] ?? null, 'website_url' => $validated['website_url'] ?? null,
            'registration_url' => $validated['registration_url'] ?? null, 'created_by_id' => auth()->id(),
        ]);
        $this->contractorService->attachAndNotify($project, $party, $validated['role'], auth()->id(), array_filter([
            'activity_scope' => $validated['activity_scope'] ?? null, 'contract_start_date' => $validated['contract_start_date'] ?? null,
            'contract_end_date' => $validated['contract_end_date'] ?? null, 'notes' => $validated['contract_notes'] ?? null,
        ]));
        return redirect()->route('projects.contractors', $project)->with('success', "أُنشئ {$party->name} ورُبط بالمشروع.");
    }

    public function assignContractor(Project $project)
    {
        $parties = ExternalParty::orderBy('name')->get(['id', 'name', 'cr_number']);
        return view('modules.projects.assign_contractor', ['project' => $project, 'parties' => $parties, 'roles' => ProjectContractor::ROLES]);
    }

    public function storeContractor(Request $request, Project $project)
    {
        $validated = $request->validate([
            'external_party_id' => 'required|integer|exists:external_parties,id', 'role' => 'required|in:'.implode(',', array_keys(ProjectContractor::ROLES)),
            'activity_scope' => 'nullable|string|max:500', 'contract_start_date' => 'nullable|date',
            'contract_end_date' => 'nullable|date|after_or_equal:contract_start_date', 'notes' => 'nullable|string|max:1000',
        ]);
        $party = ExternalParty::findOrFail($validated['external_party_id']);
        $this->contractorService->attachAndNotify($project, $party, $validated['role'], auth()->id(), array_filter([
            'activity_scope' => $validated['activity_scope'] ?? null, 'contract_start_date' => $validated['contract_start_date'] ?? null,
            'contract_end_date' => $validated['contract_end_date'] ?? null, 'notes' => $validated['notes'] ?? null,
        ]));
        return redirect()->route('projects.contractors', $project)->with('success', "أُضيف {$party->name} إلى المشروع.");
    }

    /** انتقال حالة تأهيل المقاول في المشروع (آلة الحالة بالدور). */
    public function transitionContractor(Request $request, Project $project, ProjectContractor $contractor)
    {
        abort_unless($contractor->project_id === $project->id, 404);
        $v = $request->validate(['status' => 'required|in:'.implode(',', array_keys(ProjectContractor::STATUSES)), 'notes' => 'nullable|string|max:1000']);
        // المقاول نفسه يقدّم للمراجعة فقط؛ الاعتماد لمسؤول السلامة والمناوب والمنسق
        if ($this->contractorPartyId()) {
            $this->assertPartyAccess($contractor->external_party_id);
            if (!in_array($v['status'], [ProjectContractor::STATUS_PRE_REVIEW, ProjectContractor::STATUS_POST_REVIEW], true)) {
                abort(403, 'المقاول يقدّم للمراجعة فقط.');
            }
        }
        try {
            $this->contractorService->transition($contractor, $v['status'], auth()->id(), $v['notes'] ?? null, auth()->user()->role());
            return back()->with('success', 'صارت حالة التأهيل: '.$contractor->fresh()->getStatusLabel());
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    private function formData(): array
    {
        return [
            'places' => Place::orderBy('sort')->get(),
            'units' => OrganizationUnit::where('is_active', true)->orderBy('order')->get(),
            'coordinators' => User::whereHas('profile', fn ($q) => $q->whereIn('role', ['safety_coordinator', 'system_admin', 'system_staff'])->where('is_active', true))->orderBy('name')->get(['id', 'name']),
            'statuses' => Project::STATUSES,
        ];
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:200', 'name_en' => 'nullable|string|max:200', 'code' => 'nullable|string|max:50', 'description' => 'nullable|string',
            'status' => 'nullable|in:'.implode(',', array_keys(Project::STATUSES)),
            'place_id' => 'required|exists:places,id', // المعهد: مكان التنفيذ إلزامي
            'organization_unit_id' => 'nullable|exists:organization_units,id',
            'assigned_coordinator_id' => 'nullable|exists:users,id',
            'start_date' => 'nullable|date', 'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);
    }

    private function assertProjectAccess(Project $project): void
    {
        if ($pid = $this->contractorPartyId()) {
            abort_unless($project->projectContractors()->where('external_party_id', $pid)->exists(), 403, 'هذا المشروع لطرف آخر.');
        }
    }
}
