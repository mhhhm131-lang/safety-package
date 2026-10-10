<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyContact;
use App\Modules\Emergency\Models\EmergencyEquipment;
use App\Modules\Emergency\Models\EmergencyTeam;
use App\Modules\Emergency\Models\EvacuationDrill;
use App\Modules\Emergency\Models\PanicAlert;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Incident\Models\Incident;
use App\Modules\Permit\Models\Permit;
use App\Modules\Permit\Models\PermitType;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PermitTypesSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * قرار ٨٢ (بكلمته «كمل القوائم» ٢٠٢٦-١٠-١٠): قوائم القراءة تمرّ بمباني الحساب — حساب الفرع لا يرى بيانات الملز
 * في: الذعر («ما ينتظرك» وشاشته)، الفريق الأولي، التصاريح، خطط الاستجابة، لوحة التقارير، المعدات، جهات الاتصال،
 * المباني، التمارين، مرشّحات المكان والإدارة، وقوائم الإحالة في صفحة البلاغ. مسؤول السلامة يرى الكل كما كان.
 */
class BranchListsByBuildingTest extends TestCase
{
    use RefreshDatabase;

    private EmergencyBuilding $main;
    private EmergencyBuilding $b;
    private OrganizationUnit $hq;
    private OrganizationUnit $hqFm;
    private OrganizationUnit $branchB;
    private OrganizationUnit $dmmOps;
    private User $farea;
    private User $salama;
    private User $empM;
    private User $empB;
    private User $coordM;
    private User $coordB;
    private User $faniM;
    private User $faniB;
    private Incident $incB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class, PermitTypesSeeder::class]);
        $this->main = EmergencyBuilding::main();
        Place::whereNull('building_id')->update(['building_id' => $this->main->id]); // كما على المنشور بعد الترحيل 100004: أماكن الملز بمبناها
        $this->hq = OrganizationUnit::create(['code' => 'hq', 'name' => 'المركز الرئيسي', 'unit_type' => 'company']);
        $this->main->update(['branch_unit_id' => $this->hq->id]);
        $this->hqFm = OrganizationUnit::create(['code' => 'hq-fm', 'name' => 'المرافق والصيانة', 'unit_type' => 'department', 'parent_id' => $this->hq->id, 'place_id' => Place::idByCode('HZ-06')]);

        $this->branchB = OrganizationUnit::create(['code' => 'br-dmm', 'name' => 'فرع الشرقية', 'unit_type' => 'region', 'parent_id' => $this->hq->id]);
        $this->b = EmergencyBuilding::create(['code' => 'DMM', 'name' => 'معهد الادارة فرع الدمام', 'branch' => 'فرع الشرقية', 'branch_unit_id' => $this->branchB->id]);
        Place::createCategoriesFor($this->b);
        $this->branchB->update(['place_id' => $this->bp('HZ-06')->id]);
        $this->dmmOps = OrganizationUnit::create(['code' => 'dmm-ops', 'name' => 'التشغيل', 'unit_type' => 'department', 'parent_id' => $this->branchB->id, 'place_id' => $this->bp('HZ-06')->id]);

        $this->farea = $this->user('farea', 'branch_manager', $this->b, null, $this->branchB);
        $this->salama = $this->user('salama', 'system_admin');
        $this->empM = $this->user('emp.m', 'employee', $this->main, Place::find(Place::idByCode('HZ-06')), $this->hqFm);
        $this->empB = $this->user('emp.b', 'employee', $this->b, $this->bp('HZ-06'), $this->dmmOps);
        $this->coordM = $this->user('coord.m', 'safety_coordinator', $this->main, Place::find(Place::idByCode('HZ-06')), $this->hqFm);
        $this->coordB = $this->user('coord.b', 'safety_coordinator', $this->b, $this->bp('HZ-00'), $this->branchB);
        $this->faniM = $this->user('fani.m', 'tech_electrical', $this->main, Place::find(Place::idByCode('HZ-01')));
        $this->faniB = $this->user('fani.b', 'tech_electrical', $this->b, $this->bp('HZ-01'));

        // بيانات الملز
        PanicAlert::create(['user_id' => $this->empM->id, 'building_id' => $this->main->id, 'place_id' => Place::idByCode('HZ-06'), 'status' => 'triggered']);
        EmergencyTeam::create(['building_id' => $this->main->id, 'place_id' => Place::idByCode('HZ-01'), 'name' => 'فريق الملز', 'team_type' => 'initial', 'shift' => 'all', 'is_active' => true, 'source' => 'manual']);
        EmergencyEquipment::create(['building_id' => $this->main->id, 'place_id' => Place::idByCode('HZ-01'), 'equipment_type' => 'fire_extinguisher', 'code' => 'EQ-MLZ', 'inspection_frequency' => 'monthly', 'last_inspection_date' => now()->subDays(28), 'next_inspection_date' => now()->addDays(2), 'status' => 'operational']);
        EmergencyContact::create(['building_id' => $this->main->id, 'contact_type' => 'external', 'name' => 'جهة الملز', 'role' => 'حريق', 'phone' => '998', 'priority' => 1, 'is_active' => true]);
        // التمرين بلا مكان فتعرض القائمة اسم مبناه (أسماء الأماكن متطابقة بين المباني)
        EvacuationDrill::create(['building_id' => $this->main->id, 'drill_type' => 'evacuation', 'scheduled_at' => now()->addDays(3), 'scenario' => 'تمرين الملز', 'status' => 'scheduled']);
        $type = PermitType::query()->firstOrFail();
        Permit::create(['code' => 'P-MLZ', 'permit_type_id' => $type->id, 'permit_category' => $type->category ?? 'work', 'title' => 'تصريح الملز', 'place_id' => Place::idByCode('HZ-02'), 'status' => Permit::STATUS_ACTIVE]);
        Incident::create(['code' => 'ش-0901', 'title' => 'بلاغ الملز', 'description' => 'م', 'incident_type' => 'normal', 'status' => 'new', 'place_id' => Place::idByCode('HZ-01')]);

        // بيانات الدمام
        PanicAlert::create(['user_id' => $this->empB->id, 'building_id' => $this->b->id, 'place_id' => $this->bp('HZ-06')->id, 'status' => 'triggered']);
        EmergencyTeam::create(['building_id' => $this->b->id, 'place_id' => $this->bp('HZ-01')->id, 'name' => 'فريق الدمام', 'team_type' => 'initial', 'shift' => 'all', 'is_active' => true, 'source' => 'manual']);
        EmergencyEquipment::create(['building_id' => $this->b->id, 'place_id' => $this->bp('HZ-01')->id, 'equipment_type' => 'fire_extinguisher', 'code' => 'EQ-DMM', 'inspection_frequency' => 'monthly', 'last_inspection_date' => now()->subDays(28), 'next_inspection_date' => now()->addDays(2), 'status' => 'operational']);
        EmergencyContact::create(['building_id' => $this->b->id, 'contact_type' => 'external', 'name' => 'جهة الدمام', 'role' => 'حريق', 'phone' => '998', 'priority' => 1, 'is_active' => true]);
        EvacuationDrill::create(['building_id' => $this->b->id, 'drill_type' => 'evacuation', 'scheduled_at' => now()->addDays(3), 'scenario' => 'تمرين الدمام', 'status' => 'scheduled']);
        Permit::create(['code' => 'P-DMM', 'permit_type_id' => $type->id, 'permit_category' => $type->category ?? 'work', 'title' => 'تصريح الدمام', 'place_id' => $this->bp('HZ-02')->id, 'status' => Permit::STATUS_ACTIVE]);
        $this->incB = Incident::create(['code' => 'ش-0902', 'title' => 'بلاغ الدمام', 'description' => 'د', 'incident_type' => 'normal', 'status' => 'new', 'place_id' => $this->bp('HZ-01')->id]);
    }

    private function bp(string $cat): Place
    {
        return Place::where('building_id', $this->b->id)->where('category', $cat)->firstOrFail();
    }

    private function user(string $username, string $role, ?EmergencyBuilding $building = null, ?Place $place = null, ?OrganizationUnit $unit = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234']);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'building_id' => $building?->id, 'place_id' => $place?->id, 'organization_unit_id' => $unit?->id]);
        return $u;
    }

    public function test_panic_alerts_in_inbox_and_dashboard_follow_the_account_buildings(): void
    {
        $this->actingAs($this->farea);
        $this->get('/app')->assertOk()->assertSee('تنبيه ذعر من «اسم emp.b»')->assertDontSee('تنبيه ذعر من «اسم emp.m»');
        $this->get('/app/emergency/panic')->assertOk()->assertSee('اسم emp.b')->assertDontSee('اسم emp.m');
        $this->actingAs($this->salama);
        $this->get('/app/emergency/panic')->assertOk()->assertSee('اسم emp.b')->assertSee('اسم emp.m');
    }

    public function test_teams_equipment_contacts_drills_and_buildings_lists_follow_the_account_buildings(): void
    {
        $this->actingAs($this->farea);
        $this->get('/app/emergency/teams')->assertOk()->assertSee('فريق الدمام')->assertDontSee('فريق الملز')->assertSee('HZ-01/DMM')->assertDontSee('HZ-01 —');
        $this->get('/app/emergency/equipment')->assertOk()->assertSee('EQ-DMM')->assertDontSee('EQ-MLZ');
        $this->get('/app/emergency/contacts')->assertOk()->assertSee('جهة الدمام')->assertDontSee('جهة الملز');
        $this->get('/app/emergency/drills')->assertOk()->assertSee('معهد الادارة فرع الدمام')->assertDontSee($this->main->name);
        $this->get('/app/emergency/buildings')->assertOk()->assertSee('معهد الادارة فرع الدمام')->assertDontSee($this->main->name);
        $this->get('/app/emergency/plans')->assertOk()->assertSee('HZ-01/DMM')->assertDontSee('HZ-01 —');
        $this->actingAs($this->salama);
        $this->get('/app/emergency/teams')->assertOk()->assertSee('فريق الدمام')->assertSee('فريق الملز');
        $this->get('/app/emergency/buildings')->assertOk()->assertSee('معهد الادارة فرع الدمام')->assertSee($this->main->name);
    }

    public function test_permits_incidents_and_reports_lists_follow_the_account_buildings(): void
    {
        $this->actingAs($this->farea);
        $this->get('/app/permits')->assertOk()->assertSee('P-DMM')->assertDontSee('P-MLZ')->assertDontSee('HZ-02 —');
        $this->get('/app/incidents')->assertOk()->assertSee('ش-0902')->assertDontSee('ش-0901')->assertSee('HZ-01/DMM')->assertDontSee('HZ-01 —');
        $this->get('/app/reports')->assertOk()->assertSee('HZ-01/DMM')->assertDontSee('HZ-01 —');
        $this->actingAs($this->salama);
        $this->get('/app/permits')->assertOk()->assertSee('P-DMM')->assertSee('P-MLZ');
        $this->get('/app/reports')->assertOk()->assertSee('HZ-01/DMM')->assertSee('HZ-01 —');
    }

    public function test_referral_lists_on_the_incident_page_follow_the_building_of_the_incident_place(): void
    {
        // مدير الفرع يرى قائمة المنسقين (لا يملك الإحالة فقائمة المعالجين لا تُبنى له)
        $this->actingAs($this->farea);
        $html = $this->get('/app/incidents/'.$this->incB->id)->assertOk()->getContent();
        $this->assertStringContainsString('اسم coord.b', $html);
        $this->assertStringNotContainsString('اسم coord.m', $html, 'قائمة الإحالة تعرض منسق الملز لبلاغ الدمام');
        // مسؤول السلامة يحيل: القائمتان بمبنى مكان البلاغ لا بمبنى من يفتحه، ومن بلا مبنى (هو) يبقى
        $this->actingAs($this->salama);
        $html = $this->get('/app/incidents/'.$this->incB->id)->assertOk()->getContent();
        $this->assertStringContainsString('اسم fani.b', $html);
        $this->assertStringNotContainsString('اسم fani.m', $html, 'قائمة الإحالة تعرض فني الملز لبلاغ الدمام');
        $this->assertStringContainsString('اسم coord.b', $html);
        $this->assertStringNotContainsString('اسم coord.m', $html);
        $this->assertStringContainsString('اسم salama', $html, 'من بلا مبنى يجب أن يبقى في قائمة الإحالة');
    }

    public function test_active_register_unit_filter_lists_only_the_units_in_scope(): void
    {
        $this->actingAs($this->farea);
        $html = $this->get('/app/risk/active')->assertOk()->getContent();
        $this->assertStringContainsString('التشغيل', $html);
        $this->assertStringNotContainsString('المرافق والصيانة', $html, 'مرشّح الإدارة يعرض إدارة الملز لمدير فرع');
        $this->actingAs($this->salama);
        $this->get('/app/risk/active')->assertOk()->assertSee('المرافق والصيانة')->assertSee('التشغيل');
    }
}
