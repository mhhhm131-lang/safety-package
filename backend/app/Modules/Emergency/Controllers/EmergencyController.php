<?php

namespace App\Modules\Emergency\Controllers;

use App\Core\StateMachine\Exceptions\TransitionException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Emergency\Models\AssemblyPoint;
use App\Modules\Emergency\Models\BuildingExit;
use App\Modules\Emergency\Models\BuildingFloor;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyContact;
use App\Modules\Emergency\Models\EmergencyEquipment;
use App\Modules\Emergency\Models\EmergencyEquipmentInspection;
use App\Modules\Emergency\Models\EmergencyEventLog;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Models\EmergencyIncidentStep;
use App\Modules\Emergency\Models\EmergencyTeam;
use App\Modules\Emergency\Models\EmergencyTeamMember;
use App\Modules\Emergency\Models\EvacuationCheckIn;
use App\Modules\Emergency\Models\EvacuationDrill;
use App\Modules\Emergency\Models\Lockdown;
use App\Modules\Emergency\Models\PanicAlert;
use App\Modules\Emergency\Models\ResponsePlan;
use App\Modules\Emergency\Services\AutoEscalationService;
use App\Modules\Emergency\Services\DrillService;
use App\Modules\Emergency\Services\EmergencyAnalyticsService;
use App\Modules\Emergency\Services\EmergencyService;
use App\Modules\Emergency\Services\IncidentStepsService;
use App\Modules\Emergency\Services\LockdownService;
use App\Modules\Emergency\Services\MedicalProfileService;
use App\Modules\Emergency\Services\PanicAlertService;
use App\Modules\Emergency\Services\QrMusteringService;
use App\Modules\Emergency\Services\ResponsePlanSync;
use App\Modules\Emergency\Services\TeamSync;
use App\Modules\Emergency\Support\RoleCards;
use App\Modules\Emergency\Services\VisitorService;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\Setting;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

/**
 * شاشات الطوارئ (من OHSMS بلا tenant/project). الصلاحيات على المسارات (permission:) والسياسة على الكائن.
 * المعهد مبنى واحد؛ الحالة تُربط بالمكان (HZ)؛ الفريق الأولي مشتق من ملف المكان في اللوحة.
 */
