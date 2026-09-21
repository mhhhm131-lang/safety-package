<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Emergency\Models\AssemblyPoint;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Models\EmergencyNotification;
use App\Modules\Emergency\Models\EmergencyTeam;
use App\Modules\Emergency\Models\EmergencyTeamMember;
use App\Modules\Emergency\Models\PanicAlert;
use App\Modules\Emergency\Models\PanicAlertResponder;
use App\Modules\Emergency\Services\EmergencyService;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٢٢-٤ (د): المستجيب يردّ بزر.
 *
 * العيوب المُعادة إنتاجها (جولة ٢٢-١ على النظام الممتلئ):
 *  ع٤ شاشة تنبيه الذعر تعرض جدول المستجيبين ولا زر يجعل أحداً مستجيباً («استلمتُ»/«أنا في الطريق» بلا زر).
 *  ع٥ بطاقة «نادِ هاتفياً» تظهر للمناوب وزرّها الوحيد «شاشة الحالة» — لا يُغلق النداء فلا يُعرف من اتُّصل به.
 *  ونية «وصلتُ» تفتح لوحة المركز ولا تسجّل وصولاً.
 */
class ResponderButtonsTest extends TestCase
{
    use RefreshDatabase;

    private User $salama;
    private User $munawib;
    private User $medic;      // عضو الفريق الأولي: مستجيب وله حساب
    private User $employee;
    private EmergencyBuilding $building;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlacesSeeder::class);
        $this->seed(OrganizationUnitsSeeder::class);
        $this->seed(EmergencySeeder::class);

        $this->salama = $this->user('salama', 'system_admin');
        $this->munawib = $this->user('munawib', 'system_staff');
        $this->medic = $this->user('medic', 'medic', 'HZ-06');
        $this->employee = $this->user('emp', 'employee', 'HZ-06');

        $this->building = EmergencyBuilding::main();
        AssemblyPoint::create(['building_id' => $this->building->id, 'code' => 'A1',
            'name' => 'الساحة الأمامية', 'is_primary' => true, 'capacity' => 300]);

        // فريق أولي للمكاتب: المسعف بحساب، والإطفائي بلا حساب (يُنادى هاتفياً)
        $team = EmergencyTeam::create([
            'building_id' => $this->building->id, 'place_id' => Place::idByCode('HZ-06'),
            'name' => 'الفريق الأولي — المكاتب', 'team_type' => 'initial', 'shift' => 'all',
            'is_active' => true, 'source' => 'manual',
        ]);
        EmergencyTeamMember::create(['team_id' => $team->id, 'user_id' => $this->medic->id,
            'name' => $this->medic->name, 'role' => 'member', 'role_key' => 'medic', 'is_available' => true]);
        EmergencyTeamMember::create(['team_id' => $team->id, 'name' => 'فهد الإطفائي',
            'role' => 'member', 'role_key' => 'firefighter', 'phone' => '0500000004', 'is_available' => true]);
    }

    private function user(string $username, string $role, ?string $placeCode = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'place_id' => Place::idByCode($placeCode)]);
        return $u;
    }

    private function trigger(): EmergencyIncident
    {
        return app(EmergencyService::class)->triggerAlarm(
            $this->building, 'fire', $this->salama, 'high', false, 'اختبار أزرار المستجيب', Place::idByCode('HZ-06')
        );
    }

    private function panic(): PanicAlert
    {
        $this->actingAs($this->employee)->post('/app/emergency/sos', ['alert_type' => 'medical']);
        return PanicAlert::latest('id')->firstOrFail();
    }

    // ── ع٤: «استلمتُ» و«أنا في الطريق» ──

    public function test_panic_screen_shows_responder_buttons(): void
    {
        $alert = $this->panic();
        $this->assertTrue(
            PanicAlertResponder::where('panic_alert_id', $alert->id)->where('user_id', $this->medic->id)->exists(),
            'المسعف لم يُسجَّل مستجيباً عند إطلاق الاستغاثة'
        );

        $this->actingAs($this->medic)->get("/app/emergency/panic/{$alert->id}")
            ->assertOk()
            ->assertSee('استلمتُ', false)
            ->assertSee('أنا في الطريق', false);
    }

    public function test_responder_acknowledges_then_says_he_is_on_the_way(): void
    {
        $alert = $this->panic();

        $this->actingAs($this->medic)
            ->post("/app/emergency/panic/{$alert->id}/respond", ['response_type' => 'acknowledged'])
            ->assertRedirect("/app/emergency/panic/{$alert->id}");
        $this->assertSame(PanicAlert::STATUS_ACKNOWLEDGED, $alert->fresh()->status);
        $this->assertSame($this->medic->id, $alert->fresh()->acknowledged_by_id);

        $this->actingAs($this->medic)
            ->post("/app/emergency/panic/{$alert->id}/respond", ['response_type' => 'en_route']);
        $this->assertSame(PanicAlert::STATUS_RESPONDING, $alert->fresh()->status);

        $row = PanicAlertResponder::where('panic_alert_id', $alert->id)->where('user_id', $this->medic->id)->first();
        $this->assertSame('en_route', $row->response_type);
        $this->assertNotNull($row->responded_at);
    }

    public function test_plain_employee_cannot_respond_to_an_alert(): void
    {
        $alert = $this->panic();
        $this->actingAs($this->employee)
            ->post("/app/emergency/panic/{$alert->id}/respond", ['response_type' => 'acknowledged'])
            ->assertForbidden();
    }

    // ── ع٥: «نوديَ» يغلق النداء الهاتفي ──

    public function test_centre_closes_a_phone_call_with_one_button(): void
    {
        $incident = $this->trigger();
        $call = EmergencyNotification::where('incident_id', $incident->id)
            ->where('channel', EmergencyNotification::CHANNEL_PHONE)
            ->where('status', EmergencyNotification::STATUS_MANUAL)
            ->where('recipient_name', 'فهد الإطفائي')->first();
        $this->assertNotNull($call, 'لم يُسجَّل نداء هاتفي للعضو بلا حساب');

        // البطاقة تظهر للمناوب ومعها زر «نوديَ»
        $this->actingAs($this->munawib)->get('/app')
            ->assertOk()
            ->assertSee('نادِ هاتفياً «فهد الإطفائي»', false)
            ->assertSee('نوديَ', false);

        $this->actingAs($this->munawib)->post("/app/emergency/calls/{$call->id}/done")->assertRedirect();

        $this->assertSame(EmergencyNotification::STATUS_SENT, $call->fresh()->status);
        $this->assertNotNull($call->fresh()->sent_at);
        // ويُقيَّد في الخط الزمني من نادى ومَن نُوديَ
        $this->assertDatabaseHas('emergency_event_logs', [
            'incident_id' => $incident->id, 'user_id' => $this->munawib->id,
            'event_type' => \App\Modules\Emergency\Models\EmergencyEventLog::TYPE_TEAM_NOTIFIED,
        ]);

        // وتختفي بطاقته بعدها (وتبقى بطاقات من لم يُنادَ بعد)
        $this->actingAs($this->munawib)->get('/app')->assertOk()
            ->assertDontSee('نادِ هاتفياً «فهد الإطفائي»', false);
    }

    public function test_only_the_centre_closes_calls(): void
    {
        $incident = $this->trigger();
        $call = EmergencyNotification::where('incident_id', $incident->id)
            ->where('status', EmergencyNotification::STATUS_MANUAL)->firstOrFail();

        $this->actingAs($this->employee)->post("/app/emergency/calls/{$call->id}/done")->assertForbidden();
    }

    // ── «وصلتُ» تسجّل الوصول فعلاً ──

    public function test_team_member_records_his_arrival_from_his_screen(): void
    {
        $incident = $this->trigger();

        $this->actingAs($this->medic)->get('/app/emergency/me')
            ->assertOk()
            ->assertSee('وصلتُ إلى الموقع', false);

        $this->actingAs($this->medic)->post('/app/emergency/me/arrived')
            ->assertRedirect('/app/emergency/me');

        // الوصول يُسجَّل كما يسجّله المركز يدوياً: سطر في الخط الزمني + حصر الفريق
        $member = EmergencyTeamMember::where('user_id', $this->medic->id)->firstOrFail();
        $this->assertDatabaseHas('emergency_event_logs', [
            'incident_id' => $incident->id,
            'event_type' => \App\Modules\Emergency\Models\EmergencyEventLog::TYPE_TEAM_ARRIVED,
        ]);
        $log = \App\Modules\Emergency\Models\EmergencyEventLog::where('incident_id', $incident->id)
            ->where('event_type', \App\Modules\Emergency\Models\EmergencyEventLog::TYPE_TEAM_ARRIVED)->first();
        $this->assertSame($member->id, (int) ($log->data['team_member_id'] ?? 0));
        $this->assertStringContainsString('اسم medic', $log->message);

        // والشاشة تقول له إنه مسجَّل، فلا يضغط مرتين
        $this->actingAs($this->medic)->get('/app/emergency/me')->assertOk()
            ->assertSee('وصولك إلى الموقع مسجَّل', false)
            ->assertDontSee('وصلتُ إلى الموقع', false);
    }

    public function test_arrived_intent_points_at_the_action_not_the_dashboard(): void
    {
        $this->trigger();
        $intent = \App\Core\Intents\IntentRegistry::forUser($this->medic)->firstWhere('key', 'arrived');
        $this->assertNotNull($intent, 'المستجيب بلا نية «وصلتُ»');
        $this->assertStringContainsString('/app/emergency/me', $intent->url);
    }
}
