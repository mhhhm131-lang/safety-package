<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * قرار ٨٣ (بكلمته ٢٠٢٦-١٠-١٠ «لا يرى فرع آخر في أي شيء»): ملف المكان وملف نظامه ووحداته تُفتح لمن المكان في مبانيه.
 * كان الملف يُفتح لأي حساب (قرار ٤٨ يوم كان المبنى واحداً) فرأى حساب الدمام بلاغات فحص الملز وبلاغات شاغليه.
 */
class BranchPlaceFileScopeTest extends TestCase
{
    use RefreshDatabase;

    private EmergencyBuilding $main;
    private EmergencyBuilding $b;
    private OrganizationUnit $branchB;
    private OrganizationUnit $dmmOps;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
        $this->main = EmergencyBuilding::main();
        Place::whereNull('building_id')->update(['building_id' => $this->main->id]);
        $hq = OrganizationUnit::create(['code' => 'hq', 'name' => 'المركز الرئيسي', 'unit_type' => 'company']);
        $this->main->update(['branch_unit_id' => $hq->id]);
        $this->branchB = OrganizationUnit::create(['code' => 'br-dmm', 'name' => 'فرع الشرقية', 'unit_type' => 'region', 'parent_id' => $hq->id]);
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

    public function test_branch_accounts_open_place_files_of_their_building_only(): void
    {
        $farea = $this->user('farea', 'branch_manager', $this->b, null, $this->branchB);
        $nasr = $this->user('nasr', 'department_manager', $this->b, $this->bp('HZ-06'), $this->dmmOps);
        $malazParking = Place::idByCode('HZ-01');
        $dmmParking = $this->bp('HZ-01')->id;

        foreach ([$farea, $nasr] as $u) {
            $this->actingAs($u);
            $this->get("/app/places/{$dmmParking}/file")->assertOk();                 // مكان في مبناه ولو لم يكن مكانه
            $this->get("/app/places/{$malazParking}/file")->assertForbidden();         // مكان في الملز
            $this->get("/app/places/{$malazParking}/systems/ipa-park-form-v10/p01")->assertForbidden();
            $this->get("/app/places/{$malazParking}/units")->assertForbidden();
        }
    }

    public function test_center_and_sees_all_accounts_still_open_every_place_file(): void
    {
        $salama = $this->user('salama', 'system_admin');
        $this->actingAs($salama);
        $this->get('/app/places/'.Place::idByCode('HZ-01').'/file')->assertOk();
        $this->get('/app/places/'.$this->bp('HZ-01')->id.'/file')->assertOk();

        // موظف في الملز بلا مبنى في حسابه: مبناه مبنى مكانه (الملز) — يفتح ملفات الملز لا الدمام
        $emp = $this->user('emp.m', 'employee', null, Place::find(Place::idByCode('HZ-06')));
        $this->actingAs($emp);
        $this->get('/app/places/'.Place::idByCode('HZ-01').'/file')->assertOk();
        $this->get('/app/places/'.$this->bp('HZ-01')->id.'/file')->assertForbidden();
    }
}
