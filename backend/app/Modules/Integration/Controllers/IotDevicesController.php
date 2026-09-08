<?php

namespace App\Modules\Integration\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\Setting;
use App\Modules\Integration\Models\IotDevice;
use App\Modules\Integration\Models\IotEvent;
use App\Modules\Integration\Services\AccessControlService;
use App\Modules\Integration\Services\DigitalSignageService;
use App\Modules\Integration\Services\ElevatorService;
use App\Modules\Integration\Services\FirePanelService;
use App\Modules\Integration\Services\HvacService;
use Illuminate\Http\Request;

/** شاشات الأجهزة (بدل شاشة TenantSettings في OHSMS): تسجيل، مفتاح التوقيع، خريطة المناطق ← الأماكن، اختبار، سجل الأحداث. */
class IotDevicesController extends Controller
{
    public function dashboard(Request $request)
    {
        $buildings = EmergencyBuilding::active()->orderBy('name')->get(['id', 'name', 'address', 'fire_zones']);
        $selectedBuilding = (int) $request->input('building', $buildings->first()?->id);
        $devices = IotDevice::with('building', 'place')->orderBy('kind')->get();
        $recentEvents = IotEvent::with('device', 'incident')->orderByDesc('received_at')->limit(15)->get();
        return view('modules.emergency.iot.dashboard', compact('buildings', 'selectedBuilding', 'devices', 'recentEvents'));
    }

    public function index()
    {
        $devices = IotDevice::with('building', 'place')->withCount('events')->orderBy('kind')->orderBy('name')->get();
        $allowed = (string) Setting::get('iot.allowed_hosts', '');
        return view('modules.emergency.iot.devices', compact('devices', 'allowed'));
    }

    public function create()
    {
        return view('modules.emergency.iot.device_form', ['device' => new IotDevice(['protocol' => 'webhook', 'scheme' => 'http']), 'buildings' => EmergencyBuilding::orderBy('name')->get(), 'places' => Place::orderBy('sort')->get()]);
    }

    public function store(Request $request)
    {
        $v = $this->validateDevice($request);
        $v['created_by_id'] = auth()->id();
        $device = IotDevice::create($v);
        // المفتاح يظهر مرة واحدة بعد الإنشاء (مشفّر في القاعدة)
        return redirect()->route('emergency.iot.devices.show', $device)->with('success', 'سُجّل الجهاز')->with('show_secret', $device->webhook_secret);
    }

    public function show(IotDevice $device)
    {
        $device->load('building', 'place');
        $events = $device->events()->with('incident')->limit(50)->get();
        $webhookUrl = url('/api/iot/webhooks/'.$device->id);
        $sample = json_encode(['event_type' => 'alarm', 'zone_id' => array_key_first($device->config['zones'] ?? []) ?? '1', 'severity' => 'critical', 'location' => 'اختبار'], JSON_UNESCAPED_UNICODE);
        return view('modules.emergency.iot.device_show', compact('device', 'events', 'webhookUrl', 'sample'));
    }

    public function edit(IotDevice $device)
    {
        return view('modules.emergency.iot.device_form', ['device' => $device, 'buildings' => EmergencyBuilding::orderBy('name')->get(), 'places' => Place::orderBy('sort')->get()]);
    }

    public function update(Request $request, IotDevice $device)
    {
        $v = $this->validateDevice($request, $device);
        if (empty($v['password'])) unset($v['password']);
        $device->update($v);
        return redirect()->route('emergency.iot.devices.show', $device)->with('success', 'حُدّث الجهاز');
    }

    public function rotateSecret(IotDevice $device)
    {
        $device->update(['webhook_secret' => \Illuminate\Support\Str::random(48)]);
        return back()->with('success', 'وُلّد مفتاح توقيع جديد — حدّثه في الجهاز')->with('show_secret', $device->webhook_secret);
    }

    public function destroy(IotDevice $device)
    {
        $device->delete();
        return redirect()->route('emergency.iot.devices.index')->with('success', 'حُذف الجهاز');
    }

