<?php

namespace App\Modules\Emergency\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Emergency\Events\IncidentUpdated;
use App\Modules\Emergency\Events\PersonCheckedIn;
use App\Modules\Emergency\Models\AssemblyPoint;
use App\Modules\Emergency\Models\BuildingExit;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Models\EvacuationCheckIn;
use App\Modules\Emergency\Services\EmergencyService;
use App\Modules\Emergency\Services\IncidentCommandService;
use App\Modules\Emergency\Services\LockdownService;
use App\Modules\Emergency\Services\QrMusteringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * الواجهة البرمجية للطوارئ (من OHSMS بلا tenant). رمز QR يُرسل نصاً (qr_content) ويُرسم في المتصفح — لا حزمة simple-qrcode.
 * أُضيفت نقاط: أحداث الحالة (للاستطلاع في شاشة التتبع)، حالة الإغلاق، وقيادة الحادث (IncidentCommandService لم يكن موصولاً في OHSMS).
 */
class EmergencyApiController extends Controller
{
    public function __construct(
        protected EmergencyService $service,
        protected QrMusteringService $musteringService,
    ) {}

    public function activeEmergencies(): JsonResponse
    {
        $incidents = EmergencyIncident::open()->with(['building:id,name,code,address,latitude,longitude', 'place:id,code,name'])->get()
            ->map(fn ($i) => [
                'id' => $i->id, 'code' => $i->incident_code, 'type' => $i->incident_type, 'type_label' => $i->getTypeLabel(),
                'severity' => $i->severity, 'severity_label' => $i->getSeverityLabel(), 'status' => $i->status, 'status_label' => $i->getStatusLabel(),
                'is_drill' => $i->is_drill, 'building' => $i->building, 'place' => $i->place,
                'triggered_at' => $i->triggered_at->toIso8601String(), 'duration_seconds' => $i->getDurationSeconds(),
                'alert_message' => $i->getAlertMessage(),
            ]);
        return response()->json(['success' => true, 'data' => $incidents, 'has_active' => $incidents->isNotEmpty()]);
    }

    public function incidentDetails(EmergencyIncident $incident): JsonResponse
    {
        $stats = $this->musteringService->getLiveStats($incident);
        $byAssemblyPoint = $this->musteringService->getStatsByAssemblyPoint($incident);
        return response()->json(['success' => true, 'data' => [
            'incident' => [
                'id' => $incident->id, 'code' => $incident->incident_code, 'type' => $incident->incident_type, 'type_label' => $incident->getTypeLabel(),
                'severity' => $incident->severity, 'is_drill' => $incident->is_drill, 'status' => $incident->status, 'status_label' => $incident->getStatusLabel(),
                'place' => $incident->place?->only(['id', 'code', 'name']), 'escalation_level' => $incident->escalation_level,
                'acknowledged_at' => $incident->acknowledged_at?->toIso8601String(),
                'triggered_at' => $incident->triggered_at->toIso8601String(), 'duration_seconds' => $incident->getDurationSeconds(),
                'duration_formatted' => $incident->getDurationFormatted(),
            ],
            'building' => $incident->building->only(['id', 'name', 'address', 'latitude', 'longitude']),
            'stats' => $stats,
            'assembly_points' => $byAssemblyPoint->map(fn ($p) => ['id' => $p->assembly_point_id, 'name' => $p->assemblyPoint?->name, 'code' => $p->assemblyPoint?->code, 'count' => $p->count]),
        ]]);
    }

    /** خطوات خطة الاستجابة في الحالة (المرحلة ١٠-٢) — للعدّادات في شاشة التتبع. */
    public function steps(EmergencyIncident $incident): JsonResponse
    {
        return response()->json(['success' => true, 'status' => $incident->status, 'server_now' => now()->toIso8601String(),
            'data' => app(\App\Modules\Emergency\Services\IncidentStepsService::class)->toArray($incident)]);
    }

