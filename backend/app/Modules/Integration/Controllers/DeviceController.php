<?php

namespace App\Modules\Integration\Controllers;

use App\Http\Controllers\Controller;
use App\Core\Services\NotificationService;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyCamera;
use App\Modules\Emergency\Models\EmergencyWearable;
use App\Modules\Emergency\Models\WearableAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** الكاميرات والأساور (DeviceIntegrationController في OHSMS بلا tenant) + شاشاتها التي كانت في EmergencyController. */
class DeviceController extends Controller
{
    // ========== CCTV Cameras ==========

    public function cameras(): JsonResponse
    {
        $cameras = EmergencyCamera::query()
            ->with('building')
            ->orderBy('is_emergency_priority', 'desc')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $cameras->map(fn($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'camera_id' => $c->camera_id,
                'location' => $c->location,
                'type' => $c->type,
                'type_label' => $c->getTypeLabel(),
                'status' => $c->status,
                'is_online' => $c->isOnline(),
                'is_priority' => $c->is_emergency_priority,
                'has_audio' => $c->has_audio,
                'stream_url' => $c->stream_url,
                'snapshot_url' => $c->snapshot_url,
                'building' => $c->building ? ['id' => $c->building_id, 'name' => $c->building->name] : null,
                'floor_number' => $c->floor_number,
            ]),
        ]);
    }

    public function storeCamera(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'building_id' => 'nullable|exists:emergency_buildings,id',
            'name' => 'required|string|max:100',
            'camera_id' => 'nullable|string|max:50',
            'location' => 'nullable|string|max:200',
            'stream_url' => 'nullable|url|max:500',
            'snapshot_url' => 'nullable|url|max:500',
            'type' => 'nullable|in:fixed,ptz,dome,thermal',
            'is_emergency_priority' => 'nullable|boolean',
            'has_audio' => 'nullable|boolean',
            'floor_number' => 'nullable|integer',
        ]);

        $camera = EmergencyCamera::create($validated);

        return response()->json(['success' => true, 'message' => 'تم إضافة الكاميرا', 'data' => ['id' => $camera->id]], 201);
    }

    public function camerasByBuilding(int $buildingId): JsonResponse
    {
        $cameras = EmergencyCamera::query()
            ->where('building_id', $buildingId)
            ->online()
            ->orderBy('is_emergency_priority', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $cameras->map(fn($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'location' => $c->location,
                'stream_url' => $c->stream_url,
                'snapshot_url' => $c->snapshot_url,
                'is_priority' => $c->is_emergency_priority,
            ]),
        ]);
    }

    // ========== Wearables ==========

    public function wearables(): JsonResponse
    {
        $devices = EmergencyWearable::query()
            ->with('user')
            ->active()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $devices->map(fn($w) => [
                'id' => $w->id,
                'device_id' => $w->device_id,
                'device_type' => $w->device_type,
                'device_model' => $w->device_model,
                'user' => ['id' => $w->user_id, 'name' => $w->user?->name],
                'is_stale' => $w->isStale(),
                'last_heartbeat_at' => $w->last_heartbeat_at?->toIso8601String(),
                'battery_level' => $w->battery_level,
                'last_location' => $w->last_latitude ? [
                    'lat' => (float)$w->last_latitude,
                    'lng' => (float)$w->last_longitude,
                ] : null,
                'panic_enabled' => $w->panic_enabled,
                'fall_detection_enabled' => $w->fall_detection_enabled,
            ]),
        ]);
    }

    public function registerWearable(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => 'required|string|max:100|unique:emergency_wearables,device_id',
            'device_type' => 'nullable|string|max:50',
            'device_model' => 'nullable|string|max:100',
            'push_token' => 'nullable|string|max:500',
            'panic_enabled' => 'nullable|boolean',
            'fall_detection_enabled' => 'nullable|boolean',
            'heart_rate_alert_enabled' => 'nullable|boolean',
        ]);

        $validated['user_id'] = auth()->id();

        $wearable = EmergencyWearable::create($validated);

        return response()->json(['success' => true, 'message' => 'تم تسجيل الجهاز', 'data' => ['id' => $wearable->id]], 201);
    }

    public function heartbeat(Request $request, EmergencyWearable $wearable): JsonResponse
    {
        if ($wearable->user_id !== auth()->id() && !auth()->user()->can_('integration.manage')) {
            return response()->json(['success' => false, 'message' => 'غير مصرح'], 403);
        }

        $validated = $request->validate([
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'heart_rate' => 'nullable|integer|min:30|max:250',
            'battery_level' => 'nullable|integer|min:0|max:100',
        ]);

        $wearable->updateHeartbeat([
            'last_latitude' => $validated['latitude'] ?? $wearable->last_latitude,
            'last_longitude' => $validated['longitude'] ?? $wearable->last_longitude,
            'last_heart_rate' => $validated['heart_rate'] ?? $wearable->last_heart_rate,
            'battery_level' => $validated['battery_level'] ?? $wearable->battery_level,
        ]);

        return response()->json(['success' => true]);
    }

    public function triggerWearableAlert(Request $request, EmergencyWearable $wearable): JsonResponse
    {
        $validated = $request->validate([
            'alert_type' => 'required|in:panic,fall,heart_rate,low_battery,sos',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'heart_rate' => 'nullable|integer',
            'additional_data' => 'nullable|array',
        ]);

        $alert = WearableAlert::create([
            'wearable_id' => $wearable->id,
            'alert_type' => $validated['alert_type'],
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'heart_rate' => $validated['heart_rate'] ?? null,
            'additional_data' => $validated['additional_data'] ?? null,
        ]);

        // كان TODO في OHSMS: تنبيه المستجيبين (داخل النظام + بريد)
        app(NotificationService::class)->notifyRoles(['system_admin', 'system_staff', 'safety_coordinator', 'security_safety_head'], 'iot.wearable',
            'تنبيه من سوار: '.$alert->getTypeLabel().' — '.($wearable->user?->name ?? ''), 'الموقع: '.($validated['latitude'] ?? '?').','.($validated['longitude'] ?? '?'), '/app/emergency/iot/wearables/alerts');

        return response()->json(['success' => true, 'message' => 'تم إرسال التنبيه', 'alert_id' => $alert->id], 201);
    }

    public function wearableAlerts(): JsonResponse
    {
        $alerts = WearableAlert::query()
            ->with(['wearable.user', 'acknowledgedBy'])
            ->active()
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $alerts->map(fn($a) => [
                'id' => $a->id,
                'alert_type' => $a->alert_type,
                'alert_type_label' => $a->getTypeLabel(),
                'status' => $a->status,
                'user' => ['id' => $a->wearable->user_id, 'name' => $a->wearable->user?->name],
                'location' => $a->latitude ? ['lat' => (float)$a->latitude, 'lng' => (float)$a->longitude] : null,
                'heart_rate' => $a->heart_rate,
                'created_at' => $a->created_at->toIso8601String(),
                'acknowledged_by' => $a->acknowledgedBy?->name,
                'acknowledged_at' => $a->acknowledged_at?->toIso8601String(),
            ]),
        ]);
    }

    public function acknowledgeAlert(WearableAlert $alert): JsonResponse
    {
        $alert->acknowledge(auth()->id());
        return response()->json(['success' => true, 'message' => 'تم استلام التنبيه']);
    }

    public function resolveAlert(WearableAlert $alert): JsonResponse
    {
        $alert->resolve();
        return response()->json(['success' => true, 'message' => 'تم إغلاق التنبيه']);
    }

    // ========== الشاشات (كانت في EmergencyController) ==========

    public function camerasDashboard()
    {
        $cameras = EmergencyCamera::with('building', 'place')->orderBy('is_emergency_priority', 'desc')->orderBy('name')->get();
        $buildings = EmergencyBuilding::orderBy('name')->get(['id', 'name']);
        $stats = ['total' => $cameras->count(), 'online' => $cameras->where('status', 'online')->count(), 'offline' => $cameras->where('status', 'offline')->count(),
            'maintenance' => $cameras->where('status', 'maintenance')->count(), 'priority' => $cameras->where('is_emergency_priority', true)->count()];
        return view('modules.emergency.cameras.dashboard', compact('cameras', 'buildings', 'stats'));
    }

    public function camerasCreate()
    {
        $buildings = EmergencyBuilding::orderBy('name')->get(['id', 'name']);
        $places = \App\Modules\Governance\Models\Place::orderBy('sort')->get();
        return view('modules.emergency.cameras.create', compact('buildings', 'places'));
    }

    public function camerasStore(Request $request)
    {
        $validated = $request->validate([
            'building_id' => 'nullable|exists:emergency_buildings,id', 'place_id' => 'nullable|exists:places,id', 'name' => 'required|string|max:100',
            'camera_id' => 'nullable|string|max:50', 'location' => 'nullable|string|max:200', 'stream_url' => 'nullable|url|max:500', 'snapshot_url' => 'nullable|url|max:500',
            'type' => 'required|in:fixed,ptz,dome,thermal', 'is_emergency_priority' => 'nullable|boolean', 'has_audio' => 'nullable|boolean', 'floor_number' => 'nullable|integer',
        ]);
        $validated['is_emergency_priority'] = $request->boolean('is_emergency_priority');
        $validated['has_audio'] = $request->boolean('has_audio');
        EmergencyCamera::create($validated);
        return redirect()->route('emergency.iot.cameras.dashboard')->with('success', 'أُضيفت الكاميرا');
    }

    public function wearablesDashboard()
    {
        $wearables = EmergencyWearable::with('user')->orderByDesc('last_heartbeat_at')->get();
        $activeAlerts = WearableAlert::with(['wearable.user'])->active()->orderByDesc('created_at')->get();
        $stats = ['total_devices' => $wearables->count(), 'active_devices' => $wearables->where('is_active', true)->count(),
            'online_now' => $wearables->filter(fn ($w) => !$w->isStale(5))->count(),
            'low_battery' => $wearables->filter(fn ($w) => $w->battery_level !== null && $w->battery_level < 20)->count(), 'active_alerts' => $activeAlerts->count()];
        return view('modules.emergency.wearables.dashboard', compact('wearables', 'activeAlerts', 'stats'));
    }

    public function wearablesAlerts()
    {
        $alerts = WearableAlert::with(['wearable.user', 'acknowledgedBy'])->orderByDesc('created_at')->paginate(50);
        return view('modules.emergency.wearables.alerts', compact('alerts'));
    }
}