    /** اختبار الاتصال الصادر بالجهاز نفسه (البروتوكول المسجّل). */
    public function test(IotDevice $device)
    {
        $svc = match ($device->kind) {
            'fire_panel' => app(FirePanelService::class), 'access_control' => app(AccessControlService::class), 'elevator' => app(ElevatorService::class),
            'hvac' => app(HvacService::class), 'signage' => app(DigitalSignageService::class), default => null,
        };
        if ($device->kind === 'fire_panel') {
            $r = $svc->forDevice($device)->testConnection();
        } elseif ($svc) {
            if (!function_exists('socket_create') && in_array($device->protocol, ['bacnet', 'modbus', 'mqtt'])) {
                $r = ['success' => false, 'error' => 'امتداد sockets غير مثبت في PHP'];
            } elseif ($device->protocol === 'rest') {
                try {
                    $resp = \Illuminate\Support\Facades\Http::timeout(8)->get($device->endpoint('status'));
                    $r = ['success' => $resp->successful(), 'http' => $resp->status()];
                } catch (\Throwable $e) {
                    $r = ['success' => false, 'error' => $e->getMessage()];
                }
            } else {
                $r = ['success' => true, 'note' => 'يستقبل فقط — لا اتصال صادر'];
            }
        } else {
            $r = ['success' => true, 'note' => 'لا اختبار لهذا النوع'];
        }
        $device->touchSeen($r);
        return back()->with($r['success'] ? 'success' : 'error', ($r['success'] ? 'الاتصال ناجح' : 'فشل الاتصال').(isset($r['error']) ? ': '.$r['error'] : '').(isset($r['note']) ? ' — '.$r['note'] : ''));
    }

    public function allowedHosts(Request $request)
    {
        $v = $request->validate(['allowed_hosts' => 'nullable|string|max:2000']);
        Setting::set('iot.allowed_hosts', $v['allowed_hosts'] ?? '', auth()->id());
        return back()->with('success', 'حُفظت القائمة المسموحة');
    }

    public function events(Request $request)
    {
        $events = IotEvent::with('device', 'incident')->orderByDesc('received_at')->paginate(50);
        return view('modules.emergency.iot.events', compact('events'));
    }

    private function validateDevice(Request $request, ?IotDevice $device = null): array
    {
        $v = $request->validate([
            'kind' => 'required|in:'.implode(',', array_keys(IotDevice::KINDS)),
            'name' => 'required|string|max:120',
            'building_id' => 'nullable|exists:emergency_buildings,id',
            'place_id' => 'nullable|exists:places,id',
            'protocol' => 'required|in:'.implode(',', array_keys(IotDevice::PROTOCOLS)),
            'scheme' => 'nullable|in:http,https',
            'host' => 'nullable|string|max:255',
            'port' => 'nullable|integer|min:1|max:65535',
            'base_path' => 'nullable|string|max:255',
            'unit_id' => 'nullable|integer|min:0',
            'username' => 'nullable|string|max:120',
            'password' => 'nullable|string|max:255',
            'config' => 'nullable|string|max:20000',
            'fire_zones' => 'nullable|integer|min:0|max:999',
            'is_enabled' => 'nullable|boolean',
        ]);
        $v['is_enabled'] = $request->boolean('is_enabled');
        $v['scheme'] = $v['scheme'] ?? 'http';
        if (($v['config'] ?? '') !== '') {
            $cfg = json_decode($v['config'], true);
            if (!is_array($cfg)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['config' => 'الإعدادات ليست JSON صحيحاً']);
            }
            $v['config'] = $cfg;
        } else {
            $v['config'] = null;
        }
        if (array_key_exists('fire_zones', $v)) {
            if (!empty($v['building_id']) && $v['fire_zones'] !== null) {
                EmergencyBuilding::where('id', $v['building_id'])->update(['fire_zones' => (int) $v['fire_zones']]);
            }
            unset($v['fire_zones']);
        }
        return $v;
    }
}