    /** أحداث الحالة بعد رقم معيّن — للاستطلاع كل ثوانٍ من شاشة التتبع (بديل Echo غير المثبت). */
    public function events(Request $request, EmergencyIncident $incident): JsonResponse
    {
        $after = (int) $request->query('after', 0);
        $events = $incident->eventLogs()->with('user:id,name')->where('id', '>', $after)->get()->map(fn ($e) => [
            'id' => $e->id, 'type' => $e->event_type, 'type_label' => $e->getTypeLabel(), 'severity' => $e->severity,
            'message' => $e->message, 'user' => $e->user?->name, 'at' => $e->logged_at?->format('H:i:s'),
        ]);
        return response()->json(['success' => true, 'status' => $incident->status, 'status_label' => $incident->getStatusLabel(), 'data' => $events]);
    }

    public function myStatus(): JsonResponse
    {
        $checkIn = $this->musteringService->getUserCheckIn(auth()->user());
        if (!$checkIn) {
            return response()->json(['success' => true, 'has_active_incident' => false, 'data' => null]);
        }
        $incident = $checkIn->incident;
        return response()->json(['success' => true, 'has_active_incident' => true, 'data' => [
            'check_in_id' => $checkIn->id, 'status' => $checkIn->status, 'status_label' => $checkIn->getStatusLabel(), 'qr_token' => $checkIn->qr_token,
            'checked_in_at' => $checkIn->checked_in_at?->toIso8601String(),
            'assembly_point' => $checkIn->assemblyPoint?->only(['id', 'name', 'code']),
            'incident' => ['id' => $incident->id, 'type_label' => $incident->getTypeLabel(), 'building_name' => $incident->building->name, 'place' => $incident->place?->name, 'is_drill' => $incident->is_drill],
        ]]);
    }

