<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Emergency\Models\AssemblyPoint;
use App\Modules\Emergency\Models\BuildingExit;
use App\Modules\Emergency\Models\BuildingFloor;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EvacuationCheckIn;
use App\Modules\Emergency\Services\EmergencyService;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٢٢-٢ (د): الشخص وقت الحالة — شاشة واحدة على جواله.
 *
 * العيب المُعاد إنتاجه (جولة ٢٢-١ على النظام الممتلئ): الموظف لا يرى الحالة إطلاقاً ولا شاشة له،
 * وتسجيل الوصول مستحيل عليه لأن `/api/emergency/buildings/{b}/assembly-points` يرد ٤٠٣ خلف
 * `permission:emergency.view,emergency.respond` بينما `check-in` يشترط `assembly_point_id`.
 *
 * البوابة: موظف بلا أي صلاحية طوارئ يفتح شاشته، يرى ما يجري وتعليماته وأقرب مخرج ونقطة التجمع،
 * ويسجّل وصوله بزر فيظهر في حصر شاشة الحالة، ويطلب مساعدة فتظهر لمن يستجيب.
 */
class MyEmergencyTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;
    private User $salama;
    private EmergencyBuilding $building;
    private AssemblyPoint $point;
    private BuildingFloor $floor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlacesSeeder::class);
        $this->seed(OrganizationUnitsSeeder::class);
        $this->seed(EmergencySeeder::class);

        $this->employee = $this->user('emp', 'employee', 'HZ-06');
        $this->salama = $this->user('salama', 'system_admin');

        $this->building = EmergencyBuilding::main();
        $this->point = AssemblyPoint::create(['building_id' => $this->building->id, 'code' => 'A1',
            'name' => 'الساحة الأمامية', 'is_primary' => true, 'capacity' => 300, 'directions' => 'أمام المدخل الرئيسي']);

        // طابقان: طابق المكاتب (HZ-06) وطابق القبو (HZ-01)، لكل منهما مخرج — لاختبار «أقرب مخرج» بطابق الشخص
        $basement = BuildingFloor::create(['building_id' => $this->building->id, 'floor_number' => -1,
            'name' => 'القبو', 'zone' => 'HZ-01', 'created_at' => now()]);
        $this->floor = BuildingFloor::create(['building_id' => $this->building->id, 'floor_number' => 2,
            'name' => 'الدور الثاني — المكاتب', 'zone' => 'HZ-06', 'created_at' => now()]);
        BuildingExit::create(['building_id' => $this->building->id, 'floor_id' => $basement->id, 'code' => 'RB',
            'name' => 'مخرج القبو', 'exit_type' => 'main', 'status' => 'available', 'leads_to_point_id' => $this->point->id, 'created_at' => now()]);
        BuildingExit::create(['building_id' => $this->building->id, 'floor_id' => $this->floor->id, 'code' => 'R2',
            'name' => 'مخرج الدور الثاني', 'exit_type' => 'main', 'status' => 'available', 'leads_to_point_id' => $this->point->id, 'created_at' => now()]);
    }

    private function user(string $username, string $role, ?string $placeCode = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'place_id' => Place::idByCode($placeCode)]);
        return $u;
    }

    private function trigger(string $type = 'fire'): \App\Modules\Emergency\Models\EmergencyIncident
    {
        return app(EmergencyService::class)->triggerAlarm(
            $this->building, $type, $this->salama, 'high', false,
            'اختبار شاشة الشخص', Place::idByCode('HZ-06')
        );
    }

    /** بلا حالة مفتوحة: الشاشة تفتح ولا تسقط، وتقول لا حالة. */
    public function test_screen_opens_for_plain_employee_without_incident(): void
    {
        $this->actingAs($this->employee)->get('/app/emergency/me')
            ->assertOk()
            ->assertSee('لا حالة طارئة', false);
    }

    /** الموظف بلا أي صلاحية طوارئ يرى ما يجري وتعليماته وأقرب مخرج ونقطة التجمع. */
    public function test_plain_employee_sees_incident_instructions_exit_and_point(): void
    {
        $this->assertFalse(
            \App\Core\Permissions\PermissionRegistry::hasPermission('employee', 'emergency.view'),
            'الموظف يجب أن يكون بلا صلاحية طوارئ حتى يكون الاختبار ذا معنى'
        );
        $this->trigger();

        $this->actingAs($this->employee)->get('/app/emergency/me')
            ->assertOk()
            ->assertSee('حريق', false)
            ->assertSee('الساحة الأمامية', false)
            ->assertSee('مخرج الدور الثاني', false)   // مخرج طابقه هو، لا مخرج القبو
            ->assertDontSee('مخرج القبو', false)
            ->assertSee('سجّل وصولي', false)
            ->assertSee('أحتاج مساعدة', false);
    }

    /** يسجّل وصوله بزر واحد فيصير «بأمان» ويظهر في الحصر. */
    public function test_employee_checks_in_from_his_screen(): void
    {
        $incident = $this->trigger();

        $this->actingAs($this->employee)
            ->post('/app/emergency/me/check-in', ['assembly_point_id' => $this->point->id])
            ->assertRedirect('/app/emergency/me');

        $checkIn = EvacuationCheckIn::where('incident_id', $incident->id)
            ->where('user_id', $this->employee->id)->first();
        $this->assertNotNull($checkIn, 'لا سجل حضور للموظف');
        $this->assertSame(EvacuationCheckIn::STATUS_SAFE, $checkIn->status);
        $this->assertSame($this->point->id, $checkIn->assembly_point_id);

        $this->actingAs($this->employee)->get('/app/emergency/me')->assertOk()->assertSee('بأمان', false);
    }

    /** يطلب مساعدة فتظهر لمن يستجيب على شاشة الحالة. */
    public function test_employee_requests_help_and_responder_sees_it(): void
    {
        $incident = $this->trigger();

        $this->actingAs($this->employee)
            ->post('/app/emergency/me/help', ['help_type' => 'mobility', 'notes' => 'لا أستطيع النزول'])
            ->assertRedirect('/app/emergency/me');

        $checkIn = EvacuationCheckIn::where('incident_id', $incident->id)
            ->where('user_id', $this->employee->id)->first();
        $this->assertTrue((bool) $checkIn->needs_assistance, 'لم يُسجَّل طلب المساعدة');

        $this->actingAs($this->salama)->get("/app/emergency/incidents/{$incident->id}/live")
            ->assertOk()
            ->assertSee('يحتاجون مساعدة', false)
            ->assertSee('لا أستطيع النزول', false);
    }

    /** المدخل: الموظف يجد الشاشة بلا بحث — شريط أحمر في كل صفحة وزر أحمر ثابت على الجوال. */
    public function test_employee_finds_the_screen_from_any_page(): void
    {
        $this->actingAs($this->employee)->get('/app')->assertOk()->assertDontSee('ماذا أفعل', false);

        $this->trigger();

        $home = $this->actingAs($this->employee)->get('/app')->assertOk();
        $home->assertSee('حالة طارئة — ماذا أفعل', false);
        $home->assertSee('/app/emergency/me', false);
        $home->assertSee('sosBar', false);   // الزر الأحمر الثابت على الجوال صار له
    }

    /** التمرين يُسمّى تمريناً فلا يُفزع أحد. */
    public function test_drill_is_labelled_as_a_drill(): void
    {
        app(EmergencyService::class)->triggerAlarm(
            $this->building, 'fire', $this->salama, 'medium', true, 'تمرين', Place::idByCode('HZ-06')
        );
        $this->actingAs($this->employee)->get('/app')->assertOk()->assertSee('تمرين إخلاء — ماذا أفعل', false);
        $this->actingAs($this->employee)->get('/app/emergency/me')->assertOk()->assertSee('تمرين إخلاء', false);
    }

    /** الشاشة لكل حساب: لا تُحجب خلف صلاحية طوارئ. */
    public function test_screen_is_not_behind_emergency_permission(): void
    {
        $this->trigger();
        foreach (['employee', 'field_worker', 'medic', 'system_staff'] as $role) {
            $u = $this->user('u_'.$role, $role, 'HZ-06');
            $this->actingAs($u)->get('/app/emergency/me')->assertOk();
        }
    }
}
