<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Governance\Services\BuildingContext;
use App\Modules\Governance\Services\ScopeService;
use App\Modules\Incident\Models\Incident;
use App\Modules\Incident\Services\IncidentVisibilityService;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * قرار ٨١ (بكلمته ٢٠٢٦-١٠-١٠): منسق سلامة الفرع يرى فرعه كله «لأنه يشبه دور مسؤول السلامة في فرعه».
 * المعيار: منسق سلامة وحدته من نوع «فرع» (region) = نطاق مدير الفرع (مباني فرعه وبلاغاتها).
 * منسقو الإدارات (وحدتهم إدارة) في الملز والفروع كما هم: وحدتهم ومكانهم.
 */
class BranchCoordinatorScopeTest extends TestCase
{
    use RefreshDatabase;

    private EmergencyBuilding $main;
    private EmergencyBuilding $b;
    private OrganizationUnit $hq;
    private OrganizationUnit $hqFm;
    private OrganizationUnit $branchB;
    private OrganizationUnit $dmmOps;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
        $this->main = EmergencyBuilding::main();
        $this->hq = OrganizationUnit::create(['code' => 'hq', 'name' => 'المركز الرئيسي', 'unit_type' => 'company']);
        $this->main->update(['branch_unit_id' => $this->hq->id]);
        $this->hqFm = OrganizationUnit::create(['code' => 'hq-fm', 'name' => 'المرافق والصيانة', 'unit_type' => 'department', 'parent_id' => $this->hq->id, 'place_id' => Place::idByCode('HZ-06')]);

        // الفرع تحت المركز الرئيسي ونوعه «فرع» (كما على المنشور بعد الترحيلين 100007 و100008)
        $this->branchB = OrganizationUnit::create(['code' => 'br-dmm', 'name' => 'فرع الشرقية', 'unit_type' => 'region', 'parent_id' => $this->hq->id]);
        $this->b = EmergencyBuilding::create(['code' => 'DMM', 'name' => 'معهد الادارة فرع الدمام', 'branch' => 'فرع الشرقية', 'branch_unit_id' => $this->branchB->id]);
        Place::createCategoriesFor($this->b);
        $this->branchB->update(['place_id' => $this->bp('HZ-06')->id]);
        $this->dmmOps = OrganizationUnit::create(['code' => 'dmm-ops', 'name' => 'التشغيل', 'unit_type' => 'department', 'parent_id' => $this->branchB->id, 'place_id' => $this->bp('HZ-06')->id]);
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

    /** يوسف على المنشور: منسق سلامة، وحدته «فرع الشرقية»، مكانه مركز السلامة في الدمام */
    public function test_branch_coordinator_sees_all_places_and_incidents_of_his_branch(): void
    {
        $usf = $this->user('usf', 'safety_coordinator', $this->b, $this->bp('HZ-00'), $this->branchB);
        $codes = ScopeService::forUser($usf)->codes();
        $this->assertContains('HZ-01/DMM', $codes, 'منسق الفرع لا يرى القبو في فرعه');
        $this->assertContains('HZ-06/DMM', $codes);
        $this->assertCount(9, $codes, 'منسق الفرع يجب أن يرى أماكن مبنى فرعه التسعة');
        $this->assertNotContains('HZ-01', $codes, 'منسق الفرع يرى أماكن الملز');
        $this->assertSame([$this->b->id], BuildingContext::choices($usf)->pluck('id')->all());

        $v = app(IncidentVisibilityService::class);
        $inB = Incident::create(['code' => 'ش-0401', 'title' => 'ب', 'description' => 'بلاغ القبو في الشرقية', 'incident_type' => 'secret', 'status' => 'new', 'place_id' => $this->bp('HZ-01')->id]);
        $inHub = Incident::create(['code' => 'ش-0402', 'title' => 'م', 'description' => 'بلاغ مكاتب الشرقية', 'incident_type' => 'normal', 'status' => 'new', 'place_id' => $this->bp('HZ-06')->id]);
        $inM = Incident::create(['code' => 'ش-0403', 'title' => 'م', 'description' => 'بلاغ الملز', 'incident_type' => 'normal', 'status' => 'new', 'place_id' => Place::idByCode('HZ-01')]);
        $this->assertTrue($v->canView($inB, $usf->id), 'منسق الفرع لا يفتح بلاغ القبو في فرعه');
        $this->assertTrue($v->canView($inHub, $usf->id), 'منسق الفرع لا يفتح بلاغ مكاتب فرعه');
        $this->assertFalse($v->canView($inM, $usf->id), 'منسق الفرع يفتح بلاغ الملز');
        $this->assertSame(2, $v->getVisibleIncidents($usf->id)->count());
    }

    /** منسق إدارة (وحدته إدارة لا فرع) في الملز وفي الفرع: كما كان — وحدته ومكانه، لا المبنى كله */
    public function test_department_coordinators_keep_their_unit_scope(): void
    {
        $coordM = $this->user('coord.m', 'safety_coordinator', $this->main, Place::find(Place::idByCode('HZ-06')), $this->hqFm);
        $codes = ScopeService::forUser($coordM)->codes();
        $this->assertSame(['HZ-06'], $codes, 'منسق إدارة في الملز صار يرى غير مكانه');
        $this->assertFalse(BuildingContext::canSwitch($coordM));

        $coordB = $this->user('coord.b', 'safety_coordinator', $this->b, $this->bp('HZ-06'), $this->dmmOps);
        $this->assertSame(['HZ-06/DMM'], ScopeService::forUser($coordB)->codes(), 'منسق إدارة في الفرع صار يرى الفرع كله');

        $v = app(IncidentVisibilityService::class);
        $inB = Incident::create(['code' => 'ش-0501', 'title' => 'ب', 'description' => 'بلاغ القبو في الشرقية', 'incident_type' => 'normal', 'status' => 'new', 'place_id' => $this->bp('HZ-01')->id]);
        $inM = Incident::create(['code' => 'ش-0502', 'title' => 'م', 'description' => 'بلاغ القبو في الملز', 'incident_type' => 'normal', 'status' => 'new', 'place_id' => Place::idByCode('HZ-01')]);
        $this->assertFalse($v->canView($inB, $coordB->id), 'منسق إدارة التشغيل يرى بلاغ القبو');
        $this->assertFalse($v->canView($inM, $coordM->id), 'منسق إدارة في الملز يرى بلاغ القبو');
    }

    /** منسق سلامة بلا وحدة ولا مكان يرى الكل (قاعدة قائمة) — لا تتغير */
    public function test_coordinator_without_unit_and_place_still_sees_all(): void
    {
        $free = $this->user('coord.free', 'safety_coordinator');
        $this->assertTrue(ScopeService::forUser($free)->isAll() || ScopeService::forUser($free)->places()->isEmpty());
        $v = app(IncidentVisibilityService::class);
        $inM = Incident::create(['code' => 'ش-0601', 'title' => 'م', 'description' => 'بلاغ الملز', 'incident_type' => 'normal', 'status' => 'new', 'place_id' => Place::idByCode('HZ-01')]);
        $this->assertTrue($v->canView($inM, $free->id));
    }
}