class EmergencyController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected EmergencyService $service,
        protected QrMusteringService $musteringService,
    ) {}

    // ==================== الرئيسية ====================

    public function dashboard()
    {
        $stats = $this->service->getDashboardStats();
        $buildings = EmergencyBuilding::with(['floors', 'assemblyPoints'])->orderBy('name')->get();
        $activeIncidents = EmergencyIncident::open()->with('building', 'place')->orderByDesc('triggered_at')->get();
        $recentIncidents = EmergencyIncident::whereIn('status', ['ended', 'cancelled'])->with('place')->orderByDesc('triggered_at')->limit(8)->get();
        $upcomingDrills = EvacuationDrill::where('status', 'scheduled')->where('scheduled_at', '>=', now())->orderBy('scheduled_at')->limit(5)->with('building', 'place')->get();
        $places = Place::orderBy('sort')->get();
        $teamsByPlace = EmergencyTeam::active()->whereNotNull('place_id')->get()->groupBy('place_id');
        $pendingCalls = EmergencyIncident::open()->pluck('id')->isEmpty() ? 0
            : \App\Modules\Emergency\Models\EmergencyNotification::whereIn('incident_id', EmergencyIncident::open()->pluck('id'))->manual()->count();
        $mainBuilding = EmergencyBuilding::main();
        $escalationRules = app(AutoEscalationService::class)->getEscalationRules();

        return view('modules.emergency.dashboard', compact(
            'stats', 'buildings', 'activeIncidents', 'recentIncidents', 'upcomingDrills', 'places', 'teamsByPlace', 'pendingCalls', 'mainBuilding', 'escalationRules'
        ));
    }

    // ==================== المبنى ====================

    public function buildingsIndex()
    {
        $buildings = EmergencyBuilding::withCount(['floors', 'assemblyPoints', 'teams', 'equipment'])->orderBy('name')->paginate(20);
        return view('modules.emergency.buildings.index', compact('buildings'));
    }

    public function buildingsCreate()
    {
        return view('modules.emergency.buildings.create');
    }

    public function buildingsStore(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:200',
            'name_en' => 'nullable|string|max:200',
            'code' => 'nullable|string|max:20|unique:emergency_buildings,code',
            'address' => 'nullable|string',
            'building_type' => 'required|in:'.implode(',', array_keys(EmergencyBuilding::TYPES)),
            'floors_count' => 'required|integer|min:1|max:200',
            'basement_floors' => 'nullable|integer|min:0|max:20',
            'total_capacity' => 'nullable|integer|min:1',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'risk_level' => 'required|in:low,medium,high,critical',
        ]);
        $validated['created_by_id'] = auth()->id();
        $building = EmergencyBuilding::create($validated);

        for ($i = -($validated['basement_floors'] ?? 0); $i <= $validated['floors_count']; $i++) {
            if ($i === 0 && ($validated['basement_floors'] ?? 0) > 0) continue;
            BuildingFloor::create(['building_id' => $building->id, 'floor_number' => $i, 'created_at' => now()]);
        }
        return redirect()->route('emergency.buildings.show', $building)->with('success', 'تم إنشاء المبنى');
    }

    public function buildingsShow(EmergencyBuilding $building)
    {
        $building->load(['floors.responsible', 'floors.exits', 'assemblyPoints.place', 'exits.floor', 'exits.assemblyPoint', 'teams.members', 'teams.place', 'contacts']);
        $status = $this->service->getBuildingStatus($building);
        $places = Place::orderBy('sort')->get();
        $users = User::whereHas('profile', fn ($q) => $q->where('is_active', true))->orderBy('name')->get(['id', 'name']);
        return view('modules.emergency.buildings.show', compact('building', 'status', 'places', 'users'));
    }

    public function buildingsEdit(EmergencyBuilding $building)
    {
        return view('modules.emergency.buildings.edit', compact('building'));
    }

    public function buildingsUpdate(Request $request, EmergencyBuilding $building)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:200',
            'name_en' => 'nullable|string|max:200',
            'code' => 'nullable|string|max:20|unique:emergency_buildings,code,'.$building->id,
            'address' => 'nullable|string',
            'building_type' => 'required|in:'.implode(',', array_keys(EmergencyBuilding::TYPES)),
            'total_capacity' => 'nullable|integer|min:1',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'risk_level' => 'required|in:low,medium,high,critical',
            'status' => 'required|in:active,inactive,under_maintenance',
        ]);
        $building->update($validated);
        return redirect()->route('emergency.buildings.show', $building)->with('success', 'تم تحديث المبنى');
    }

    /** الطوابق والمخارج ونقاط التجمع تُملأ بالواقع من شاشة المبنى (الفجوتان ٣ و٦). */
    public function floorsStore(Request $request, EmergencyBuilding $building)
    {
        $v = $request->validate([
            'floor_number' => 'required|integer|min:-20|max:200|unique:building_floors,floor_number,NULL,id,building_id,'.$building->id,
            'name' => 'nullable|string|max:100', 'zone' => 'nullable|string|max:100',
            'capacity' => 'nullable|integer|min:0', 'evacuation_order' => 'nullable|integer|min:0|max:255',
            'responsible_id' => 'nullable|exists:users,id',
        ]);
        BuildingFloor::create($v + ['building_id' => $building->id, 'created_at' => now()]);
        $building->update(['floors_count' => max($building->floors_count, (int) $v['floor_number']), 'basement_floors' => max($building->basement_floors, -(int) $v['floor_number'])]);
        return back()->with('success', 'أُضيف الطابق');
    }

    public function floorsUpdate(Request $request, EmergencyBuilding $building, BuildingFloor $floor)
    {
        abort_unless($floor->building_id === $building->id, 404);
        $v = $request->validate([
            'name' => 'nullable|string|max:100', 'zone' => 'nullable|string|max:100', 'capacity' => 'nullable|integer|min:0',
            'evacuation_order' => 'nullable|integer|min:0|max:255', 'responsible_id' => 'nullable|exists:users,id',
            'status' => 'nullable|in:normal,evacuating,cleared,blocked',
        ]);
        $floor->update($v);
        return back()->with('success', 'حُدّث الطابق');
    }

    public function floorsDestroy(EmergencyBuilding $building, BuildingFloor $floor)
    {
        abort_unless($floor->building_id === $building->id, 404);
        $floor->delete();
        return back()->with('success', 'حُذف الطابق');
    }

    public function exitsStore(Request $request, EmergencyBuilding $building)
    {
        $v = $request->validate([
            'floor_id' => 'required|exists:building_floors,id', 'code' => 'required|string|max:10', 'name' => 'nullable|string|max:100',
            'exit_type' => 'required|in:main,emergency,fire_escape,service', 'direction' => 'nullable|string|max:50',
            'width_meters' => 'nullable|numeric|min:0|max:99', 'is_accessible' => 'nullable|boolean',
            'leads_to_point_id' => 'nullable|exists:assembly_points,id', 'status' => 'nullable|in:available,blocked,maintenance',
        ]);
        $v['is_accessible'] = $request->boolean('is_accessible');
        BuildingExit::create($v + ['building_id' => $building->id, 'created_at' => now()]);
        return back()->with('success', 'أُضيف المخرج');
    }

    public function exitsUpdate(Request $request, EmergencyBuilding $building, BuildingExit $exit)
    {
        abort_unless($exit->building_id === $building->id, 404);
        $v = $request->validate([
            'status' => 'nullable|in:available,blocked,maintenance', 'name' => 'nullable|string|max:100',
            'leads_to_point_id' => 'nullable|exists:assembly_points,id', 'is_accessible' => 'nullable|boolean',
        ]);
        if ($request->has('is_accessible')) $v['is_accessible'] = $request->boolean('is_accessible');
        $exit->update($v);
        return back()->with('success', 'حُدّث المخرج');
    }

    public function exitsDestroy(EmergencyBuilding $building, BuildingExit $exit)
    {
        abort_unless($exit->building_id === $building->id, 404);
        $exit->delete();
        return back()->with('success', 'حُذف المخرج');
    }

    public function assemblyPointsStore(Request $request, EmergencyBuilding $building)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:10', 'name' => 'required|string|max:100', 'place_id' => 'nullable|exists:places,id',
            'latitude' => 'nullable|numeric', 'longitude' => 'nullable|numeric', 'capacity' => 'nullable|integer|min:1',
            'is_primary' => 'nullable|boolean', 'is_accessible' => 'nullable|boolean', 'directions' => 'nullable|string',
            'responsible_id' => 'nullable|exists:users,id',
        ]);
        $validated['building_id'] = $building->id;
        $validated['is_primary'] = $request->boolean('is_primary');
        $validated['is_accessible'] = $request->boolean('is_accessible', true);
        if ($validated['is_primary']) {
            AssemblyPoint::where('building_id', $building->id)->update(['is_primary' => false]);
        }
        AssemblyPoint::create($validated);
        return redirect()->route('emergency.buildings.show', $building)->with('success', 'أُضيفت نقطة التجمع');
    }

    public function assemblyPointsUpdate(Request $request, EmergencyBuilding $building, AssemblyPoint $point)
    {
        abort_unless($point->building_id === $building->id, 404);
        $v = $request->validate([
            'name' => 'nullable|string|max:100', 'place_id' => 'nullable|exists:places,id', 'capacity' => 'nullable|integer|min:1',
            'is_primary' => 'nullable|boolean', 'directions' => 'nullable|string', 'status' => 'nullable|in:active,inactive',
        ]);
        if ($request->boolean('is_primary')) {
            AssemblyPoint::where('building_id', $building->id)->update(['is_primary' => false]);
            $v['is_primary'] = true;
        }
        $point->update($v);
        return back()->with('success', 'حُدّثت نقطة التجمع');
    }

    public function assemblyPointsDestroy(EmergencyBuilding $building, AssemblyPoint $point)
    {
        abort_unless($point->building_id === $building->id, 404);
        $point->delete();
        return back()->with('success', 'حُذفت نقطة التجمع');
    }

    // ==================== لوحة التحكم والتفعيل ====================

    public function buildingControl(EmergencyBuilding $building)
    {
        $building->load(['floors', 'assemblyPoints', 'teams.members']);
        $activeIncident = $building->getActiveIncident();
        $stats = $activeIncident ? $this->musteringService->getLiveStats($activeIncident) : null;
        if ($activeIncident) $activeIncident->load('eventLogs.user', 'place');
        $places = Place::orderBy('sort')->get();
        $lockdown = $building->activeLockdown();
        $user = auth()->user();
        return view('modules.emergency.buildings.control', compact('building', 'activeIncident', 'stats', 'places', 'lockdown', 'user'));
    }

    public function triggerAlarm(Request $request, EmergencyBuilding $building)
    {
        $this->authorize('trigger', EmergencyIncident::class);
        $validated = $request->validate([
            'incident_type' => 'required|in:'.implode(',', array_diff(array_keys(EmergencyIncident::TYPES), ['lockdown'])),
            'severity' => 'required|in:low,medium,high,critical',
            'place_id' => 'required|exists:places,id', // المعهد: الحالة تُربط بالمكان
            'is_drill' => 'nullable|boolean',
            'description' => 'nullable|string|max:2000',
        ]);
        if ($building->getActiveIncident()) {
            return back()->with('error', 'توجد حالة طارئة مفتوحة في هذا المبنى. أنهِها أو ألغِها أولاً.');
        }
        $incident = $this->service->triggerAlarm(
            $building, $validated['incident_type'], $request->user(), $validated['severity'],
            $request->boolean('is_drill'), $validated['description'] ?? null, (int) $validated['place_id'],
        );
        return redirect()->route('emergency.incidents.live', $incident)->with('success', 'تم تشغيل الإنذار وتنبيه الفريق');
    }

    // ==================== الحالة الطارئة ====================

    public function incidentsIndex(Request $request)
    {
        $q = EmergencyIncident::with('place', 'building', 'triggeredBy')->orderByDesc('triggered_at');
        if ($request->filled('status')) $q->where('status', $request->status);
        if ($request->filled('type')) $q->where('incident_type', $request->type);
        if ($request->filled('place')) $q->whereHas('place', fn ($w) => $w->where('code', $request->place));
        if ($request->filled('drill')) $q->where('is_drill', $request->drill === '1');
        $incidents = $q->paginate(20)->withQueryString();
        $places = Place::orderBy('sort')->get();
        return view('modules.emergency.incidents.index', compact('incidents', 'places'));
    }

    public function incidentLive(EmergencyIncident $incident)
    {
        $this->authorize('view', $incident);
        $incident->load(['building.floors', 'building.assemblyPoints', 'eventLogs.user', 'place', 'triggeredBy', 'notifications', 'lockdown']);
        $stats = $this->musteringService->getLiveStats($incident);
        $missingPeople = $this->musteringService->getMissingPeople($incident);
        $needHelp = $this->musteringService->getPeopleNeedingHelp($incident);
        $byAssemblyPoint = $this->musteringService->getStatsByAssemblyPoint($incident);
        $teamCheckIns = $incident->checkIns()->where('person_type', 'team')->with('teamMember.team')->get();
        $teams = EmergencyTeam::active()->with('members')
            ->where(fn ($w) => $w->where('place_id', $incident->place_id)->orWhereNull('place_id'))->get();
        $manualCalls = $incident->notifications()->manual()->get();
        $user = auth()->user();
        // المرحلة ١٠-٢: خطوات الخطة بعدّاداتها، وخطوة كل عضو فريق بلا حساب بجانب اسمه في قائمة النداء
        $planSteps = $incident->planSteps()->get();
        $stepsByTeamKey = [];
        foreach ($planSteps as $s) {
            foreach ($s->role_cards ?? [] as $no) {
                foreach (RoleCards::get((int) $no)['team'] ?? [] as $k) $stepsByTeamKey[$k][$s->id] = $s;
            }
        }
        $memberKeys = EmergencyTeamMember::whereIn('id', $manualCalls->where('recipient_type', 'team_member')->pluck('recipient_id'))->pluck('role_key', 'id');
        return view('modules.emergency.incidents.live', compact(
            'incident', 'stats', 'missingPeople', 'needHelp', 'byAssemblyPoint', 'teamCheckIns', 'teams', 'manualCalls', 'user', 'planSteps', 'stepsByTeamKey', 'memberKeys'
        ));
    }

    public function acknowledge(EmergencyIncident $incident)
    {
        $this->authorize('respond', $incident);
        $this->service->acknowledge($incident, auth()->user());
        return back()->with('success', 'سُجّل استلامك للحالة');
    }

    public function addNote(Request $request, EmergencyIncident $incident)
    {
        $this->authorize('respond', $incident);
        $v = $request->validate(['note' => 'required|string|max:1000', 'severity' => 'nullable|in:info,warning,critical']);
        EmergencyEventLog::log($incident, EmergencyEventLog::TYPE_NOTE, $v['note'], [], $v['severity'] ?? 'info', auth()->id());
        return back()->with('success', 'أُضيفت الملاحظة إلى السجل الزمني');
    }

    /** وصول عضو فريق (بلا حساب) أو أي شخص محصور — يسجّله المركز/المنسق يدوياً. */
    public function checkInManual(Request $request, EmergencyIncident $incident)
    {
        $this->authorize('respond', $incident);
        $v = $request->validate(['check_in_id' => 'required|integer', 'assembly_point_id' => 'nullable|exists:assembly_points,id', 'note' => 'nullable|string|max:300']);
        $checkIn = EvacuationCheckIn::where('incident_id', $incident->id)->findOrFail($v['check_in_id']);
        if ($checkIn->isSafe()) {
            return back()->with('error', 'سُجّل وصوله مسبقاً');
        }
        $point = !empty($v['assembly_point_id']) ? AssemblyPoint::find($v['assembly_point_id']) : null;
        if ($checkIn->person_type === 'team' && $checkIn->teamMember) {
            $this->service->teamMemberArrived($incident, $checkIn->teamMember, auth()->user(), $v['note'] ?? null);
        }
        $this->musteringService->manualCheckIn($checkIn, $point, auth()->user());
        return back()->with('success', 'سُجّل وصول: '.$checkIn->getPersonName());
    }

    /** «تم» على خطوة من خطة الاستجابة — المناوب أو صاحب الدور (المرحلة ١٠-٢). */
    public function stepDone(Request $request, EmergencyIncident $incident, int $step)
    {
        $this->authorize('respond', $incident);
        $v = $request->validate(['note' => 'nullable|string|max:500']);
        $s = EmergencyIncidentStep::where('incident_id', $incident->id)->findOrFail($step);
        if (!$s->isPending()) return back()->with('error', 'الخطوة «'.$s->title.'» '.$s->getStatusLabel().' مسبقاً');
        $s = app(IncidentStepsService::class)->complete($s, auth()->user(), null, null, $v['note'] ?? null);
        return back()->with('success', 'تمت الخطوة '.$s->label.' «'.$s->title.'» — '.$s->deltaLabel());
    }

    public function stepSkip(Request $request, EmergencyIncident $incident, int $step)
    {
        $this->authorize('respond', $incident);
        $v = $request->validate(['note' => 'required|string|max:500']);
        $s = EmergencyIncidentStep::where('incident_id', $incident->id)->findOrFail($step);
        if (!$s->isPending()) return back()->with('error', 'الخطوة «'.$s->title.'» '.$s->getStatusLabel().' مسبقاً');
        app(IncidentStepsService::class)->skip($s, auth()->user(), $v['note']);
        return back()->with('success', 'تُخطّيت الخطوة '.$s->label.' «'.$s->title.'»');
    }

    public function markMissing(Request $request, EmergencyIncident $incident)
    {
        $this->authorize('respond', $incident);
        $v = $request->validate(['check_in_id' => 'required|integer', 'last_known_location' => 'nullable|string|max:200']);
        $checkIn = EvacuationCheckIn::where('incident_id', $incident->id)->findOrFail($v['check_in_id']);
        if (!empty($v['last_known_location'])) $checkIn->update(['last_known_location' => $v['last_known_location']]);
        $this->musteringService->markAsMissing($checkIn, auth()->user());
        return back()->with('success', 'عُلّم مفقوداً: '.$checkIn->getPersonName());
    }

    public function reportMissingPerson(Request $request, EmergencyIncident $incident)
    {
        $this->authorize('respond', $incident);
        $v = $request->validate(['person_name' => 'required|string|max:100', 'person_phone' => 'nullable|string|max:20',
            'last_known_location' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:500']);
        $checkIn = EvacuationCheckIn::create([
            'incident_id' => $incident->id, 'person_type' => 'visitor', 'visitor_name' => $v['person_name'], 'visitor_phone' => $v['person_phone'] ?? null,
            'status' => 'evacuating', 'last_known_location' => $v['last_known_location'] ?? null, 'notes' => $v['notes'] ?? null, 'checked_by_id' => auth()->id(),
        ]);
        $this->musteringService->markAsMissing($checkIn, auth()->user());
        return back()->with('success', 'سُجّل مفقوداً: '.$checkIn->getPersonName());
    }

    public function contain(Request $request, EmergencyIncident $incident)
    {
        $this->authorize('contain', $incident);
        try {
            $this->service->contain($incident, auth()->user(), $request->input('note'));
        } catch (TransitionException $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', 'سُجّلت السيطرة على الحالة');
    }

    public function reactivate(Request $request, EmergencyIncident $incident)
    {
        $this->authorize('reactivate', $incident);
        try {
            $this->service->reactivate($incident, auth()->user(), $request->input('note'));
        } catch (TransitionException $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', 'عادت الحالة نشطة');
    }

    public function endIncident(Request $request, EmergencyIncident $incident)
    {
        $this->authorize('end', $incident);
        $validated = $request->validate(['final_report' => 'nullable|string|max:5000']);
        try {
            $this->service->endIncident($incident, auth()->user(), $validated['final_report'] ?? null);
        } catch (TransitionException $e) {
            return back()->with('error', $e->getMessage());
        }
        return redirect()->route('emergency.incidents.report', $incident)->with('success', 'أُنهيت الحالة وأُعلن الأمان');
    }

    public function cancelIncident(Request $request, EmergencyIncident $incident)
    {
        $this->authorize('cancel', $incident);
        $validated = $request->validate(['reason' => 'required|string|max:500']);
        try {
            $this->service->cancel($incident, auth()->user(), $validated['reason']);
        } catch (TransitionException $e) {
            return back()->with('error', $e->getMessage());
        }
        return redirect()->route('emergency.dashboard')->with('success', 'أُلغيت الحالة');
    }

    /** تقرير الحالة: السجل الزمني كاملاً والإحصاءات والفريق وما نُودي — يُطبع (البوابة: انتهاء ← تقرير). */
    public function incidentReport(EmergencyIncident $incident)
    {
        $this->authorize('view', $incident);
        $incident->load(['building', 'place', 'triggeredBy', 'containedBy', 'endedBy', 'cancelledBy', 'eventLogs.user', 'notifications', 'checkIns.user', 'checkIns.teamMember', 'checkIns.assemblyPoint', 'drill', 'lockdown', 'afterActionReport']);
        $stats = $this->musteringService->getLiveStats($incident);
        $byPoint = $this->musteringService->getStatsByAssemblyPoint($incident);
        $firstArrival = $incident->eventLogs->firstWhere('event_type', EmergencyEventLog::TYPE_TEAM_ARRIVED);
        $firstArrivalSec = $firstArrival ? (int) abs($firstArrival->logged_at->diffInSeconds($incident->triggered_at)) : null;
        return view('modules.emergency.incidents.report', compact('incident', 'stats', 'byPoint', 'firstArrivalSec'));
    }

    // ==================== الإغلاق الأمني ====================

    public function lockdownInitiate(Request $request, EmergencyBuilding $building)
    {
        $this->authorize('trigger', EmergencyIncident::class);
        $v = $request->validate(['level' => 'required|in:soft,modified,full,shelter', 'reason' => 'nullable|string|max:500', 'place_id' => 'nullable|exists:places,id']);
        try {
            $lockdown = app(LockdownService::class)->initiateLockdown($building, auth()->user(), $v['level'], ['reason' => $v['reason'] ?? null, 'place_id' => $v['place_id'] ?? null]);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        return redirect()->route('emergency.incidents.live', $lockdown->incident)->with('success', 'بدأ الإغلاق الأمني — '.$lockdown->getLevelLabel());
    }

    public function lockdownLift(Request $request, Lockdown $lockdown)
    {
        $this->authorize('trigger', EmergencyIncident::class);
        try {
            app(LockdownService::class)->liftLockdown($lockdown, auth()->user(), (string) $request->input('reason', ''));
        } catch (\RuntimeException|TransitionException $e) {
            return back()->with('error', $e->getMessage());
        }
        return redirect()->route('emergency.buildings.control', $lockdown->building)->with('success', 'رُفع الإغلاق الأمني');
    }

    // ==================== التمارين ====================

    public function drillsIndex()
    {
        $drills = EvacuationDrill::with(['building', 'place', 'conductedBy', 'incident'])->orderByDesc('scheduled_at')->paginate(20);
        $stats = app(DrillService::class)->getDrillStats();
        return view('modules.emergency.drills.index', compact('drills', 'stats'));
    }

    public function drillsCreate()
    {
        $buildings = EmergencyBuilding::active()->orderBy('name')->get(['id', 'name']);
        $places = Place::orderBy('sort')->get();
        return view('modules.emergency.drills.create', compact('buildings', 'places'));
    }

    public function drillsStore(Request $request)
    {
        $validated = $request->validate([
            'building_id' => 'required|exists:emergency_buildings,id',
            'place_id' => 'nullable|exists:places,id',
            'drill_type' => 'required|in:fire,evacuation,earthquake,chemical,full_scale,tabletop,announced,unannounced',
            'scheduled_at' => 'required|date',
            'scenario' => 'nullable|string', 'objectives' => 'nullable|string',
            'expected_participants' => 'nullable|integer|min:1', 'target_time_sec' => 'nullable|integer|min:1',
        ]);
        EvacuationDrill::create($validated);
        return redirect()->route('emergency.drills.index')->with('success', 'تمت جدولة التمرين');
    }

    public function drillsStart(EvacuationDrill $drill)
    {
        $this->authorize('trigger', EmergencyIncident::class);
        if ($drill->building->getActiveIncident()) {
            return back()->with('error', 'توجد حالة مفتوحة في المبنى؛ لا يبدأ تمرين أثناءها');
        }
        try {
            $incident = app(DrillService::class)->startDrill($drill, auth()->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        return redirect()->route('emergency.incidents.live', $incident)->with('success', 'بدأ التمرين — نُبّه الفريق');
    }

    public function drillsEnd(Request $request, EvacuationDrill $drill)
    {
        $v = $request->validate(['observations' => 'nullable|string|max:3000', 'improvements' => 'nullable|string|max:3000']);
        if ($drill->incident) $this->authorize('end', $drill->incident);
        try {
            $drill = app(DrillService::class)->endDrill($drill, auth()->user(), $v['observations'] ?? null, $v['improvements'] ?? null);
        } catch (TransitionException $e) {
            return back()->with('error', $e->getMessage());
        }
        return redirect()->route('emergency.drills.index')->with('success', 'انتهى التمرين — النتيجة: '.($drill->getResultLabel() ?? '—').' ('.$drill->score.'/100)');
    }

    public function drillsCancel(Request $request, EvacuationDrill $drill)
    {
        try {
            app(DrillService::class)->cancelDrill($drill, $request->input('reason'));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', 'أُلغي التمرين');
    }

    // ==================== المعدات ====================

    public function equipmentIndex(Request $request)
    {
        $q = EmergencyEquipment::with(['building', 'floor', 'place'])->orderBy('next_inspection_date');
        if ($request->filled('place')) $q->whereHas('place', fn ($w) => $w->where('code', $request->place));
        if ($request->filled('status')) $q->where('status', $request->status);
        $equipment = $q->paginate(20)->withQueryString();
        $places = Place::orderBy('sort')->get();
        return view('modules.emergency.equipment.index', compact('equipment', 'places'));
    }

    public function equipmentCreate()
    {
        $buildings = EmergencyBuilding::with('floors')->orderBy('name')->get();
        $places = Place::orderBy('sort')->get();
        return view('modules.emergency.equipment.create', compact('buildings', 'places'));
    }

    public function equipmentStore(Request $request)
    {
        $validated = $request->validate([
            'building_id' => 'required|exists:emergency_buildings,id',
            'floor_id' => 'nullable|exists:building_floors,id',
            'place_id' => 'nullable|exists:places,id',
            'equipment_type' => 'required|in:fire_extinguisher,fire_hose,smoke_detector,heat_detector,alarm_bell,exit_sign,emergency_light,first_aid_kit,aed,fire_blanket,spill_kit,eyewash,other',
            'code' => 'nullable|string|max:50', 'brand' => 'nullable|string|max:100', 'model' => 'nullable|string|max:100',
            'serial_number' => 'nullable|string|max:100', 'location_description' => 'nullable|string|max:255',
            'install_date' => 'nullable|date', 'expiry_date' => 'nullable|date', 'last_inspection_date' => 'nullable|date',
            'inspection_frequency' => 'required|in:monthly,quarterly,semi_annual,annual', 'notes' => 'nullable|string',
        ]);
        $base = !empty($validated['last_inspection_date']) ? \Carbon\Carbon::parse($validated['last_inspection_date']) : now();
        $validated['next_inspection_date'] = $base->copy()->addDays(match ($validated['inspection_frequency']) {
            'monthly' => 30, 'quarterly' => 90, 'semi_annual' => 180, 'annual' => 365,
        });
        EmergencyEquipment::create($validated);
        return redirect()->route('emergency.equipment.index')->with('success', 'أُضيفت المعدة');
    }

    public function equipmentInspect(Request $request, EmergencyEquipment $equipment)
    {
        $v = $request->validate(['result' => 'required|in:pass,fail,needs_attention', 'issues_found' => 'nullable|string|max:1000',
            'corrective_action' => 'nullable|string|max:1000', 'notes' => 'nullable|string|max:1000']);
        $next = now()->addDays(match ($equipment->inspection_frequency) { 'monthly' => 30, 'quarterly' => 90, 'semi_annual' => 180, 'annual' => 365, default => 30 });
        EmergencyEquipmentInspection::create($v + ['equipment_id' => $equipment->id, 'inspected_at' => now(), 'inspected_by_id' => auth()->id(), 'next_inspection_date' => $next, 'created_at' => now()]);
        $equipment->update([
            'last_inspection_date' => now(), 'next_inspection_date' => $next,
            'status' => $v['result'] === 'pass' ? 'operational' : ($v['result'] === 'fail' ? 'out_of_service' : 'needs_service'),
        ]);
        return back()->with('success', 'سُجّل الفحص');
    }

    // ==================== الفرق ====================

    public function teamsIndex(Request $request)
    {
        // الفرق المشتقة من ملف المكان تُحدَّث عند كل فتح (المصدر اللوحة)
        app(TeamSync::class)->sync();
        $q = EmergencyTeam::with(['building', 'place', 'members'])->orderByDesc('source')->orderBy('place_id')->orderBy('name');
        if ($request->filled('place')) $q->whereHas('place', fn ($w) => $w->where('code', $request->place));
        $teams = $q->paginate(30)->withQueryString();
        $places = Place::orderBy('sort')->get();
        return view('modules.emergency.teams.index', compact('teams', 'places'));
    }

    public function teamsCreate()
    {
        $buildings = EmergencyBuilding::active()->orderBy('name')->get(['id', 'name']);
        $places = Place::orderBy('sort')->get();
        return view('modules.emergency.teams.create', compact('buildings', 'places'));
    }

    public function teamsStore(Request $request)
    {
        $validated = $request->validate([
            'building_id' => 'required|exists:emergency_buildings,id', 'place_id' => 'nullable|exists:places,id',
            'name' => 'required|string|max:100',
            'team_type' => 'required|in:command,fire_warden,first_aid,evacuation,search_rescue,communication,security',
            'description' => 'nullable|string|max:500', 'shift' => 'required|in:morning,evening,night,all', 'is_active' => 'nullable|boolean',
        ]);
        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['source'] = 'manual';
        $team = EmergencyTeam::create($validated);
        return redirect()->route('emergency.teams.show', $team)->with('success', 'أُنشئ الفريق');
    }

    public function teamsShow(EmergencyTeam $team)
    {
        $team->load(['building', 'place', 'members.user', 'organizationUnit']);
        $availableUsers = User::whereHas('profile', fn ($q) => $q->where('is_active', true))
            ->whereNotIn('id', $team->members->pluck('user_id')->filter())->orderBy('name')->get(['id', 'name', 'email']);
        return view('modules.emergency.teams.show', compact('team', 'availableUsers'));
    }

    public function teamsEdit(EmergencyTeam $team)
    {
        if ($team->isDerived()) {
            return redirect()->route('emergency.teams.show', $team)->with('error', 'الفريق الأولي يُحرَّر من ملف المكان في اللوحة');
        }
        $buildings = EmergencyBuilding::active()->orderBy('name')->get(['id', 'name']);
        $places = Place::orderBy('sort')->get();
        return view('modules.emergency.teams.edit', compact('team', 'buildings', 'places'));
    }

    public function teamsUpdate(Request $request, EmergencyTeam $team)
    {
        if ($team->isDerived()) {
            return back()->with('error', 'الفريق الأولي يُحرَّر من ملف المكان في اللوحة');
        }
        $validated = $request->validate([
            'building_id' => 'required|exists:emergency_buildings,id', 'place_id' => 'nullable|exists:places,id',
            'name' => 'required|string|max:100',
            'team_type' => 'required|in:command,fire_warden,first_aid,evacuation,search_rescue,communication,security',
            'description' => 'nullable|string|max:500', 'shift' => 'required|in:morning,evening,night,all', 'is_active' => 'nullable|boolean',
        ]);
        $validated['is_active'] = $request->boolean('is_active', true);
        $team->update($validated);
        return redirect()->route('emergency.teams.show', $team)->with('success', 'حُدّث الفريق');
    }

    public function teamsDestroy(EmergencyTeam $team)
    {
        if ($team->isDerived()) {
            return back()->with('error', 'الفريق الأولي لا يُحذف من هنا');
        }
        $team->members()->delete();
        $team->delete();
        return redirect()->route('emergency.teams.index')->with('success', 'حُذف الفريق');
    }

    public function teamMembersStore(Request $request, EmergencyTeam $team)
    {
        if ($team->isDerived()) {
            return back()->with('error', 'أعضاء الفريق الأولي من ملف المكان في اللوحة');
        }
        $validated = $request->validate([
            'user_id' => 'nullable|exists:users,id', 'name' => 'required_without:user_id|nullable|string|max:120',
            'role' => 'required|in:leader,deputy,member', 'phone' => 'nullable|string|max:20', 'specialization' => 'nullable|string|max:100',
        ]);
        if (!empty($validated['user_id']) && $team->members()->where('user_id', $validated['user_id'])->exists()) {
            return back()->with('error', 'العضو موجود في الفريق');
        }
        $validated['team_id'] = $team->id;
        $validated['is_available'] = true;
        EmergencyTeamMember::create($validated);
        return redirect()->route('emergency.teams.show', $team)->with('success', 'أُضيف العضو');
    }

    public function teamMembersDestroy(EmergencyTeam $team, EmergencyTeamMember $member)
    {
        abort_unless($member->team_id === $team->id, 404);
        if ($team->isDerived()) {
            return back()->with('error', 'أعضاء الفريق الأولي من ملف المكان في اللوحة');
        }
        $member->delete();
        return redirect()->route('emergency.teams.show', $team)->with('success', 'أُزيل العضو');
    }

    public function teamsSync()
    {
        $n = app(TeamSync::class)->sync();
        return back()->with('success', 'زُومن الفريق الأولي من ملف المكان: '.$n.' فريق');
    }

    // ==================== خطط الاستجابة (المرحلة ١٠-١: مشتقة من الوثائق الثماني، اطلاع فقط) ====================

    public function plansIndex()
    {
        // الوثيقة هي الحقيقة: تُعاد قراءتها عند كل فتح إن تغيّرت بصمتها (رخيصة: sha1 لثمانية ملفات)
        $summary = app(ResponsePlanSync::class)->sync();
        $plans = ResponsePlan::with(['place', 'steps'])->get()->keyBy(fn ($p) => $p->place->code);
        $places = Place::where('code', '!=', 'HZ-00')->orderBy('sort')->get();
        $cards = RoleCards::byCategory();
        return view('modules.emergency.plans.index', compact('summary', 'plans', 'places', 'cards'));
    }

    public function plansShow(string $place)
    {
        app(ResponsePlanSync::class)->sync();
        $plan = ResponsePlan::whereHas('place', fn ($q) => $q->where('code', $place))->with(['place', 'steps'])->firstOrFail();
        $byPath = $plan->stepsByPath();
        return view('modules.emergency.plans.show', compact('plan', 'byPath'));
    }

    public function plansSync()
    {
        $r = app(ResponsePlanSync::class)->sync(null, true);
        $n = count(array_filter($r, fn ($x) => $x['status'] === 'synced'));
        $missing = array_keys(array_filter($r, fn ($x) => $x['status'] === 'missing'));
        $msg = 'زُومنت خطط الاستجابة من الوثائق: '.$n.' خطة';
        if ($missing) $msg .= ' — وثائق مفقودة: '.implode('، ', $missing);
        return back()->with($missing ? 'warning' : 'success', $msg);
    }

    // ==================== جهات الاتصال ====================

    public function contactsIndex()
    {
        $contacts = EmergencyContact::with('building')->orderBy('contact_type')->orderBy('priority')->paginate(30);
        return view('modules.emergency.contacts.index', compact('contacts'));
    }

    public function contactsCreate()
    {
        $buildings = EmergencyBuilding::orderBy('name')->get(['id', 'name']);
        return view('modules.emergency.contacts.create', compact('buildings'));
    }

    public function contactsStore(Request $request)
    {
        $validated = $this->validateContact($request);
        $validated['auto_notify'] = $request->boolean('auto_notify');
        $validated['is_active'] = true;
        EmergencyContact::create($validated);
        return redirect()->route('emergency.contacts.index')->with('success', 'أُضيفت جهة الاتصال');
    }

    public function contactsEdit(EmergencyContact $contact)
    {
        $buildings = EmergencyBuilding::orderBy('name')->get(['id', 'name']);
        return view('modules.emergency.contacts.edit', compact('contact', 'buildings'));
    }

    public function contactsUpdate(Request $request, EmergencyContact $contact)
    {
        $validated = $this->validateContact($request);
        $validated['auto_notify'] = $request->boolean('auto_notify');
        $validated['is_active'] = $request->boolean('is_active', true);
        $contact->update($validated);
        return redirect()->route('emergency.contacts.index')->with('success', 'حُدّثت جهة الاتصال');
    }

    public function contactsDestroy(EmergencyContact $contact)
    {
        $contact->delete();
        return redirect()->route('emergency.contacts.index')->with('success', 'حُذفت جهة الاتصال');
    }

    private function validateContact(Request $request): array
    {
        return $request->validate([
            'building_id' => 'nullable|exists:emergency_buildings,id', 'name' => 'required|string|max:200', 'role' => 'nullable|string|max:100',
            'organization' => 'nullable|string|max:200', 'phone' => 'required|string|max:20', 'phone_alt' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255', 'contact_type' => 'required|in:internal,external', 'priority' => 'required|integer|min:1|max:100',
            'auto_notify' => 'nullable|boolean', 'is_active' => 'nullable|boolean', 'notes' => 'nullable|string|max:500',
        ]);
    }

    // ==================== المهل والتصعيد ====================

    public function settingsEdit()
    {
        $rules = app(AutoEscalationService::class)->getEscalationRules();
        return view('modules.emergency.settings', compact('rules'));
    }

    public function settingsUpdate(Request $request)
    {
        $data = $request->validate(['minutes' => 'array', 'minutes.*' => 'nullable|numeric|min:0|max:100000']);
        foreach (AutoEscalationService::SETTING_KEYS as $key => $label) {
            Setting::set($key, $data['minutes'][$key] ?? null, auth()->id());
        }
        return back()->with('success', 'حُفظت المهل');
    }

    // ==================== التحليلات ====================

    public function analytics(Request $request)
    {
        [$from, $to] = $this->period($request);
        $analyticsService = app(EmergencyAnalyticsService::class);
        $metrics = $analyticsService->getDashboardMetrics($from, $to);
        $summary = $analyticsService->getExecutiveSummary($from, $to, $metrics);
        return view('modules.emergency.analytics', compact('metrics', 'summary', 'from', 'to'));
    }

    public function analyticsExport(Request $request)
    {
        [$from, $to] = $this->period($request);
        $analyticsService = app(EmergencyAnalyticsService::class);
        $metrics = $analyticsService->getDashboardMetrics($from, $to);
        return response()->json(['metrics' => $metrics, 'summary' => $analyticsService->getExecutiveSummary($from, $to, $metrics), 'generated_at' => now()->format('Y-m-d H:i:s')])
            ->header('Content-Disposition', 'attachment; filename="emergency-analytics-'.now()->format('Y-m-d').'.json"');
    }

    private function period(Request $request): array
    {
        $from = $request->filled('from') ? \Carbon\Carbon::parse($request->input('from')) : now()->subMonths(12);
        $to = $request->filled('to') ? \Carbon\Carbon::parse($request->input('to'))->endOfDay() : now();
        return [$from, $to];
    }

    // ==================== تنبيهات الذعر ====================

    public function panicDashboard()
    {
        $panicService = app(PanicAlertService::class);
        $activeAlerts = PanicAlert::active()->with(['user', 'building', 'place', 'responders.user'])->orderByDesc('created_at')->get();
        $recentAlerts = PanicAlert::whereNotIn('status', ['triggered', 'acknowledged', 'responding'])->with(['user', 'building', 'place', 'acknowledgedBy', 'resolvedBy'])->orderByDesc('created_at')->limit(20)->get();
        $stats = $panicService->getStats('month');
        $buildings = EmergencyBuilding::active()->orderBy('name')->get(['id', 'name']);
        $places = Place::orderBy('sort')->get();
        return view('modules.emergency.panic.dashboard', compact('activeAlerts', 'recentAlerts', 'stats', 'buildings', 'places'));
    }

    public function panicDetails(PanicAlert $alert)
    {
        $alert->load(['user', 'building', 'place', 'incident', 'acknowledgedBy', 'resolvedBy', 'responders.user']);
        return view('modules.emergency.panic.details', compact('alert'));
    }

    // ==================== الزوار ====================

    public function visitorsDashboard()
    {
        $visitorService = app(VisitorService::class);
        $buildings = EmergencyBuilding::with(['visitors' => fn ($q) => $q->currentlyIn()])->orderBy('name')->get();
        $stats = $visitorService->getStats('today');
        $recentVisitors = \App\Modules\Emergency\Models\EmergencyVisitor::with(['building', 'host', 'place'])->orderByDesc('checked_in_at')->limit(20)->get();
        return view('modules.emergency.visitors.dashboard', compact('buildings', 'stats', 'recentVisitors'));
    }

    public function visitorsKiosk()
    {
        $buildings = EmergencyBuilding::orderBy('name')->get(['id', 'name', 'code']);
        $places = Place::orderBy('sort')->get();
        return view('modules.emergency.visitors.kiosk', compact('buildings', 'places'));
    }

    public function visitorsBuilding(EmergencyBuilding $building)
    {
        $visitorService = app(VisitorService::class);
        $currentVisitors = $visitorService->getVisitorsInBuilding($building->id);
        $todayVisitors = $visitorService->getTodayVisitors($building->id);
        return view('modules.emergency.visitors.building', compact('building', 'currentVisitors', 'todayVisitors'));
    }

    // ==================== الملفات الطبية ====================

    public function medicalDashboard()
    {
        $medicalService = app(MedicalProfileService::class);
        $stats = $medicalService->getStats();
        $needsAssistance = $medicalService->getUsersNeedingAssistance();
        $needsReview = $medicalService->getProfilesNeedingReview()->take(10);
        return view('modules.emergency.medical.dashboard', compact('stats', 'needsAssistance', 'needsReview'));
    }

    public function myMedicalProfile()
    {
        $profile = app(MedicalProfileService::class)->getOrCreate(auth()->id());
        return view('modules.emergency.medical.my-profile', compact('profile'));
    }
}
