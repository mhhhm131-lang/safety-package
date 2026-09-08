<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Emergency\Models\AssemblyPoint;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Models\EmergencyNotification;
use App\Modules\Emergency\Models\EmergencyTeam;
use App\Modules\Emergency\Models\EvacuationCheckIn;
use App\Modules\Emergency\Models\EvacuationDrill;
use App\Modules\Emergency\Models\Lockdown;
use App\Modules\Emergency\Services\TeamSync;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\Setting;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Store\Models\InstituteDocument;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٤ — الطوارئ. البوابة (BACKEND.md ٧-٢): تفعيل ← تنبيه الفريق ← تسجيل الوصول ← انتهاء ← تقرير.
 * لا اختبارات في OHSMS لهذه الوحدة (الخطوة ٩: تُكتب).
 */
class EmergencyTest extends TestCase
{
    use RefreshDatabase;

    private User $salama;   // مسؤول السلامة
    private User $munawib;  // مناوب المركز
    private User $coord;    // منسق السلامة HZ-06
    private User $fani;     // الفني HZ-06
    private User $mudir;    // مدير إدارة (الموارد البشرية)
    private User $exec;     // المدير العام
    private User $employee; // موظف بلا صلاحية طوارئ
    private EmergencyBuilding $building;
    private AssemblyPoint $point;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlacesSeeder::class);
        $this->seed(OrganizationUnitsSeeder::class);
        $this->seed(EmergencySeeder::class);

        $this->salama = $this->user('salama', 'system_admin');
        $this->munawib = $this->user('munawib', 'system_staff');
        $this->coord = $this->user('coord', 'safety_coordinator', null, 'HZ-06');
        $this->fani = $this->user('fani', 'field_worker', null, 'HZ-06');
        $this->mudir = $this->user('mudir', 'department_manager', OrganizationUnit::first()->code);
        $this->exec = $this->user('idara', 'top_management');
        $this->employee = $this->user('emp', 'employee');

        $this->building = EmergencyBuilding::main();
        $this->point = AssemblyPoint::create(['building_id' => $this->building->id, 'code' => 'A1', 'name' => 'الساحة الأمامية', 'is_primary' => true, 'capacity' => 300]);

        // ملف المكان في اللوحة: فريق أولي مرشَّح ومعتمد للمكاتب الإدارية (إدارة الموارد البشرية) وفريق «_» في القبو مرشَّح فقط
        $unit = OrganizationUnit::first();
        $doc = [
            'HZ-06' => ['plans' => ['sa' => '2026-01-01', 'ra' => '2026-01-01'], 'units' => [
                $unit->code => [
                    'team' => [
                        ['role' => 'المنسق', 'name' => 'أحمد المنسق', 'dept' => 'الموارد البشرية', 'phone' => '0500000001', 'trained' => '2026-02-01', 'trainer' => 'الدفاع المدني'],
                        ['role' => 'المسعف', 'name' => 'سعد المسعف', 'dept' => 'الموارد البشرية', 'phone' => '0500000002', 'trained' => '', 'trainer' => ''],
                        ['role' => 'المنقذ', 'name' => 'خالد المنقذ', 'dept' => 'الموارد البشرية', 'phone' => '0500000003', 'trained' => '', 'trainer' => ''],
                        ['role' => 'الإطفائي', 'name' => 'فهد الإطفائي', 'dept' => 'الموارد البشرية', 'phone' => '', 'trained' => '', 'trainer' => ''],
                    ],
                    'nom' => ['by' => 'مدير الموارد البشرية', 'dept' => $unit->code, 'date' => '2026-03-01'],
                    'appr' => ['by' => 'مدير الشؤون الإدارية والهندسية', 'date' => '2026-03-05'],
                    'hr' => [],
                ],
            ]],
            'HZ-01' => ['plans' => [], 'units' => ['_' => [
                'team' => [['role' => 'المنسق', 'name' => 'ناصر القبو', 'dept' => 'المرافق', 'phone' => '0500000009', 'trained' => '', 'trainer' => '']],
                'nom' => ['by' => 'مدير المرافق', 'dept' => '', 'date' => '2026-03-02'], 'appr' => [], 'hr' => [],
            ]]],
        ];
        InstituteDocument::create(['key' => 'ipa-place', 'data' => json_encode($doc, JSON_UNESCAPED_UNICODE), 'version' => 1]);
        app(TeamSync::class)->sync();
    }

    private function user(string $username, string $role, ?string $unitCode = null, ?string $placeCode = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true,
            'organization_unit_id' => $unitCode ? OrganizationUnit::where('code', $unitCode)->value('id') : null,
            'place_id' => Place::idByCode($placeCode)]);
        return $u;
    }

    private function placeId(string $code): int
    {
        return Place::idByCode($code);
    }

    // ── الطبقة صفر: الفريق الأولي من ملف المكان ──

    public function test_team_sync_derives_initial_teams_from_place_profile(): void
    {
        $teams = EmergencyTeam::where('source', 'place_profile')->get();
        $this->assertCount(2, $teams);

        $hz06 = $teams->firstWhere('place_id', $this->placeId('HZ-06'));
        $this->assertSame('initial', $hz06->team_type);
        $this->assertSame('approved', $hz06->readiness);
        $this->assertTrue($hz06->is_active);
        $this->assertSame(OrganizationUnit::first()->id, $hz06->organization_unit_id);
        $this->assertSame(['coordinator', 'medic', 'rescuer', 'firefighter'], $hz06->members->pluck('role_key')->all());
        $this->assertSame('أحمد المنسق', $hz06->members->first()->name);
        $this->assertSame('leader', $hz06->members->first()->role);
        $this->assertSame('2026-02-01', $hz06->members->first()->trained_at->toDateString());
        $this->assertNull($hz06->members->first()->user_id);

        $hz01 = $teams->firstWhere('place_id', $this->placeId('HZ-01'));
        $this->assertSame('nominated', $hz01->readiness);
        $this->assertCount(1, $hz01->members);

        // اختفاء الفريق من الوثيقة يعطّله ولا يحذفه
        $doc = json_decode(InstituteDocument::where('key', 'ipa-place')->value('data'), true);
        unset($doc['HZ-01']);
        InstituteDocument::where('key', 'ipa-place')->update(['data' => json_encode($doc, JSON_UNESCAPED_UNICODE)]);
        app(TeamSync::class)->sync();
        $this->assertFalse($hz01->fresh()->is_active);
        $this->assertSame(2, EmergencyTeam::where('source', 'place_profile')->count());
    }

    public function test_store_put_of_place_profile_syncs_teams(): void
    {
        $doc = json_decode(InstituteDocument::where('key', 'ipa-place')->value('data'), true);
        $doc['HZ-06']['units'][OrganizationUnit::first()->code]['team'][1]['name'] = 'سعد الجديد';
        $doc['HZ-06']['units'][OrganizationUnit::first()->code]['hr'] = ['date' => '2026-03-10'];
        $this->actingAs($this->salama)->putJson('/api/store/ipa-place', ['data' => json_encode($doc, JSON_UNESCAPED_UNICODE), 'version' => 1])->assertOk();
        $team = EmergencyTeam::where('source', 'place_profile')->where('place_id', $this->placeId('HZ-06'))->first();
        $this->assertSame('referred', $team->readiness);
        $this->assertSame('سعد الجديد', $team->members->firstWhere('role_key', 'medic')->name);
    }

    // ── البوابة ──

    public function test_gate_trigger_notify_arrive_end_report(): void
    {
        // ١) التفعيل: مسؤول السلامة يفعّل حريقاً في المكاتب الإدارية
        $r = $this->actingAs($this->salama)->post("/app/emergency/buildings/{$this->building->id}/trigger", [
            'incident_type' => 'fire', 'severity' => 'high', 'place_id' => $this->placeId('HZ-06'), 'description' => 'دخان في الدور الثاني',
        ]);
        $incident = EmergencyIncident::first();
        $this->assertNotNull($incident);
        $r->assertRedirect("/app/emergency/incidents/{$incident->id}/live");
        $this->assertSame('active', $incident->status);
        $this->assertSame($this->placeId('HZ-06'), $incident->place_id);
        $this->assertSame('ط-0001', $incident->incident_code);
        $this->assertSame('evacuating', $this->building->fresh()->emergency_status);

        // ٢) التنبيه: الفريق الأولي للمكان (بلا حساب → نداء هاتفي يدوي)، القيادة والإدارة (داخل النظام + بريد)، جهات الاتصال
        $types = $incident->eventLogs()->pluck('event_type')->all();
        $this->assertContains('alarm_triggered', $types);
        $this->assertContains('team_notified', $types);
        $this->assertContains('external_notified', $types);
        $calls = EmergencyNotification::where('incident_id', $incident->id)->where('channel', 'phone_call')->get();
        $this->assertSame(3, $calls->where('recipient_type', 'team_member')->where('status', 'manual')->count()); // ثلاثة بأرقام
        $this->assertSame(1, $calls->where('recipient_type', 'team_member')->where('status', 'failed')->count()); // الإطفائي بلا رقم
        $this->assertSame(3, $calls->where('recipient_type', 'contact')->count()); // الدفاع المدني والهلال والشرطة (التنبيه الآلي)
        $this->assertSame(0, $calls->where('recipient_name', 'ناصر القبو')->count()); // فريق مكان آخر لا يُنبَّه
        foreach ([$this->coord, $this->fani, $this->munawib, $this->exec, $this->mudir, $this->employee] as $u) {
            $this->assertDatabaseHas('app_notifications', ['user_id' => $u->id, 'type' => 'emergency.triggered']);
        }
        // الحصر: سجل لكل حساب مفعّل + أعضاء الفريق الأربعة بلا حساب
        $this->assertSame(7, $incident->checkIns()->where('person_type', 'employee')->count());
        $this->assertSame(4, $incident->checkIns()->where('person_type', 'team')->count());

        // الرؤية والصلاحيات: الموظف لا يرى؛ المدير العام يرى ولا يفعّل؛ الفني يصل للتتبع
        $this->actingAs($this->employee)->get('/app/emergency')->assertForbidden();
        $this->actingAs($this->exec)->get("/app/emergency/incidents/{$incident->id}/live")->assertOk()->assertSee('ط-0001');
        $this->actingAs($this->exec)->post("/app/emergency/buildings/{$this->building->id}/trigger", ['incident_type' => 'fire', 'severity' => 'high', 'place_id' => $this->placeId('HZ-06')])->assertForbidden();
        $this->actingAs($this->fani)->get("/app/emergency/incidents/{$incident->id}/live")->assertOk()->assertSee('أحمد المنسق');

        // ٣) الوصول: المنسق يقرّ بالاستلام ويسجّل وصول المسعف (بلا حساب) يدوياً؛ ويمسح رمز الفني عند نقطة التجمع
        $this->actingAs($this->coord)->post("/app/emergency/incidents/{$incident->id}/acknowledge")->assertSessionHas('success');
        $this->assertSame($this->coord->id, $incident->fresh()->acknowledged_by_id);
        $medic = $incident->checkIns()->where('person_type', 'team')->whereHas('teamMember', fn ($q) => $q->where('role_key', 'medic'))->first();
        $this->actingAs($this->coord)->post("/app/emergency/incidents/{$incident->id}/check-in", ['check_in_id' => $medic->id])->assertSessionHas('success');
        $this->assertSame('safe', $medic->fresh()->status);
        $this->assertTrue($incident->eventLogs()->where('event_type', 'team_arrived')->where('message', 'like', '%سعد المسعف%')->exists());
        $faniCheckIn = $incident->checkIns()->where('user_id', $this->fani->id)->first();
        $this->actingAs($this->coord)->postJson('/api/emergency/verify-qr', ['qr_token' => $faniCheckIn->qr_token, 'assembly_point_id' => $this->point->id])->assertOk()->assertJsonPath('data.person_name', 'اسم fani');
        $this->assertSame('qr_scan', $faniCheckIn->fresh()->check_in_method);
        // الموظف يسجّل وصوله بنفسه؛ رمزه نص يُرسم في المتصفح
        $this->actingAs($this->employee)->getJson('/api/emergency/my-qr')->assertOk()->assertJsonPath('has_active_incident', true);
        $this->actingAs($this->employee)->postJson('/api/emergency/check-in', ['assembly_point_id' => $this->point->id])->assertOk();
        $this->actingAs($this->employee)->postJson('/api/emergency/check-in', ['assembly_point_id' => $this->point->id])->assertStatus(422);
        // مفقود ثم عُثر عليه
        $mudirCheckIn = $incident->checkIns()->where('user_id', $this->mudir->id)->first();
        $this->actingAs($this->coord)->post("/app/emergency/incidents/{$incident->id}/mark-missing", ['check_in_id' => $mudirCheckIn->id, 'last_known_location' => 'الدور الثاني'])->assertSessionHas('success');
        $this->assertSame('missing', $mudirCheckIn->fresh()->status);
        $this->actingAs($this->coord)->postJson("/api/emergency/check-ins/{$mudirCheckIn->id}/found", ['notes' => 'في الممر'])->assertOk();
        $this->assertSame('safe', $mudirCheckIn->fresh()->status);
        $stats = $this->actingAs($this->coord)->getJson("/api/emergency/incidents/{$incident->id}/stats")->assertOk()->json('data');
        $this->assertSame(4, $stats['safe']);
        $this->assertSame(1, $stats['team_arrived']);

        // ٤) الانتهاء: الفني لا يملك الإنهاء (آلة الحالة)؛ مسؤول السلامة يُنهي بتقرير
        $this->actingAs($this->fani)->post("/app/emergency/incidents/{$incident->id}/end", ['final_report' => 'x'])->assertForbidden();
        $this->actingAs($this->coord)->post("/app/emergency/incidents/{$incident->id}/contain", ['note' => 'أُطفئ الحريق'])->assertSessionHas('success');
        $this->assertSame('contained', $incident->fresh()->status);
        $this->actingAs($this->salama)->post("/app/emergency/incidents/{$incident->id}/end", ['final_report' => 'حريق صغير في مكتب، أُخمد بالطفاية، لا إصابات.'])
            ->assertRedirect("/app/emergency/incidents/{$incident->id}/report");
        $incident->refresh();
        $this->assertSame('ended', $incident->status);
        $this->assertSame($this->salama->id, $incident->ended_by_id);
        $this->assertSame(11, $incident->total_evacuees);
        $this->assertSame(4, $incident->total_safe);
        $this->assertSame('all_clear', $this->building->fresh()->emergency_status);
        $this->assertTrue($incident->eventLogs()->where('event_type', 'all_clear')->exists());
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->employee->id, 'type' => 'emergency.ended']);
        $this->assertDatabaseHas('audit_logs', ['model_name' => 'EmergencyIncident']);

        // ٥) التقرير
        $this->actingAs($this->exec)->get("/app/emergency/incidents/{$incident->id}/report")->assertOk()
            ->assertSee('ط-0001')->assertSee('أحمد المنسق')->assertSee('أُخمد بالطفاية')->assertSee('تشغيل الإنذار')->assertSee('انتهى الخطر');
        // بعد الانتهاء لا تعديل
        $this->actingAs($this->coord)->post("/app/emergency/incidents/{$incident->id}/acknowledge")->assertForbidden();
        $this->actingAs($this->salama)->post("/app/emergency/incidents/{$incident->id}/end")->assertForbidden();
    }

    public function test_cancel_and_role_rules(): void
    {
        $this->actingAs($this->mudir)->post("/app/emergency/buildings/{$this->building->id}/trigger", ['incident_type' => 'medical', 'severity' => 'medium', 'place_id' => $this->placeId('HZ-06')])->assertRedirect();
        $incident = EmergencyIncident::first();
        // لا تفعيل ثانٍ والحالة مفتوحة
        $this->actingAs($this->salama)->post("/app/emergency/buildings/{$this->building->id}/trigger", ['incident_type' => 'fire', 'severity' => 'high', 'place_id' => $this->placeId('HZ-01')])->assertSessionHas('error');
        $this->assertSame(1, EmergencyIncident::count());
        // مدير الإدارة لا يلغي (manage)؛ المناوب يلغي بسبب
        $this->actingAs($this->mudir)->post("/app/emergency/incidents/{$incident->id}/cancel", ['reason' => 'خطأ'])->assertForbidden();
        $this->actingAs($this->munawib)->post("/app/emergency/incidents/{$incident->id}/cancel", ['reason' => 'إنذار كاذب'])->assertRedirect('/app/emergency');
        $this->assertSame('cancelled', $incident->fresh()->status);
        $this->assertSame('normal', $this->building->fresh()->emergency_status);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->coord->id, 'type' => 'emergency.cancelled']);
    }

    public function test_drill_runs_through_the_same_path_and_is_scored(): void
    {
        $this->actingAs($this->salama)->post('/app/emergency/drills', ['building_id' => $this->building->id, 'place_id' => $this->placeId('HZ-06'), 'drill_type' => 'fire',
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'), 'scenario' => 'حريق في المطبخ', 'target_time_sec' => 300])->assertRedirect('/app/emergency/drills');
        $drill = EvacuationDrill::first();
        $this->assertSame('scheduled', $drill->status);
        $this->actingAs($this->coord)->post("/app/emergency/drills/{$drill->id}/start")->assertRedirect();
        $drill->refresh();
        $incident = $drill->incident;
        $this->assertSame('in_progress', $drill->status);
        $this->assertTrue($incident->is_drill);
        $this->assertSame($this->placeId('HZ-06'), $incident->place_id);
        // التمرين لا يُبلَّغ للجهات الخارجية
        $this->assertSame(0, EmergencyNotification::where('incident_id', $incident->id)->where('recipient_type', 'contact')->count());
        // بعض من وصلوا
        foreach ($incident->checkIns()->take(5)->get() as $c) {
            $this->actingAs($this->coord)->post("/app/emergency/incidents/{$incident->id}/check-in", ['check_in_id' => $c->id, 'assembly_point_id' => $this->point->id]);
        }
        $this->actingAs($this->coord)->post("/app/emergency/drills/{$drill->id}/end", ['observations' => 'تأخر البعض'])->assertRedirect('/app/emergency/drills');
        $drill->refresh();
        $this->assertSame('completed', $drill->status);
        $this->assertSame('ended', $incident->fresh()->status);
        $this->assertNotNull($drill->score);
        $this->assertContains($drill->result, ['pass', 'needs_improvement', 'fail']);
        $this->assertSame(5, $incident->fresh()->total_safe);
        $this->actingAs($this->salama)->get('/app/emergency/drills')->assertOk()->assertSee($drill->drill_code);
    }

    public function test_auto_escalation_uses_settings_without_defaults(): void
    {
        $this->actingAs($this->salama)->post("/app/emergency/buildings/{$this->building->id}/trigger", ['incident_type' => 'gas_leak', 'severity' => 'high', 'place_id' => $this->placeId('HZ-02')]);
        $incident = EmergencyIncident::first();
        $incident->update(['triggered_at' => now()->subMinutes(10)]);
        // بلا مهل: لا شيء
        $this->artisan('emergency:check-escalation')->assertSuccessful();
        $this->assertSame(1, $incident->fresh()->escalation_level);
        // المهلة من الإعدادات
        $this->actingAs($this->salama)->post('/app/emergency/settings', ['minutes' => ['emergency.escalation.no_ack_min' => 2, 'emergency.escalation.no_team_min' => 5]])->assertSessionHas('success');
        $this->assertSame('2', (string) Setting::get('emergency.escalation.no_ack_min'));
        $this->artisan('emergency:check-escalation')->assertSuccessful();
        $incident->refresh();
        $this->assertSame(3, $incident->escalation_level); // لا إقرار (٢) ثم لم يصل أحد (٣)
        $this->assertSame(2, $incident->eventLogs()->where('event_type', 'escalation')->count());
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->coord->id, 'type' => 'emergency.escalated']);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->salama->id, 'type' => 'emergency.escalated']);
        // الخطورة الحرجة تصعّد فوراً للإدارة العليا
        $this->actingAs($this->munawib)->post("/app/emergency/incidents/{$incident->id}/cancel", ['reason' => 'اختبار']);
        $this->actingAs($this->salama)->post("/app/emergency/buildings/{$this->building->id}/trigger", ['incident_type' => 'fire', 'severity' => 'critical', 'place_id' => $this->placeId('HZ-04')]);
        $this->artisan('emergency:check-escalation');
        $this->assertSame(4, EmergencyIncident::orderByDesc('id')->first()->escalation_level);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->exec->id, 'type' => 'emergency.escalated']);
    }

    public function test_lockdown_is_a_stored_incident_and_lifts_through_state_machine(): void
    {
        $this->actingAs($this->salama)->post("/app/emergency/buildings/{$this->building->id}/lockdown", ['level' => 'full', 'reason' => 'شخص مشبوه'])->assertRedirect();
        $lockdown = Lockdown::first();
        $this->assertSame('active', $lockdown->state);
        $this->assertSame('lockdown', $lockdown->incident->incident_type);
        $this->assertSame('deferred_phase_5', $lockdown->results['executed']);
        $this->actingAs($this->salama)->getJson("/api/emergency/buildings/{$this->building->id}/lockdown")->assertOk()->assertJsonPath('data.is_locked_down', true);
        $this->actingAs($this->salama)->post("/app/emergency/lockdowns/{$lockdown->id}/lift", ['reason' => 'زال الخطر'])->assertRedirect();
        $this->assertSame('lifted', $lockdown->fresh()->state);
        $this->assertSame('ended', $lockdown->incident->fresh()->status);
    }

    public function test_panic_alert_notifies_responders_and_escalates_to_incident(): void
    {
        $this->actingAs($this->employee)->postJson('/api/emergency/panic/trigger', ['alert_type' => 'medical', 'message' => 'زميل سقط', 'place_id' => $this->placeId('HZ-06')])->assertCreated();
        $alert = \App\Modules\Emergency\Models\PanicAlert::first();
        $this->assertSame('triggered', $alert->status);
        // لا فريق أمن بحساب → المنسق والمناوب ومسؤول السلامة
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->coord->id, 'type' => 'emergency.panic']);
        $this->actingAs($this->employee)->postJson("/api/emergency/panic/{$alert->id}/acknowledge")->assertForbidden();
        $this->actingAs($this->coord)->postJson("/api/emergency/panic/{$alert->id}/acknowledge")->assertOk();
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->employee->id, 'type' => 'emergency.panic']);
        $this->actingAs($this->salama)->postJson("/api/emergency/panic/{$alert->id}/escalate")->assertOk();
        $alert->refresh();
        $this->assertNotNull($alert->incident_id);
        $this->assertSame('medical', $alert->incident->incident_type);
        $this->assertSame($this->placeId('HZ-06'), $alert->incident->place_id);
        $this->actingAs($this->salama)->get('/app/emergency/panic')->assertOk();
        $this->actingAs($this->salama)->get("/app/emergency/panic/{$alert->id}")->assertOk();
    }

    public function test_mass_message_visitors_medical_and_aar_apis(): void
    {
        // رسالة جماعية لشاغلي مكان: داخل النظام + بريد
        $this->actingAs($this->salama)->postJson('/api/emergency/messages/send', ['title' => 'تنبيه', 'message' => 'ابقوا في أماكنكم', 'target_type' => 'place', 'target_place_id' => $this->placeId('HZ-06'), 'channels' => ['app', 'email']])->assertCreated();
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->fani->id, 'type' => 'emergency.message']);
        $this->assertDatabaseMissing('app_notifications', ['user_id' => $this->exec->id, 'type' => 'emergency.message']);
        $message = \App\Modules\Emergency\Models\EmergencyMassMessage::first();
        $this->assertSame(2, $message->total_recipients);
        $this->actingAs($this->fani)->postJson("/api/emergency/messages/{$message->id}/respond", ['response_type' => 'safe'])->assertOk();
        $this->assertSame(1, $message->fresh()->responded_count);
        $this->actingAs($this->salama)->getJson('/api/emergency/templates')->assertOk()->assertJsonCount(10, 'data');

        // زائر: تسجيل دخول برمز يُرسم في المتصفح
        $r = $this->actingAs($this->munawib)->postJson('/api/emergency/visitors/check-in', ['name' => 'زائر تجريبي', 'phone' => '0501112222', 'place_id' => $this->placeId('HZ-07')])->assertCreated();
        $this->assertNotEmpty($r->json('data.qr_content'));
        $visitor = \App\Modules\Emergency\Models\EmergencyVisitor::first();
        $this->assertSame($this->building->id, $visitor->building_id);
        $this->actingAs($this->munawib)->postJson("/api/emergency/visitors/{$visitor->id}/mark-safe", ['assembly_point_id' => $this->point->id])->assertOk();
        $this->actingAs($this->munawib)->get('/app/emergency/visitors')->assertOk();

        // الملف الطبي الشخصي
        $this->actingAs($this->employee)->putJson('/api/emergency/medical/my-profile', ['blood_type' => 'O+', 'needs_evacuation_assistance' => true, 'mobility_level' => 'limited'])->assertOk();
        $this->actingAs($this->coord)->getJson('/api/emergency/medical/needs-assistance')->assertOk()->assertJsonPath('count', 1);
        $this->actingAs($this->employee)->getJson('/api/emergency/medical/needs-assistance')->assertForbidden();
        $this->actingAs($this->employee)->get('/app/emergency/medical/my-profile')->assertOk();

        // تقرير ما بعد الحادث لحالة منتهية
        $this->actingAs($this->salama)->post("/app/emergency/buildings/{$this->building->id}/trigger", ['incident_type' => 'fire', 'severity' => 'high', 'place_id' => $this->placeId('HZ-06')]);
        $incident = EmergencyIncident::first();
        $this->actingAs($this->salama)->post("/app/emergency/incidents/{$incident->id}/end", ['final_report' => 'انتهى']);
        $this->actingAs($this->salama)->postJson("/api/emergency/aar/incidents/{$incident->id}", ['severity' => 'minor'])->assertCreated();
        $report = \App\Modules\Emergency\Models\AfterActionReport::first();
        $this->assertStringContainsString('ط-0001', $report->title);
        $this->actingAs($this->salama)->postJson("/api/emergency/aar/{$report->id}/actions", ['title' => 'تدريب', 'description' => 'إعادة تدريب الفريق', 'priority' => 'high', 'category' => 'training'])->assertCreated();
        $this->actingAs($this->salama)->getJson('/api/emergency/aar/stats')->assertOk();
        // قيادة الحادث
        $this->actingAs($this->salama)->postJson("/api/emergency/incidents/{$incident->id}/ics/establish")->assertOk()->assertJsonPath('data.status', 'active');
        $this->actingAs($this->salama)->getJson("/api/emergency/incidents/{$incident->id}/ics/forms/201")->assertOk()->assertJsonPath('data.form', 'ICS-201');
    }

    public function test_building_setup_screens_and_pages_render(): void
    {
        $s = $this->actingAs($this->salama);
        $s->post("/app/emergency/buildings/{$this->building->id}/floors", ['floor_number' => 0, 'name' => 'الأرضي'])->assertSessionHas('success');
        $s->post("/app/emergency/buildings/{$this->building->id}/floors", ['floor_number' => 1])->assertSessionHas('success');
        $floor = $this->building->floors()->where('floor_number', 0)->first();
        $s->post("/app/emergency/buildings/{$this->building->id}/exits", ['floor_id' => $floor->id, 'code' => 'E0-1', 'name' => 'المدخل الرئيسي', 'exit_type' => 'main', 'leads_to_point_id' => $this->point->id])->assertSessionHas('success');
        $s->post("/app/emergency/buildings/{$this->building->id}/assembly-points", ['code' => 'B1', 'name' => 'المواقف الخلفية', 'place_id' => $this->placeId('HZ-01')])->assertSessionHas('success');
        $this->assertSame(2, $this->building->assemblyPoints()->count());
        $this->actingAs($this->fani)->post("/app/emergency/buildings/{$this->building->id}/floors", ['floor_number' => 2])->assertForbidden();
        $s = $this->actingAs($this->salama);
        $s->post('/app/emergency/equipment', ['building_id' => $this->building->id, 'place_id' => $this->placeId('HZ-05'), 'equipment_type' => 'fire_extinguisher', 'code' => 'FE-1', 'inspection_frequency' => 'monthly'])->assertRedirect();
        $eq = \App\Modules\Emergency\Models\EmergencyEquipment::first();
        $s->post("/app/emergency/equipment/{$eq->id}/inspect", ['result' => 'needs_attention', 'issues_found' => 'ضغط منخفض'])->assertSessionHas('success');
        $this->assertSame('needs_service', $eq->fresh()->status);
        $s->post('/app/emergency/contacts', ['name' => 'مدير المرافق', 'phone' => '0500000010', 'contact_type' => 'internal', 'priority' => 5, 'auto_notify' => 1])->assertRedirect();
        $s->post('/app/emergency/teams', ['building_id' => $this->building->id, 'place_id' => $this->placeId('HZ-00'), 'name' => 'فريق الأمن', 'team_type' => 'security', 'shift' => 'all'])->assertRedirect();
        $team = EmergencyTeam::where('source', 'manual')->first();
        $s->post("/app/emergency/teams/{$team->id}/members", ['name' => 'رجل أمن', 'role' => 'member', 'phone' => '0500000020'])->assertRedirect();
        $this->assertSame(1, $team->members()->count());
        $derived = EmergencyTeam::where('source', 'place_profile')->first();
        $s->post("/app/emergency/teams/{$derived->id}/members", ['name' => 'x', 'role' => 'member'])->assertSessionHas('error');

        // تفعيل ثم فتح كل الشاشات
        $s->post("/app/emergency/buildings/{$this->building->id}/trigger", ['incident_type' => 'fire', 'severity' => 'high', 'place_id' => $this->placeId('HZ-06')]);
        $incident = EmergencyIncident::first();
        foreach (['/app/emergency', '/app/emergency/incidents', "/app/emergency/incidents/{$incident->id}/live", "/app/emergency/incidents/{$incident->id}/report",
            '/app/emergency/buildings', "/app/emergency/buildings/{$this->building->id}", "/app/emergency/buildings/{$this->building->id}/control", "/app/emergency/buildings/{$this->building->id}/edit",
            '/app/emergency/teams', "/app/emergency/teams/{$team->id}", "/app/emergency/teams/{$derived->id}", '/app/emergency/teams/create', "/app/emergency/teams/{$team->id}/edit",
            '/app/emergency/drills', '/app/emergency/drills/create', '/app/emergency/equipment', '/app/emergency/equipment/create',
            '/app/emergency/contacts', '/app/emergency/contacts/create', '/app/emergency/analytics', '/app/emergency/settings',
            '/app/emergency/panic', '/app/emergency/visitors', '/app/emergency/visitors/kiosk', "/app/emergency/visitors/{$this->building->id}", '/app/emergency/medical', '/app', '/app/emergency/analytics/export'] as $url) {
            $s->get($url)->assertOk();
        }
        $this->actingAs($this->fani)->get('/app/emergency/settings')->assertForbidden();
        $this->actingAs($this->employee)->get('/app/emergency/teams')->assertForbidden();
        $this->actingAs($this->salama)->getJson("/api/emergency/incidents/{$incident->id}/events?after=0")->assertOk()->assertJsonPath('status', 'active');
    }
}