    public function selfCheckIn(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'assembly_point_id' => 'required|exists:assembly_points,id',
            'latitude' => 'nullable|numeric|between:-90,90', 'longitude' => 'nullable|numeric|between:-180,180',
        ]);
        $checkIn = $this->musteringService->getUserCheckIn(auth()->user());
        if (!$checkIn) {
            return response()->json(['success' => false, 'message' => 'لا توجد حالة طوارئ نشطة'], 404);
        }
        if ($checkIn->status === EvacuationCheckIn::STATUS_SAFE) {
            return response()->json(['success' => false, 'message' => 'تم تسجيل وصولك مسبقاً'], 422);
        }
        $point = AssemblyPoint::findOrFail($validated['assembly_point_id']);
        $checkIn = $this->musteringService->selfCheckIn($checkIn, $point, $validated['latitude'] ?? null, $validated['longitude'] ?? null);
        broadcast(new PersonCheckedIn($checkIn))->toOthers();
        return response()->json(['success' => true, 'message' => 'تم تسجيل وصولك بأمان', 'data' => [
            'status' => $checkIn->status, 'checked_in_at' => $checkIn->checked_in_at->toIso8601String(), 'assembly_point' => $point->name,
        ]]);
    }

    public function verifyQr(Request $request): JsonResponse
    {
        $validated = $request->validate(['qr_token' => 'required|string', 'assembly_point_id' => 'nullable|exists:assembly_points,id']);
        $checkIn = EvacuationCheckIn::where('qr_token', $validated['qr_token'])->first();
        if (!$checkIn) {
            return response()->json(['success' => false, 'message' => 'رمز QR غير صالح'], 404);
        }
        if (!$checkIn->incident->isOpen()) {
            return response()->json(['success' => false, 'message' => 'الحالة المرتبطة بهذا الرمز منتهية'], 422);
        }
        if ($checkIn->status === EvacuationCheckIn::STATUS_SAFE) {
            return response()->json(['success' => false, 'message' => 'تم تسجيل هذا الشخص مسبقاً', 'data' => [
                'person_name' => $checkIn->getPersonName(), 'checked_in_at' => $checkIn->checked_in_at?->toIso8601String(),
            ]], 422);
        }
        $point = !empty($validated['assembly_point_id']) ? AssemblyPoint::find($validated['assembly_point_id']) : null;
        if ($checkIn->person_type === 'team' && $checkIn->teamMember) {
            $this->service->teamMemberArrived($checkIn->incident, $checkIn->teamMember, auth()->user());
        }
        $checkIn = $point ? $this->musteringService->checkInByQr($validated['qr_token'], $point, auth()->user())
            : $this->musteringService->manualCheckIn($checkIn, null, auth()->user());
        broadcast(new PersonCheckedIn($checkIn))->toOthers();
        return response()->json(['success' => true, 'message' => 'تم تسجيل الوصول', 'data' => [
            'person_name' => $checkIn->getPersonName(), 'person_type' => $checkIn->getPersonTypeLabel(), 'status' => $checkIn->status,
            'checked_in_at' => $checkIn->checked_in_at->toIso8601String(),
        ]]);
    }

    public function requestHelp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'help_type' => 'required|in:mobility,medical,injured,trapped,panic,child,elderly,other',
            'location' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:500',
        ]);
        $checkIn = $this->musteringService->getUserCheckIn(auth()->user());
        if (!$checkIn) {
            return response()->json(['success' => false, 'message' => 'لا توجد حالة طوارئ نشطة'], 404);
        }
        $this->musteringService->requestHelp($checkIn, $validated['help_type'], $validated['location'] ?? null, $validated['notes'] ?? null);
        broadcast(new IncidentUpdated($checkIn->incident))->toOthers();
        return response()->json(['success' => true, 'message' => 'تم إرسال طلب المساعدة']);
    }

    public function assemblyPoints(EmergencyBuilding $building): JsonResponse
    {
        $points = $building->assemblyPoints()->orderByDesc('is_primary')->get()->map(fn ($p) => [
            'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'is_primary' => $p->is_primary, 'place' => $p->place?->code,
            'latitude' => $p->latitude, 'longitude' => $p->longitude, 'capacity' => $p->capacity, 'directions' => $p->directions,
        ]);
        return response()->json(['success' => true, 'data' => $points]);
    }

    public function mapData(EmergencyBuilding $building): JsonResponse
    {
        $building->load(['assemblyPoints', 'exits.floor', 'equipment.floor']);
        $geo = fn ($m) => $m->latitude && $m->longitude;
        return response()->json([
            'success' => true,
            'building' => $building->only(['id', 'name', 'address']) + ['latitude' => (float) $building->latitude, 'longitude' => (float) $building->longitude],
            'assembly_points' => $building->assemblyPoints->filter($geo)->map(fn ($ap) => ['id' => $ap->id, 'code' => $ap->code, 'name' => $ap->name, 'latitude' => (float) $ap->latitude, 'longitude' => (float) $ap->longitude, 'capacity' => $ap->capacity, 'is_primary' => $ap->is_primary, 'directions' => $ap->directions])->values(),
            'exits' => $building->exits->filter($geo)->map(fn ($ex) => ['id' => $ex->id, 'code' => $ex->code, 'name' => $ex->name, 'latitude' => (float) $ex->latitude, 'longitude' => (float) $ex->longitude, 'exit_type' => $ex->exit_type, 'floor' => $ex->floor?->getDisplayName(), 'is_accessible' => $ex->is_accessible])->values(),
            'equipment' => $building->equipment->filter($geo)->map(fn ($eq) => ['id' => $eq->id, 'code' => $eq->code, 'equipment_type' => $eq->equipment_type, 'latitude' => (float) $eq->latitude, 'longitude' => (float) $eq->longitude, 'floor' => $eq->floor?->getDisplayName(), 'status' => $eq->status])->values(),
        ]);
    }

    public function lockdownStatus(EmergencyBuilding $building): JsonResponse
    {
        return response()->json(['success' => true, 'data' => app(LockdownService::class)->getLockdownStatus($building)]);
    }

    public function missingPeople(EmergencyIncident $incident): JsonResponse
    {
        $missing = $this->musteringService->getMissingPeople($incident);
        return response()->json(['success' => true, 'data' => $missing->map(fn ($c) => [
            'id' => $c->id, 'name' => $c->getPersonName(), 'phone' => $c->getPersonPhone(), 'type' => $c->getPersonTypeLabel(),
            'status' => $c->status, 'floor' => $c->floor?->getDisplayName(), 'last_known_location' => $c->last_known_location,
        ]), 'count' => $missing->count()]);
    }

    public function needHelp(EmergencyIncident $incident): JsonResponse
    {
        $needHelp = $this->musteringService->getPeopleNeedingHelp($incident);
        return response()->json(['success' => true, 'data' => $needHelp->map(fn ($c) => [
            'id' => $c->id, 'name' => $c->getPersonName(), 'phone' => $c->getPersonPhone(), 'help_type' => $c->assistance_type,
            'help_type_label' => $c->getAssistanceTypeLabel(), 'floor' => $c->floor?->getDisplayName(), 'location' => $c->last_known_location, 'notes' => $c->notes,
        ]), 'count' => $needHelp->count()]);
    }

    public function markFound(Request $request, EvacuationCheckIn $checkIn): JsonResponse
    {
        $validated = $request->validate(['notes' => 'nullable|string|max:500']);
        $this->musteringService->markAsFound($checkIn, auth()->user(), $validated['notes'] ?? null);
        broadcast(new PersonCheckedIn($checkIn))->toOthers();
        return response()->json(['success' => true, 'message' => 'تم تحديث الحالة']);
    }

    public function liveStats(EmergencyIncident $incident): JsonResponse
    {
        return response()->json([
            'success' => true, 'data' => $this->musteringService->getLiveStats($incident), 'status' => $incident->status,
            'status_label' => $incident->getStatusLabel(), 'duration_seconds' => $incident->getDurationSeconds(), 'duration_formatted' => $incident->getDurationFormatted(),
        ]);
    }

    /** رمز الشخص: نص يُرسم في المتصفح (qrcodejs). */
    public function myQr(): JsonResponse
    {
        $user = auth()->user();
        $checkIn = $this->musteringService->getUserCheckIn($user);
        if (!$checkIn) {
            $qrData = json_encode(['type' => 'ipa_user', 'user_id' => $user->id, 'name' => $user->name], JSON_UNESCAPED_UNICODE);
            return response()->json(['success' => true, 'has_active_incident' => false, 'data' => ['qr_content' => $qrData, 'qr_image' => null, 'user_name' => $user->name]]);
        }
        return response()->json(['success' => true, 'has_active_incident' => true, 'data' => [
            'qr_token' => $checkIn->qr_token, 'qr_content' => $checkIn->getQrCodeUrl(), 'qr_image' => null,
            'status' => $checkIn->status, 'status_label' => $checkIn->getStatusLabel(),
            'incident_type' => $checkIn->incident->getTypeLabel(), 'building_name' => $checkIn->incident->building->name,
        ]]);
    }

    public function nearestExit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'building_id' => 'nullable|exists:emergency_buildings,id', 'floor_id' => 'nullable|exists:building_floors,id',
            'latitude' => 'nullable|numeric|between:-90,90', 'longitude' => 'nullable|numeric|between:-180,180',
        ]);
        $building = !empty($validated['building_id']) ? EmergencyBuilding::findOrFail($validated['building_id']) : EmergencyBuilding::main();
        if (!$building) {
            return response()->json(['success' => false, 'message' => 'لا مبنى مسجّل'], 404);
        }
        $query = BuildingExit::where('building_id', $building->id)->where('status', 'available');
        if (!empty($validated['floor_id'])) $query->where('floor_id', $validated['floor_id']);
        $exits = $query->with(['floor', 'assemblyPoint'])->get();
        if ($exits->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'لا توجد مخارج مسجّلة'], 404);
        }
        $nearest = $exits->first();
        if (!empty($validated['latitude']) && !empty($validated['longitude'])) {
            $nearest = $exits->sortBy(fn ($exit) => $exit->assemblyPoint?->latitude
                ? $this->haversineDistance((float) $validated['latitude'], (float) $validated['longitude'], (float) $exit->assemblyPoint->latitude, (float) $exit->assemblyPoint->longitude)
                : PHP_INT_MAX)->first();
        }
        return response()->json(['success' => true, 'data' => [
            'exit' => ['id' => $nearest->id, 'code' => $nearest->code, 'name' => $nearest->name, 'type' => $nearest->exit_type, 'floor' => $nearest->floor?->getDisplayName(), 'direction' => $nearest->direction],
            'assembly_point' => $nearest->assemblyPoint?->only(['id', 'name', 'code', 'latitude', 'longitude', 'directions']),
            'all_exits' => $exits->map(fn ($e) => ['id' => $e->id, 'code' => $e->code, 'name' => $e->name, 'floor' => $e->floor?->getDisplayName()]),
        ]]);
    }

    protected function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function instructions(): JsonResponse
    {
        $checkIn = $this->musteringService->getUserCheckIn(auth()->user());
        if (!$checkIn) {
            return response()->json(['success' => true, 'has_active_incident' => false, 'data' => ['general_instructions' => $this->getGeneralInstructions()]]);
        }
        $incident = $checkIn->incident;
        $primaryPoint = $incident->building->getPrimaryAssemblyPoint();
        return response()->json(['success' => true, 'has_active_incident' => true, 'data' => [
            'incident_type' => $incident->incident_type, 'incident_type_label' => $incident->getTypeLabel(), 'severity' => $incident->severity,
            'is_drill' => $incident->is_drill, 'building_name' => $incident->building->name, 'place' => $incident->place?->name,
            'primary_assembly_point' => $primaryPoint?->only(['name', 'code', 'directions', 'latitude', 'longitude']),
            'evacuation_instructions' => app(\App\Modules\Emergency\Services\EmergencyNotificationService::class)->getEvacuationInstructions($incident),
            'do_list' => $this->getDoList($incident->incident_type), 'dont_list' => $this->getDontList($incident->incident_type),
        ]]);
    }

    protected function getGeneralInstructions(): array
    {
        return ['حافظ على هدوئك', 'اتبع تعليمات فريق الطوارئ', 'استخدم السلالم لا المصاعد', 'توجّه إلى أقرب نقطة تجمع', 'لا تعد إلى المبنى حتى يُعلَن انتهاء الخطر'];
    }

    protected function getDoList(string $incidentType): array
    {
        $common = ['حافظ على هدوئك', 'اتبع تعليمات فريق الطوارئ', 'ساعد الآخرين إن أمكن', 'سجّل وصولك في نقطة التجمع'];
        $specific = match ($incidentType) {
            'fire' => ['استخدم السلالم فقط', 'اختبر الأبواب قبل فتحها', 'ازحف إذا كان هناك دخان كثيف'],
            'earthquake' => ['احتمِ تحت طاولة متينة', 'ابتعد عن النوافذ', 'انتظر توقف الاهتزاز'],
            'chemical_spill' => ['غطِّ أنفك وفمك', 'ابتعد عن مصدر التسرب', 'اغسل جلدك إذا لامست المادة'],
            default => [],
        };
        return array_merge($common, $specific);
    }

    protected function getDontList(string $incidentType): array
    {
        $common = ['لا تستخدم المصاعد', 'لا تعد إلى المبنى', 'لا تتأخر في الإخلاء'];
        $specific = match ($incidentType) {
            'fire' => ['لا تفتح أبواباً ساخنة', 'لا تقفز من النوافذ', 'لا تحاول إطفاء حرائق كبيرة'],
            'earthquake' => ['لا تقف تحت الأبواب', 'لا تركض للخارج أثناء الاهتزاز'],
            'gas_leak' => ['لا تشعل أي نار', 'لا تستخدم الأجهزة الكهربائية', 'لا تستخدم الهاتف داخل المبنى'],
            default => [],
        };
        return array_merge($common, $specific);
    }

    public function reportMissing(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'incident_id' => 'required|exists:emergency_incidents,id', 'person_name' => 'required|string|max:100', 'person_phone' => 'nullable|string|max:20',
            'last_known_location' => 'nullable|string|max:255', 'description' => 'nullable|string|max:500',
            'needs_assistance' => 'nullable|boolean', 'assistance_type' => 'nullable|in:mobility,medical,injured,trapped,panic,child,elderly,other',
        ]);
        $incident = EmergencyIncident::findOrFail($validated['incident_id']);
        if (!$incident->isOpen()) {
            return response()->json(['success' => false, 'message' => 'حالة الطوارئ غير نشطة'], 422);
        }
        $notes = trim(($validated['description'] ?? '')."\nأبلغ عنه: ".auth()->user()->name.' — '.now()->format('Y-m-d H:i'));
        $checkIn = EvacuationCheckIn::create([
            'incident_id' => $incident->id, 'person_type' => 'visitor', 'visitor_name' => $validated['person_name'], 'visitor_phone' => $validated['person_phone'] ?? null,
            'status' => 'evacuating', 'last_known_location' => $validated['last_known_location'] ?? null, 'notes' => $notes,
            'needs_assistance' => (bool) ($validated['needs_assistance'] ?? false), 'assistance_type' => $validated['assistance_type'] ?? null, 'checked_by_id' => auth()->id(),
        ]);
        $this->musteringService->markAsMissing($checkIn, auth()->user());
        broadcast(new IncidentUpdated($incident))->toOthers();
        return response()->json(['success' => true, 'message' => 'تم الإبلاغ عن الشخص المفقود', 'data' => ['check_in_id' => $checkIn->id, 'person_name' => $checkIn->visitor_name, 'status' => 'missing']]);
    }

    // ── قيادة الحادث (ICS) ──

    public function icsEstablish(Request $request, EmergencyIncident $incident): JsonResponse
    {
        $v = $request->validate(['commander_id' => 'nullable|exists:users,id']);
        $ics = app(IncidentCommandService::class)->establishCommand($incident, (int) ($v['commander_id'] ?? auth()->id()));
        return response()->json(['success' => true, 'data' => $ics]);
    }

    public function icsAssign(Request $request, EmergencyIncident $incident): JsonResponse
    {
        $v = $request->validate(['position' => 'required|string|max:50', 'user_id' => 'required|exists:users,id']);
        return response()->json(['success' => true, 'data' => app(IncidentCommandService::class)->assignPosition($incident, $v['position'], (int) $v['user_id'])]);
    }

    public function icsObjectives(Request $request, EmergencyIncident $incident): JsonResponse
    {
        $v = $request->validate(['objectives' => 'required|array', 'objectives.*.objective' => 'required|string|max:300', 'objectives.*.priority' => 'nullable|in:low,medium,high']);
        return response()->json(['success' => true, 'data' => app(IncidentCommandService::class)->setObjectives($incident, $v['objectives'])]);
    }

    public function icsResource(Request $request, EmergencyIncident $incident): JsonResponse
    {
        $v = $request->validate(['type' => 'required|string|max:50', 'name' => 'required|string|max:120', 'quantity' => 'nullable|integer|min:1', 'assigned_to' => 'nullable|string|max:120', 'location' => 'nullable|string|max:120']);
        return response()->json(['success' => true, 'data' => app(IncidentCommandService::class)->addResource($incident, $v)]);
    }

    public function icsTransfer(Request $request, EmergencyIncident $incident): JsonResponse
    {
        $v = $request->validate(['user_id' => 'required|exists:users,id', 'reason' => 'nullable|string|max:300']);
        return response()->json(['success' => true, 'data' => app(IncidentCommandService::class)->transferCommand($incident, (int) $v['user_id'], $v['reason'] ?? '')]);
    }

    public function icsForm(EmergencyIncident $incident, string $form): JsonResponse
    {
        $svc = app(IncidentCommandService::class);
        $data = match ($form) {
            '201' => $svc->generateForm201($incident), '202' => $svc->generateForm202($incident),
            '209' => $svc->generateForm209($incident), default => $svc->generateForm214($incident),
        };
        return response()->json(['success' => true, 'data' => $data]);
    }
}
