<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyEventLog;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Models\Lockdown;
use App\Modules\Emergency\Services\EmergencyService;
use App\Modules\Emergency\Services\TeamSync;
use App\Modules\Governance\Models\AppNotification;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\Setting;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Integration\Models\IotDevice;
use App\Modules\Integration\Models\IotEvent;
use App\Modules\Store\Models\InstituteDocument;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٥ — إنترنت الأشياء. البوابة (BACKEND.md ٧-٢): إنذار محاكى عبر Webhook موقّع ينشئ حالة طارئة خلال ثانية.
 * لا اختبارات في OHSMS لهذه الوحدة سوى وحدات البروتوكولات (نُقلت إلى tests/Unit).
 */
class IoTTest extends TestCase
{
    use RefreshDatabase;

    private User $salama;   // مسؤول السلامة (integration.manage)
    private User $coord;    // منسق HZ-06
    private User $employee; // بلا صلاحية
    private EmergencyBuilding $building;
    private IotDevice $panel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlacesSeeder::class);
        $this->seed(OrganizationUnitsSeeder::class);
        $this->seed(EmergencySeeder::class);
        $this->salama = $this->user('salama', 'system_admin');
        $this->coord = $this->user('coord', 'safety_coordinator', null, 'HZ-06');
        $this->employee = $this->user('emp', 'employee');
        $this->building = EmergencyBuilding::main();

        // فريق أولي في HZ-06 من ملف المكان حتى يُنبَّه عند الإنذار الآلي
        $unit = OrganizationUnit::first();
        InstituteDocument::create(['key' => 'ipa-place', 'version' => 1, 'data' => json_encode(['HZ-06' => ['plans' => [], 'units' => [$unit->code => [
            'team' => [['role' => 'المنسق', 'name' => 'أحمد المنسق', 'dept' => 'x', 'phone' => '0500000001', 'trained' => '', 'trainer' => '']],
            'nom' => ['by' => 'م', 'dept' => $unit->code, 'date' => '2026-03-01'], 'appr' => ['by' => 'م', 'date' => '2026-03-05'], 'hr' => [],
        ]]]], JSON_UNESCAPED_UNICODE)]);
        app(TeamSync::class)->sync();

        $this->panel = IotDevice::create([
            'kind' => 'fire_panel', 'name' => 'لوحة الإنذار الرئيسية', 'building_id' => $this->building->id, 'protocol' => 'webhook',
            'config' => ['zones' => ['3' => 'HZ-06', '7' => 'HZ-01']], 'is_enabled' => true, 'created_by_id' => $this->salama->id,
        ]);
        $this->building->update(['fire_zones' => 8]);
    }

    private function user(string $username, string $role, ?string $unitCode = null, ?string $placeCode = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true,
            'organization_unit_id' => $unitCode ? OrganizationUnit::where('code', $unitCode)->value('id') : null, 'place_id' => Place::idByCode($placeCode)]);
        return $u;
    }

    private function webhook(IotDevice $device, array $payload, ?string $secret = null, array $headers = [])
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $sig = 'sha256='.hash_hmac('sha256', $body, $secret ?? $device->webhook_secret);
        return $this->call('POST', "/api/iot/webhooks/{$device->id}", [], [], [], $this->transformHeadersToServerVars(array_merge(
            ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-IPA-Signature' => $sig], $headers)), $body);
    }

    // ── البوابة ──

    public function test_signed_alarm_webhook_creates_emergency_incident_within_one_second(): void
    {
        $t0 = microtime(true);
        $r = $this->webhook($this->panel, ['event_type' => 'alarm', 'zone_id' => '3', 'severity' => 'critical', 'location' => 'الدور الثاني']);
        $elapsed = microtime(true) - $t0;

        $r->assertOk()->assertJsonPath('success', true)->assertJsonPath('action', 'incident_created');
        $this->assertLessThan(1.0, $elapsed, "استغرق {$elapsed}s");

        $incident = EmergencyIncident::findOrFail($r->json('incident_id'));
        $this->assertSame('fire', $incident->incident_type);
        $this->assertSame(EmergencyIncident::STATUS_ACTIVE, $incident->status);
        $this->assertSame(Place::idByCode('HZ-06'), $incident->place_id, 'المنطقة ٣ ← HZ-06 من خريطة الجهاز');
        $this->assertNull($incident->triggered_by_id);
        $this->assertStringContainsString('لوحة الإنذار الرئيسية', $incident->description);
        $this->assertSame('evacuating', $this->building->fresh()->emergency_status);

        // السجل الزمني باسم الجهاز، والفريق الأولي والمركز نُبّهوا بمسار المرحلة ٤ نفسه
        $log = EmergencyEventLog::where('incident_id', $incident->id)->where('event_type', EmergencyEventLog::TYPE_ALARM_TRIGGERED)->first();
        $this->assertStringContainsString('إنذار آلي من لوحة إنذار الحريق', $log->message);
        $this->assertTrue($incident->checkIns()->exists(), 'حصر الفريق الأولي أُنشئ');
        $this->assertTrue(AppNotification::where('user_id', $this->salama->id)->exists());

        $ev = IotEvent::where('device_id', $this->panel->id)->first();
        $this->assertTrue($ev->signature_valid);
        $this->assertSame($incident->id, $ev->incident_id);
        $this->assertNotNull($this->panel->fresh()->last_seen_at);
    }

    public function test_bad_signature_is_rejected_and_logged_without_incident(): void
    {
        $r = $this->webhook($this->panel, ['event_type' => 'alarm', 'zone_id' => '3'], 'wrong-secret');
        $r->assertStatus(401)->assertJsonPath('success', false);
        $this->assertSame(0, EmergencyIncident::count());
        $ev = IotEvent::first();
        $this->assertFalse($ev->signature_valid);
        $this->assertSame('rejected', $ev->action_taken);

        // بلا رأس توقيع إطلاقاً
        $this->postJson("/api/iot/webhooks/{$this->panel->id}", ['event_type' => 'alarm'])->assertStatus(401);
        // جهاز موقوف
        $this->panel->update(['is_enabled' => false]);
        $this->webhook($this->panel, ['event_type' => 'alarm', 'zone_id' => '3'])->assertStatus(401);
        // طابع زمني قديم (إعادة إرسال)
        $this->panel->update(['is_enabled' => true]);
        $this->webhook($this->panel, ['event_type' => 'alarm', 'zone_id' => '3'], null, ['X-IPA-Timestamp' => (string) (time() - 3600)])->assertStatus(401);
        $this->assertSame(0, EmergencyIncident::count());
        // جهاز غير موجود
        $this->postJson('/api/iot/webhooks/9999', ['event_type' => 'alarm'])->assertStatus(404);
    }

    public function test_second_alarm_while_incident_open_logs_to_it_and_restore_notifies(): void
    {
        $first = $this->webhook($this->panel, ['event_type' => 'alarm', 'zone_id' => '3']);
        $iid = $first->json('incident_id');
        $second = $this->webhook($this->panel, ['event_type' => 'alarm', 'zone_id' => '7']);
        $second->assertOk()->assertJsonPath('action', 'logged_to_incident')->assertJsonPath('incident_id', $iid);
        $this->assertSame(1, EmergencyIncident::count());
        $this->assertTrue(EmergencyEventLog::where('incident_id', $iid)->where('message', 'like', '%المنطقة 7%')->exists());

        $before = AppNotification::count();
        $this->webhook($this->panel, ['event_type' => 'restore', 'zone_id' => '3'])->assertOk()->assertJsonPath('action', 'logged_to_incident');
        $this->assertGreaterThan($before, AppNotification::count(), 'عودة الوضع الطبيعي تُنبّه المركز');

        // حدث غير معروف = تحديث حالة فقط
        $this->webhook($this->panel, ['event_type' => 'heartbeat'])->assertOk()->assertJsonPath('action', 'status');
    }

    public function test_hvac_smoke_and_access_control_events(): void
    {
        $hvac = IotDevice::create(['kind' => 'hvac', 'name' => 'تكييف', 'building_id' => $this->building->id, 'protocol' => 'webhook', 'place_id' => Place::idByCode('HZ-02'), 'is_enabled' => true]);
        $r = $this->webhook($hvac, ['event_type' => 'gas_detected', 'location' => 'المطبخ']);
        $r->assertOk()->assertJsonPath('action', 'incident_created');
        $inc = EmergencyIncident::find($r->json('incident_id'));
        $this->assertSame('gas_leak', $inc->incident_type);
        $this->assertSame(Place::idByCode('HZ-02'), $inc->place_id, 'بلا خريطة مناطق ← المكان الافتراضي للجهاز');
        app(EmergencyService::class)->endIncident($inc, $this->salama, 'اختبار');

        $doors = IotDevice::create(['kind' => 'access_control', 'name' => 'أبواب', 'building_id' => $this->building->id, 'protocol' => 'webhook', 'is_enabled' => true]);
        $before = AppNotification::count();
        $this->webhook($doors, ['event_type' => 'forced_door', 'door_id' => 'D-12'])->assertOk()->assertJsonPath('action', 'notified');
        $this->assertSame(1, EmergencyIncident::count(), 'باب مكسور لا يفتح حالة طارئة');
        $this->assertGreaterThan($before, AppNotification::count());
        $this->assertTrue(AppNotification::where('type', 'iot.access_control')->exists());
    }

    // ── الشاشات والصلاحيات ──

    public function test_devices_crud_and_pages(): void
    {
        $s = $this->actingAs($this->salama);
        $s->get('/app/emergency/iot')->assertOk()->assertSee('لوحة الإنذار الرئيسية');
        $s->get('/app/emergency/iot/devices')->assertOk()->assertSee('webhook');
        $s->get('/app/emergency/iot/devices/create')->assertOk();
        $s->get('/app/emergency/iot/events')->assertOk();
        $s->get('/app/emergency/iot/cameras')->assertOk();
        $s->get('/app/emergency/iot/cameras/create')->assertOk();
        $s->get('/app/emergency/iot/wearables')->assertOk();
        $s->get('/app/emergency/iot/wearables/alerts')->assertOk();

        $r = $s->post('/app/emergency/iot/devices', [
            'kind' => 'elevator', 'name' => 'مصاعد المبنى', 'building_id' => $this->building->id, 'protocol' => 'rest', 'scheme' => 'http',
            'host' => '192.168.10.20', 'port' => 8080, 'base_path' => '/api', 'username' => 'ipa', 'password' => 'p@ss', 'config' => '{"recall_floor": 1}', 'fire_zones' => 12, 'is_enabled' => 1,
        ]);
        $d = IotDevice::where('name', 'مصاعد المبنى')->firstOrFail();
        $r->assertRedirect("/app/emergency/iot/devices/{$d->id}")->assertSessionHas('show_secret');
        $this->assertSame(1, $d->config['recall_floor']);
        $this->assertSame('p@ss', $d->password, 'مشفّرة في القاعدة وتُفكّ عند القراءة');
        $this->assertNotSame('p@ss', $d->getRawOriginal('password'));
        $this->assertSame(12, $this->building->fresh()->fire_zones);
        $this->assertSame(48, strlen($d->webhook_secret));

        $s->get("/app/emergency/iot/devices/{$d->id}")->assertOk()->assertSee("/api/iot/webhooks/{$d->id}");
        $s->get("/app/emergency/iot/devices/{$d->id}/edit")->assertOk();
        $s->post('/app/emergency/iot/devices', ['kind' => 'hvac', 'name' => 'x', 'protocol' => 'rest', 'config' => '{bad json'])->assertSessionHasErrors('config');

        $old = $d->webhook_secret;
        $s->post("/app/emergency/iot/devices/{$d->id}/rotate-secret")->assertRedirect();
        $this->assertNotSame($old, $d->fresh()->webhook_secret);
        $this->webhook($d->fresh(), ['event_type' => 'entrapment', 'elevator_id' => 'L1'])->assertOk()->assertJsonPath('action', 'notified');
        $this->webhook($d->fresh(), ['event_type' => 'entrapment'], $old)->assertStatus(401);

        $s->put("/app/emergency/iot/devices/{$d->id}", ['kind' => 'elevator', 'name' => 'مصاعد المبنى', 'protocol' => 'rest', 'host' => '192.168.10.21', 'is_enabled' => 0])->assertRedirect();
        $this->assertSame('p@ss', $d->fresh()->password, 'كلمة مرور فارغة تُبقي القديمة');
        $this->assertFalse($d->fresh()->is_enabled);
        $s->delete("/app/emergency/iot/devices/{$d->id}")->assertRedirect('/app/emergency/iot/devices');
        $this->assertNull(IotDevice::find($d->id));

        // المنسق يرى الأنظمة ولا يدير الأجهزة؛ الموظف لا يرى شيئاً
        $c = $this->actingAs($this->coord);
        $c->get('/app/emergency/iot')->assertOk();
        $c->get('/app/emergency/iot/devices')->assertForbidden();
        $c->post('/app/emergency/iot/devices', ['kind' => 'hvac', 'name' => 'x', 'protocol' => 'rest'])->assertForbidden();
        $this->actingAs($this->employee)->get('/app/emergency/iot')->assertForbidden();
    }

    public function test_status_api_without_devices_reports_offline_and_protocol_hosts_are_restricted(): void
    {
        $s = $this->actingAs($this->salama);
        $b = $this->building->id;
        $r = $s->getJson("/api/iot/buildings/{$b}/status")->assertOk();
        $this->assertTrue($r->json('systems.fire_panel.enabled'));
        $this->assertFalse($r->json('systems.access_control.enabled'));
        $this->assertNull($r->json('systems.access_control.status'));
        $this->assertFalse($r->json('lockdown.is_locked_down'));

        // لوحة webhook: حالتها من آخر إشارة، والمناطق من fire_zones
        $s->getJson("/api/iot/fire-panel/buildings/{$b}/status")->assertOk()->assertJsonPath('online', false)->assertJsonPath('state', 'unknown');
        $zones = $s->getJson("/api/iot/fire-panel/buildings/{$b}/zones")->assertOk()->json();
        $this->assertCount(8, $zones);
        $this->webhook($this->panel, ['event_type' => 'alarm', 'zone_id' => '3']);
        $s = $this->actingAs($this->salama);
        $s->getJson("/api/iot/fire-panel/buildings/{$b}/status")->assertJsonPath('online', true)->assertJsonPath('state', 'alarm');
        $zones = $s->getJson("/api/iot/fire-panel/buildings/{$b}/zones")->json();
        $this->assertSame('alarm', collect($zones)->firstWhere('zone_id', 3)['state']);
        $this->assertSame('HZ-06', collect($zones)->firstWhere('zone_id', 3)['place']);

        // أوامر بلا جهاز: لا خطأ، success=false
        $s->postJson("/api/iot/elevator/buildings/{$b}/fire-recall")->assertOk()->assertJsonPath('success', false);
        $s->postJson("/api/iot/access-control/buildings/{$b}/emergency-unlock")->assertOk()->assertJsonPath('success', false);
        $s->postJson("/api/iot/hvac/buildings/{$b}/shutdown")->assertOk()->assertJsonPath('success', false);

        // البروتوكولات: عنوان غير مسموح 422 قبل أي سوكت؛ عنوان جهاز مسجّل أو من الإعداد يمر إلى الاتصال (بلا امتداد sockets → 500 بالرسالة، أو فشل اتصال)
        $s->postJson('/api/iot/protocols/bacnet/test', ['host' => '10.9.9.9'])->assertStatus(422);
        $s->postJson('/api/iot/protocols/modbus/read', ['host' => '10.9.9.9', 'address' => 0])->assertStatus(422);
        IotDevice::create(['kind' => 'hvac', 'name' => 'h', 'protocol' => 'modbus', 'host' => '10.0.0.5', 'port' => 502, 'is_enabled' => true]);
        Setting::set('iot.allowed_hosts', "10.0.0.6, 10.0.0.7", $this->salama->id);
        foreach (['10.0.0.5', '10.0.0.7'] as $host) {
            $r = $s->postJson('/api/iot/protocols/modbus/test', ['host' => $host, 'port' => 1]);
            $this->assertContains($r->status(), [200, 500]);
            $this->assertFalse($r->json('success'));
        }
        // المنسق لا يختبر البروتوكولات
        $this->actingAs($this->coord)->postJson('/api/iot/protocols/modbus/test', ['host' => '10.0.0.5'])->assertForbidden();
        $this->actingAs($this->employee)->getJson("/api/iot/buildings/{$b}/status")->assertForbidden();
    }

    public function test_lockdown_via_iot_api_records_no_device_results(): void
    {
        $s = $this->actingAs($this->salama);
        $b = $this->building->id;
        $r = $s->postJson("/api/iot/lockdown/buildings/{$b}/initiate", ['level' => 'full', 'reason' => 'اختبار'])->assertOk()->assertJsonPath('success', true);
        $this->assertSame('no_device', $r->json('results.access_control'));
        $this->assertSame('no_device', $r->json('results.signage'));
        $l = Lockdown::find($r->json('lockdown_id'));
        $this->assertTrue($l->isActive());
        $s->postJson("/api/iot/lockdown/buildings/{$b}/initiate", ['level' => 'full'])->assertStatus(422);
        $s->getJson("/api/iot/lockdown/buildings/{$b}/status")->assertJsonPath('is_locked_down', true);
        $s->postJson("/api/iot/lockdown/buildings/{$b}/lift", ['reason' => 'انتهى'])->assertOk();
        $this->assertSame('lifted', $l->fresh()->state);
        $this->assertSame('no_device', $l->fresh()->results['lifted']['elevators']);
        $this->assertSame(EmergencyIncident::STATUS_ENDED, $l->incident->fresh()->status);
        // المنسق يملك emergency.trigger (قرار المرحلة ٤) فيبدأ الإغلاق؛ الموظف لا
        $this->actingAs($this->employee)->postJson("/api/iot/lockdown/buildings/{$b}/initiate", ['level' => 'soft'])->assertForbidden();
    }

    public function test_wearable_alert_notifies_responders(): void
    {
        $s = $this->actingAs($this->coord);
        $r = $s->postJson('/api/iot/wearables/register', ['device_type' => 'smartwatch', 'device_id' => 'W-1', 'device_name' => 'ساعة'])->assertStatus(201);
        $wid = $r->json('data.id') ?? \App\Modules\Emergency\Models\EmergencyWearable::first()->id;
        $s->postJson("/api/iot/wearables/{$wid}/heartbeat", ['battery_level' => 80, 'latitude' => 24.7, 'longitude' => 46.7])->assertOk();
        $before = AppNotification::where('user_id', $this->salama->id)->count();
        $r = $s->postJson("/api/iot/wearables/{$wid}/alert", ['alert_type' => 'sos', 'latitude' => 24.7, 'longitude' => 46.7])->assertStatus(201);
        $this->assertGreaterThan($before, AppNotification::where('user_id', $this->salama->id)->count());
        $aid = $r->json('alert_id');
        $this->actingAs($this->salama)->postJson("/api/iot/wearables/alerts/{$aid}/acknowledge")->assertOk();
        $this->actingAs($this->salama)->postJson("/api/iot/wearables/alerts/{$aid}/resolve", ['notes' => 'تم'])->assertOk();
        $this->actingAs($this->employee)->postJson("/api/iot/wearables/alerts/{$aid}/acknowledge")->assertForbidden();
    }
}
