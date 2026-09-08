<?php

namespace App\Modules\Integration\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Services\LockdownService;
use App\Modules\Governance\Models\Setting;
use App\Modules\Integration\Models\IotDevice;
use App\Modules\Integration\Services\AccessControlService;
use App\Modules\Integration\Services\DigitalSignageService;
use App\Modules\Integration\Services\ElevatorService;
use App\Modules\Integration\Services\FirePanelService;
use App\Modules\Integration\Services\HvacService;
use App\Modules\Integration\Services\Protocols\BACnetProtocol;
use App\Modules\Integration\Services\Protocols\ModbusTcpProtocol;
use App\Modules\Integration\Services\Protocols\MqttProtocol;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * الواجهة البرمجية للأنظمة الخمس (EmergencyIoTController في OHSMS بلا tenant). الخدمات تُحمَّل بجهاز المبنى المفعّل.
 * مسارات البروتوكولات (اختبار/قراءة/نشر) لا تفتح سوكتاً إلا لعنوان مسموح (الإصلاح المقرر ٥-٤): عناوين الأجهزة المسجّلة
 * + الإعداد `iot.allowed_hosts`.
 */
class IoTController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected FirePanelService $firePanel, protected AccessControlService $accessControl, protected ElevatorService $elevator,
        protected HvacService $hvac, protected DigitalSignageService $signage, protected LockdownService $lockdown,
    ) {}

    public function getSystemStatus(EmergencyBuilding $building): JsonResponse
    {
        $b = $building->id;
        $this->firePanel->forBuilding($b); $this->accessControl->forBuilding($b); $this->elevator->forBuilding($b); $this->hvac->forBuilding($b); $this->signage->forBuilding($b);
        return response()->json([
            'building_id' => $b,
            'systems' => [
                'fire_panel' => ['enabled' => $this->firePanel->isEnabled(), 'device' => $this->firePanel->device()?->only(['id', 'name', 'protocol']), 'status' => $this->firePanel->isEnabled() ? $this->firePanel->getPanelStatus($b) : null],
                'access_control' => ['enabled' => $this->accessControl->isEnabled(), 'device' => $this->accessControl->device()?->only(['id', 'name', 'protocol']), 'status' => $this->accessControl->isEnabled() ? $this->accessControl->getDoorStatuses($b) : null],
                'elevator' => ['enabled' => $this->elevator->isEnabled(), 'device' => $this->elevator->device()?->only(['id', 'name', 'protocol']), 'status' => $this->elevator->isEnabled() ? $this->elevator->getElevatorStatuses($b) : null],
                'hvac' => ['enabled' => $this->hvac->isEnabled(), 'device' => $this->hvac->device()?->only(['id', 'name', 'protocol']), 'status' => $this->hvac->isEnabled() ? $this->hvac->getSystemStatus($b) : null],
                'signage' => ['enabled' => $this->signage->isEnabled(), 'device' => $this->signage->device()?->only(['id', 'name', 'protocol']), 'displays' => $this->signage->isEnabled() ? $this->signage->getDisplays($b) : null],
            ],
            'lockdown' => $this->lockdown->getLockdownStatus($building),
            'active_incident' => $building->getActiveIncident()?->only(['id', 'incident_code', 'incident_type', 'status']),
            'timestamp' => now()->toISOString(),
        ]);
    }

    // ── لوحة الحريق ──
    public function getFirePanelStatus(EmergencyBuilding $building): JsonResponse { return response()->json($this->firePanel->forBuilding($building->id)->getPanelStatus($building->id)); }
    public function getFireZones(EmergencyBuilding $building): JsonResponse { return response()->json($this->firePanel->forBuilding($building->id)->getZoneStatuses($building->id)); }
    public function acknowledgeAlarm(Request $request, EmergencyBuilding $building): JsonResponse
    {
        $request->validate(['alarm_id' => 'required|string']);
        return response()->json(['success' => $this->firePanel->forBuilding($building->id)->acknowledgeAlarm($building->id, $request->alarm_id, auth()->id())]);
    }
    public function silenceAlarm(EmergencyBuilding $building): JsonResponse { return response()->json(['success' => $this->firePanel->forBuilding($building->id)->silenceAlarm($building->id, auth()->id())]); }
    public function resetFirePanel(EmergencyBuilding $building): JsonResponse { return response()->json(['success' => $this->firePanel->forBuilding($building->id)->resetPanel($building->id, auth()->id())]); }

    // ── الأبواب ──
    public function getOccupancy(EmergencyBuilding $building): JsonResponse { return response()->json($this->accessControl->forBuilding($building->id)->getCurrentOccupancy($building->id)); }
    public function getPersonnelInBuilding(EmergencyBuilding $building): JsonResponse { return response()->json(['personnel' => $this->accessControl->forBuilding($building->id)->getPersonnelInBuilding($building->id)]); }
    public function getDoorStatuses(EmergencyBuilding $building): JsonResponse { return response()->json(['doors' => $this->accessControl->forBuilding($building->id)->getDoorStatuses($building->id)]); }
    public function unlockDoor(Request $request, EmergencyBuilding $building): JsonResponse
    {
        $request->validate(['door_id' => 'required|string', 'duration' => 'nullable|integer|min:5|max:300']);
        return response()->json(['success' => $this->accessControl->forBuilding($building->id)->unlockDoor($building->id, $request->door_id, auth()->id(), (int) ($request->duration ?? 30))]);
    }
    public function lockDoor(Request $request, EmergencyBuilding $building): JsonResponse
    {
        $request->validate(['door_id' => 'required|string']);
        return response()->json(['success' => $this->accessControl->forBuilding($building->id)->lockDoor($building->id, $request->door_id, auth()->id())]);
    }
    public function emergencyUnlock(EmergencyBuilding $building): JsonResponse
    {
        $r = $this->accessControl->forBuilding($building->id)->emergencyUnlockAll($building->id, auth()->id());
        $this->logToOpenIncident($building, 'فتح طوارئ لكل الأبواب', $r);
        return response()->json($r);
    }
    public function getMusteringData(EmergencyBuilding $building): JsonResponse { return response()->json($this->accessControl->forBuilding($building->id)->getMusteringData($building->id)); }

    // ── المصاعد ──
    public function getElevatorStatuses(EmergencyBuilding $building): JsonResponse { return response()->json($this->elevator->forBuilding($building->id)->getElevatorStatuses($building->id)); }
    public function activateFireRecall(Request $request, EmergencyBuilding $building): JsonResponse
    {
        $r = $this->elevator->forBuilding($building->id)->activateFireRecall($building->id, auth()->id(), $request->integer('recall_floor') ?: null);
        $this->logToOpenIncident($building, 'استدعاء المصاعد (Fire Recall)', $r);
        return response()->json($r);
    }
    public function activateFirefighterMode(Request $request, EmergencyBuilding $building): JsonResponse
    {
        $request->validate(['elevator_id' => 'required|string']);
        return response()->json($this->elevator->forBuilding($building->id)->activateFirefighterMode($building->id, $request->elevator_id, auth()->id()));
    }
    public function returnElevatorsToNormal(EmergencyBuilding $building): JsonResponse { return response()->json($this->elevator->forBuilding($building->id)->returnToNormal($building->id, auth()->id())); }

    // ── التكييف ──
    public function getHvacStatus(EmergencyBuilding $building): JsonResponse { return response()->json($this->hvac->forBuilding($building->id)->getSystemStatus($building->id)); }
    public function activateSmokeControl(Request $request, EmergencyBuilding $building): JsonResponse
    {
        $r = $this->hvac->forBuilding($building->id)->activateSmokeControl($building->id, auth()->id(), $request->all());
        $this->logToOpenIncident($building, 'تفعيل التحكم بالدخان', $r);
        return response()->json($r);
    }
    public function emergencyHvacShutdown(EmergencyBuilding $building): JsonResponse
    {
        $r = $this->hvac->forBuilding($building->id)->emergencyShutdown($building->id, auth()->id());
        $this->logToOpenIncident($building, 'إيقاف التكييف', $r);
        return response()->json($r);
    }
    public function activatePurgeMode(Request $request, EmergencyBuilding $building): JsonResponse { return response()->json($this->hvac->forBuilding($building->id)->activatePurgeMode($building->id, auth()->id(), $request->all())); }
    public function returnHvacToNormal(EmergencyBuilding $building): JsonResponse { return response()->json($this->hvac->forBuilding($building->id)->returnToNormal($building->id, auth()->id())); }

    // ── الشاشات ──
    public function getDisplays(EmergencyBuilding $building): JsonResponse { return response()->json(['displays' => $this->signage->forBuilding($building->id)->getDisplays($building->id)]); }
    public function pushEmergencyAlert(Request $request, EmergencyBuilding $building): JsonResponse
    {
        $request->validate(['type' => 'required|string', 'message_en' => 'nullable|string', 'message_ar' => 'nullable|string']);
        $r = $this->signage->forBuilding($building->id)->pushEmergencyAlert($building->id, $request->all(), auth()->id());
        $this->logToOpenIncident($building, 'تنبيه على الشاشات', $r);
        return response()->json($r);
    }
    public function showEvacuationRoutes(Request $request, EmergencyBuilding $building): JsonResponse { return response()->json($this->signage->forBuilding($building->id)->showEvacuationRoutes($building->id, $request->all())); }
    public function showAllClear(EmergencyBuilding $building): JsonResponse { return response()->json($this->signage->forBuilding($building->id)->showAllClear($building->id, auth()->id())); }
    public function returnSignageToNormal(EmergencyBuilding $building): JsonResponse { return response()->json($this->signage->forBuilding($building->id)->returnToNormal($building->id, auth()->id())); }

    // ── الإغلاق الأمني (JSON — نفس خدمة المرحلة ٤) ──
    public function initiateLockdown(Request $request, EmergencyBuilding $building): JsonResponse
    {
        $this->authorize('trigger', EmergencyIncident::class);
        $v = $request->validate(['level' => 'required|in:soft,modified,full,shelter', 'reason' => 'nullable|string|max:500', 'place_id' => 'nullable|exists:places,id']);
        try {
            $l = $this->lockdown->initiateLockdown($building, auth()->user(), $v['level'], ['reason' => $v['reason'] ?? null, 'place_id' => $v['place_id'] ?? null]);
            return response()->json(['success' => true, 'lockdown_id' => $l->id, 'incident_id' => $l->incident_id, 'level' => $l->level, 'results' => $l->results]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }
    }
    public function liftLockdown(Request $request, EmergencyBuilding $building): JsonResponse
    {
        $this->authorize('trigger', EmergencyIncident::class);
        $l = $building->activeLockdown();
        if (!$l) return response()->json(['success' => false, 'error' => 'لا إغلاق ساري'], 422);
        try {
            $this->lockdown->liftLockdown($l, auth()->user(), (string) $request->input('reason', ''));
            return response()->json(['success' => true, 'state' => 'lifted']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }
    }
    public function getLockdownStatus(EmergencyBuilding $building): JsonResponse { return response()->json($this->lockdown->getLockdownStatus($building)); }

    // ── البروتوكولات مباشرة (اختبار/قراءة) — عناوين مسموحة فقط ──

    public static function allowedHosts(): array
    {
        $hosts = IotDevice::whereNotNull('host')->pluck('host')->map(fn ($h) => strtolower(trim($h)))->all();
        foreach (preg_split('/[\s,]+/', (string) Setting::get('iot.allowed_hosts', '')) as $h) {
            if ($h !== '') $hosts[] = strtolower($h);
        }
        return array_values(array_unique($hosts));
    }

    protected function assertAllowed(string $host): void
    {
        if (!in_array(strtolower(trim($host)), self::allowedHosts(), true)) {
            abort(422, 'العنوان ليس ضمن الأجهزة المسجّلة ولا القائمة المسموحة (iot.allowed_hosts)');
        }
    }

    protected function requireSockets(): ?JsonResponse
    {
        return function_exists('socket_create') ? null : response()->json(['success' => false, 'error' => 'امتداد sockets غير مثبت في PHP'], 500);
    }

    public function testBacnetConnection(Request $request): JsonResponse
    {
        $request->validate(['host' => 'required|string', 'port' => 'nullable|integer', 'device_id' => 'nullable|integer']);
        $this->assertAllowed($request->host);
        if ($r = $this->requireSockets()) return $r;
        try {
            $b = new BACnetProtocol($request->host, (int) ($request->port ?? BACnetProtocol::BACNET_PORT), (int) ($request->device_id ?? 1));
            $ok = $b->connect(); $err = $b->getLastError(); if ($ok) $b->disconnect();
            return response()->json(['success' => $ok, 'error' => $err, 'protocol' => 'BACnet/IP']);
        } catch (\Throwable $e) {
            Log::error('[IoT] BACnet test failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function testModbusConnection(Request $request): JsonResponse
    {
        $request->validate(['host' => 'required|string', 'port' => 'nullable|integer', 'unit_id' => 'nullable|integer']);
        $this->assertAllowed($request->host);
        if ($r = $this->requireSockets()) return $r;
        try {
            $m = new ModbusTcpProtocol($request->host, (int) ($request->port ?? ModbusTcpProtocol::MODBUS_TCP_PORT), (int) ($request->unit_id ?? 1));
            $ok = $m->connect(); $err = $m->getLastError(); if ($ok) $m->disconnect();
            return response()->json(['success' => $ok, 'error' => $err, 'protocol' => 'Modbus TCP']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function testMqttConnection(Request $request): JsonResponse
    {
        $request->validate(['host' => 'required|string', 'port' => 'nullable|integer', 'username' => 'nullable|string', 'password' => 'nullable|string']);
        $this->assertAllowed($request->host);
        if ($r = $this->requireSockets()) return $r;
        try {
            $q = new MqttProtocol($request->host, (int) ($request->port ?? 1883), 'ipa-test-'.uniqid(), $request->username, $request->password);
            $ok = $q->connect(); $err = $q->getLastError(); $ping = null;
            if ($ok) { $ping = $q->ping(); $q->disconnect(); }
            return response()->json(['success' => $ok, 'error' => $err, 'protocol' => 'MQTT 3.1.1', 'ping' => $ping]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function readBacnetProperty(Request $request): JsonResponse
    {
        $request->validate(['host' => 'required|string', 'port' => 'nullable|integer', 'object_type' => 'required|integer', 'object_instance' => 'required|integer', 'property_id' => 'nullable|integer']);
        $this->assertAllowed($request->host);
        if ($r = $this->requireSockets()) return $r;
        try {
            $b = new BACnetProtocol($request->host, (int) ($request->port ?? BACnetProtocol::BACNET_PORT));
            if (!$b->connect()) return response()->json(['success' => false, 'error' => $b->getLastError()], 500);
            $res = $b->sendCommand('read_property', ['object_type' => (int) $request->object_type, 'object_instance' => (int) $request->object_instance, 'property_id' => (int) ($request->property_id ?? 85)]);
            $b->disconnect();
            return response()->json($res);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function readModbusRegisters(Request $request): JsonResponse
    {
        $request->validate(['host' => 'required|string', 'port' => 'nullable|integer', 'address' => 'required|integer|min:0', 'count' => 'nullable|integer|min:1|max:125', 'register_type' => 'nullable|in:holding,input,coil,discrete']);
        $this->assertAllowed($request->host);
        if ($r = $this->requireSockets()) return $r;
        try {
            $m = new ModbusTcpProtocol($request->host, (int) ($request->port ?? ModbusTcpProtocol::MODBUS_TCP_PORT));
            if (!$m->connect()) return response()->json(['success' => false, 'error' => $m->getLastError()], 500);
            $cmd = match ($request->register_type ?? 'holding') { 'input' => 'read_input_registers', 'coil' => 'read_coils', 'discrete' => 'read_discrete_inputs', default => 'read_holding_registers' };
            $res = $m->sendCommand($cmd, ['address' => (int) $request->address, 'count' => (int) ($request->count ?? 1)]);
            $m->disconnect();
            return response()->json($res);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function publishMqttMessage(Request $request): JsonResponse
    {
        $request->validate(['host' => 'required|string', 'port' => 'nullable|integer', 'topic' => 'required|string', 'message' => 'required|string', 'qos' => 'nullable|integer|in:0,1,2', 'retain' => 'nullable|boolean']);
        $this->assertAllowed($request->host);
        if ($r = $this->requireSockets()) return $r;
        try {
            $q = new MqttProtocol($request->host, (int) ($request->port ?? 1883));
            if (!$q->connect()) return response()->json(['success' => false, 'error' => $q->getLastError()], 500);
            $res = $q->publish($request->topic, $request->message, (int) ($request->qos ?? 0), (bool) ($request->retain ?? false));
            $q->disconnect();
            return response()->json($res);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function discoverBacnetDevices(Request $request): JsonResponse
    {
        $request->validate(['broadcast_address' => 'required|string', 'low_limit' => 'nullable|integer', 'high_limit' => 'nullable|integer']);
        $this->assertAllowed($request->broadcast_address);
        if ($r = $this->requireSockets()) return $r;
        try {
            $b = new BACnetProtocol($request->broadcast_address);
            if (!$b->connect()) return response()->json(['success' => false, 'error' => $b->getLastError()], 500);
            $res = $b->sendCommand('who_is', ['low' => $request->low_limit, 'high' => $request->high_limit]);
            $b->disconnect();
            return response()->json($res);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    protected function logToOpenIncident(EmergencyBuilding $building, string $what, array $result): void
    {
        $open = $building->getActiveIncident();
        if (!$open) return;
        \App\Modules\Emergency\Models\EmergencyEventLog::log($open, \App\Modules\Emergency\Models\EmergencyEventLog::TYPE_NOTE,
            'أمر للأنظمة: '.$what.' — '.(($result['success'] ?? false) ? 'نُفّذ' : 'لم يُنفّذ: '.($result['error'] ?? 'لا جهاز')), $result, 'warning', auth()->id());
    }
}
