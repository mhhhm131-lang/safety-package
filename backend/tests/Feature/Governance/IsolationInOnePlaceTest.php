<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyContact;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Models\EmergencyTeam;
use App\Modules\Emergency\Models\EmergencyVisitor;
use App\Modules\Emergency\Models\PanicAlert;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Permit\Models\Permit;
use App\Modules\Permit\Models\PermitType;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PermitTypesSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * قرار ٨٤ (بكلمته «طبّق الأفضل في العالم للعزل» ٢٠٢٦-١٠-١٠): العزل في موضع واحد.
 * بعد الفحص الكامل (٧-٣ بتاريخه): ثلاثون رابطاً من الملز كانت تُفتح لحسابات الدمام — رابط بمبنى، وصفحة كائن واحد،
 * وقوائم لم يشملها قرار ٨٢. الحل نطاق عام بالمبنى + حارس ربط المعرّف + قوائم المكان من مصدر واحد.
 */
class IsolationInOnePlaceTest extends TestCase
{
    use RefreshDatabase;

    private EmergencyBuilding $main;
    private EmergencyBuilding $b;
    private OrganizationUnit $branchB;
    private User $farea;
    private User $salama;
    private User $empM;
    private User $empB;
    private Permit $pMlz;
    private Permit $pDmm;
    private EmergencyTeam $tMlz;
    private EmergencyTeam $tDmm;
    private PanicAlert $aMlz;
    private PanicAlert $aDmm;
    private EmergencyIncident $iMlz;
    private EmergencyIncident $iDmm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class, PermitTypesSeeder::class]);
        $this->main = EmergencyBuilding::main();
        Place::whereNull('building_id')->update(['building_id' => $this->main->id]);
        $hq = OrganizationUnit::create(['code' => 'hq', 'name' => 'المركز الرئيسي', 'unit_type' => 'company']);
        $this->main->update(['branch_unit_id' => $hq->id]);
        $hqFm = OrganizationUnit::create(['code' => 'hq-fm', 'name' => 'المرافق والصيانة', 'unit_type' => 'department', 'parent_id' => $hq->id, 'place_id' => Place::idByCode('HZ-06')]);
        $this->branchB = OrganizationUnit::create(['code' => 'br-dmm', 'name' => 'فرع الشرقية', 'unit_type' => 'region', 'parent_id' => $hq->id]);
        $this->b = EmergencyBuilding::create(['code' => 'DMM', 'name' => 'معهد الادارة فرع الدمام', 'branch' => 'فرع الشرقية', 'branch_unit_id' => $this->branchB->id]);
        Place::createCategoriesFor($this->b);
        $this->branchB->update(['place_id' => $this->bp('HZ-06')->id]);
        $dmmOps = OrganizationUnit::create(['code' => 'dmm-ops', 'name' => 'التشغيل', 'unit_type' => 'department', 'parent_id' => $this->branchB->id, 'place_id' => $this->bp('HZ-06')->id]);

        $this->farea = $this->user('farea', 'branch_manager', $this->b, null, $this->branchB);
        $this->salama = $this->user('salama', 'system_admin');
        $this->empM = $this->user('emp.m', 'employee', $this->main, Place::find(Place::idByCode('HZ-06')), $hqFm);
        $this->empB = $this->user('emp.b', 'employee', $this->b, $this->bp('HZ-06'), $dmmOps);

        $type = PermitType::query()->firstOrFail();
        $this->pMlz = Permit::create(['code' => 'P-MLZ', 'permit_type_id' => $type->id, 'permit_category' => $type->category ?? 'work', 'title' => 'تصريح الملز', 'place_id' => Place::idByCode('HZ-02'), 'status' => Permit::STATUS_ACTIVE]);
        $this->pDmm = Permit::create(['code' => 'P-DMM', 'permit_type_id' => $type->id, 'permit_category' => $type->category ?? 'work', 'title' => 'تصريح الدمام', 'place_id' => $this->bp('HZ-02')->id, 'status' => Permit::STATUS_ACTIVE]);
        $this->tMlz = EmergencyTeam::create(['building_id' => $this->main->id, 'place_id' => Place::idByCode('HZ-01'), 'name' => 'فريق الملز', 'team_type' => 'initial', 'shift' => 'all', 'is_active' => true, 'source' => 'manual']);
        $this->tDmm = EmergencyTeam::create(['building_id' => $this->b->id, 'place_id' => $this->bp('HZ-01')->id, 'name' => 'فريق الدمام', 'team_type' => 'initial', 'shift' => 'all', 'is_active' => true, 'source' => 'manual']);
        $this->aMlz = PanicAlert::create(['user_id' => $this->empM->id, 'building_id' => $this->main->id, 'place_id' => Place::idByCode('HZ-06'), 'status' => 'triggered']);
        $this->aDmm = PanicAlert::create(['user_id' => $this->empB->id, 'building_id' => $this->b->id, 'place_id' => $this->bp('HZ-06')->id, 'status' => 'triggered']);
        $this->iMlz = EmergencyIncident::create(['building_id' => $this->main->id, 'place_id' => Place::idByCode('HZ-00'), 'incident_code' => 'ط-9001', 'incident_type' => 'fire', 'severity' => 'high', 'status' => 'ended', 'triggered_at' => now()->subDay(), 'ended_at' => now()->subDay()->addHour(), 'triggered_by_id' => $this->empM->id]);
        $this->iDmm = EmergencyIncident::create(['building_id' => $this->b->id, 'place_id' => $this->bp('HZ-00')->id, 'incident_code' => 'ط-9002', 'incident_type' => 'fire', 'severity' => 'high', 'status' => 'ended', 'triggered_at' => now()->subDay(), 'ended_at' => now()->subDay()->addHour(), 'triggered_by_id' => $this->empB->id]);
        EmergencyVisitor::create(['building_id' => $this->main->id, 'name' => 'زائر الملز', 'phone' => '0500000001', 'status' => 'checked_in', 'checked_in_at' => now()->subHour(), 'badge_number' => 'V-MLZ']);
        EmergencyVisitor::create(['building_id' => $this->b->id, 'name' => 'زائر الدمام', 'phone' => '0500000002', 'status' => 'checked_in', 'checked_in_at' => now()->subHour(), 'badge_number' => 'V-DMM']);
        EmergencyContact::create(['building_id' => null, 'contact_type' => 'external', 'name' => 'جهة عامة ٩٩٨', 'role' => 'حريق', 'phone' => '998', 'priority' => 1, 'is_active' => true]);
        EmergencyContact::create(['building_id' => $this->main->id, 'contact_type' => 'internal', 'name' => 'جهة الملز', 'role' => 'أمن', 'phone' => '0111', 'priority' => 1, 'is_active' => true]);
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

    /** (أ) رابط بمبنى: مبنى الملز يردّ 403 لحساب الدمام في الشاشة والواجهتين؛ ومبناه 200 */
    public function test_building_routes_refuse_a_building_outside_the_account(): void
    {
        $this->actingAs($this->farea);
        $m = $this->main->id; $d = $this->b->id;
        foreach ([
            "/app/emergency/buildings/$m", "/app/emergency/buildings/$m/control", "/app/emergency/visitors/$m",
            "/api/emergency/buildings/$m/visitors", "/api/emergency/buildings/$m/visitors/today", "/api/emergency/buildings/$m/visitors/evacuation-stats",
            "/api/emergency/buildings/$m/assembly-points", "/api/emergency/buildings/$m/map-data", "/api/emergency/buildings/$m/lockdown",
            "/api/iot/buildings/$m/status", "/api/iot/fire-panel/buildings/$m/zones", "/api/iot/access-control/buildings/$m/occupancy", "/api/iot/cameras/building/$m",
        ] as $url) {
            $this->get($url)->assertForbidden();
        }
        foreach (["/app/emergency/buildings/$d", "/app/emergency/buildings/$d/control", "/app/emergency/visitors/$d", "/api/emergency/buildings/$d/visitors", "/api/iot/buildings/$d/status", "/api/iot/cameras/building/$d"] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/app/emergency/buildings/999999')->assertNotFound();
    }

    /** (ب) الكائن الواحد: تصريح وفريق وتنبيه ذعر وحالة طارئة من الملز تردّ 403، ومن الدمام 200، وغير الموجود 404 */
    public function test_single_objects_of_another_branch_refuse_and_own_objects_open(): void
    {
        $this->actingAs($this->farea);
        foreach ([
            "/app/permits/{$this->pMlz->id}", "/app/permits/{$this->pMlz->id}/review",
            "/app/emergency/teams/{$this->tMlz->id}",
            "/app/emergency/panic/{$this->aMlz->id}", "/api/emergency/panic/{$this->aMlz->id}",
            "/app/emergency/incidents/{$this->iMlz->id}/live", "/app/emergency/incidents/{$this->iMlz->id}/report", "/api/emergency/incidents/{$this->iMlz->id}",
        ] as $url) {
            $this->get($url)->assertForbidden();
        }
        foreach ([
            "/app/permits/{$this->pDmm->id}", "/app/emergency/teams/{$this->tDmm->id}", "/app/emergency/panic/{$this->aDmm->id}",
            "/api/emergency/panic/{$this->aDmm->id}", "/app/emergency/incidents/{$this->iDmm->id}/live", "/api/emergency/incidents/{$this->iDmm->id}",
        ] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/app/emergency/teams/999999')->assertNotFound();
        $this->get('/app/permits/999999')->assertNotFound();
    }

    /** (ب) الواجهة: الذعر النشط يعيد تنبيهات مباني الحساب وحدها */
    public function test_active_panic_api_follows_the_account_buildings(): void
    {
        $this->actingAs($this->farea);
        $this->getJson('/api/emergency/panic/active')->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonFragment(['name' => 'اسم emp.b'])
            ->assertJsonMissing(['name' => 'اسم emp.m']); // كانت الواجهة تعيد تنبيه الملز لحساب الدمام
        $this->actingAs($this->salama);
        $this->getJson('/api/emergency/panic/active')->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonFragment(['name' => 'اسم emp.b'])
            ->assertJsonFragment(['name' => 'اسم emp.m']);
    }

    /** (ج) القوائم التي لم يشملها قرار ٨٢: سجل الحالات، التحليلات، الزوار والكشك، لوحة الأجهزة، لوحة التصاريح والبوابة، اقتراح الحسابات */
    public function test_remaining_lists_follow_the_account_buildings(): void
    {
        $this->actingAs($this->farea);
        $this->get('/app/emergency/incidents')->assertOk()->assertSee('ط-9002')->assertDontSee('ط-9001')->assertSee('HZ-01/DMM')->assertDontSee('HZ-01 —');
        $this->get('/app/emergency/analytics')->assertOk()->assertSee($this->b->name)->assertDontSee($this->main->name);
        $this->get('/app/emergency/visitors')->assertOk()->assertSee('زائر الدمام')->assertDontSee('زائر الملز')->assertDontSee($this->main->name);
        $this->get('/app/emergency/visitors/kiosk')->assertOk()->assertSee($this->b->name)->assertDontSee($this->main->name)->assertDontSee('HZ-01 —');
        $this->get('/app/emergency/iot')->assertOk()->assertSee($this->b->name)->assertDontSee($this->main->name);
        // لوحة التصاريح: سعات الأماكن (data-place) من مباني الحساب وحدها — كانت تقرأ Place كلها في PermitDashboardService::placeCapacities
        $this->get('/app/permits/dashboard')->assertOk()->assertSee('data-place="HZ-01/DMM"', false)->assertDontSee('data-place="HZ-01"', false);
        $this->get('/app/permits/gate')->assertOk()->assertSee('HZ-01/DMM')->assertDontSee('HZ-01 —');
        $this->get('/app/permits/gate/logs')->assertOk()->assertDontSee('HZ-01 —');
        $accounts = $this->get('/api/team-accounts')->assertOk()->getContent();
        $this->assertStringContainsString('"emp.b"', $accounts);
        $this->assertStringNotContainsString('"emp.m"', $accounts, 'اقتراح حسابات الفريق يعرض حسابات الملز لحساب الدمام');
        // ما بلا مبنى عامٌّ (قرار ٨٢): جهة الاتصال العامة تبقى، وجهة الملز لا
        $this->get('/app/emergency/contacts')->assertOk()->assertSee('جهة عامة ٩٩٨')->assertDontSee('جهة الملز');
    }

    /** المركز يرى الكل كما كان؛ وبلا مستخدم (الطابور والبذر) لا قيد */
    public function test_center_and_system_contexts_stay_unrestricted(): void
    {
        $this->actingAs($this->salama);
        foreach (["/app/emergency/buildings/{$this->main->id}", "/app/emergency/buildings/{$this->b->id}", "/app/permits/{$this->pMlz->id}", "/app/permits/{$this->pDmm->id}", "/app/emergency/panic/{$this->aMlz->id}", "/app/emergency/teams/{$this->tMlz->id}"] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/app/emergency/incidents')->assertOk()->assertSee('ط-9001')->assertSee('ط-9002');
        $this->get('/app/emergency/visitors')->assertOk()->assertSee('زائر الملز')->assertSee('زائر الدمام');
        $this->assertSame(2, EmergencyIncident::count());

        Auth::logout();
        $this->assertSame(2, EmergencyIncident::count(), 'بلا مستخدم يجب ألا يُقيَّد شيء');
        $this->assertSame(2, PanicAlert::count());
        $this->assertSame(2, Permit::count());
    }

    /** النطاق على النموذج نفسه: استعلام حساب الدمام لا يعيد صفوف الملز، ولو كُتب بلا أي شرط */
    public function test_model_queries_of_a_branch_account_never_return_another_building(): void
    {
        $this->actingAs($this->farea);
        $this->assertSame(['ط-9002'], EmergencyIncident::pluck('incident_code')->all());
        $this->assertSame(['فريق الدمام'], EmergencyTeam::pluck('name')->all());
        $this->assertSame(['P-DMM'], Permit::pluck('code')->all());
        $this->assertSame(['زائر الدمام'], EmergencyVisitor::pluck('name')->all());
        $this->assertNull(PanicAlert::find($this->aMlz->id));
        $this->assertNotNull(PanicAlert::find($this->aDmm->id));
        $this->assertSame(['جهة عامة ٩٩٨'], EmergencyContact::pluck('name')->all());
    }
}
