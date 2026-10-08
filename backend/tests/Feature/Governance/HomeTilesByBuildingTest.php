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
 * بكلمته «طبّقها الآن» (٢٠٢٦-١٠-٠٨): الصفحة الأولى لمن يرى أكثر من مبنى تجمع المربعات بالمبنى؛ مبنى الجلسة مفتوح والفروع مطوية.
 * مبنى واحد = كما كان (بلا عناوين).
 */
class HomeTilesByBuildingTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $username, string $role, ?EmergencyBuilding $building = null, ?Place $place = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234']);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'building_id' => $building?->id, 'place_id' => $place?->id]);
        return $u;
    }

    public function test_tiles_are_grouped_by_building_with_the_session_building_open_and_branches_collapsed(): void
    {
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
        $main = EmergencyBuilding::main();
        $branch = OrganizationUnit::create(['code' => 'dmm', 'name' => 'فرع الشرقية', 'unit_type' => 'region']);
        $b = EmergencyBuilding::create(['code' => 'DMM', 'name' => 'معهد الادارة فرع الدمام', 'branch' => 'فرع الشرقية', 'branch_unit_id' => $branch->id]);
        Place::createCategoriesFor($b);

        $salama = $this->user('salama', 'system_admin');
        $h = $this->actingAs($salama)->get(route('app.home'))->assertOk()->getContent();
        $this->assertSame(2, substr_count($h, 'data-tile-group="'), 'مجموعة لكل مبنى');
        $this->assertSame(18, substr_count($h, 'data-place="'), 'المربعات كلها موجودة داخل المجموعات');
        $this->assertStringContainsString('الأماكن في كل مبنى', $h);
        $this->assertStringContainsString('معهد الادارة فرع الدمام', $h);
        $this->assertStringContainsString('فرع الشرقية', $h);
        // الملز (مبنى الجلسة) مفتوح، والدمام مطوي
        $mainGrp = substr($h, strpos($h, 'data-tile-group="'.$main->id.'"'), strpos($h, 'data-tile-group="'.$b->id.'"') - strpos($h, 'data-tile-group="'.$main->id.'"'));
        $this->assertStringContainsString('class="collapse show" id="tiles-'.$main->id.'"', $mainGrp);
        $this->assertStringContainsString('data-place="HZ-01"', $mainGrp);
        $this->assertStringNotContainsString('data-place="HZ-01/DMM"', $mainGrp, 'مكان الدمام داخل مجموعة الملز');
        $dmmGrp = substr($h, strpos($h, 'data-tile-group="'.$b->id.'"'), 6000);
        $this->assertStringContainsString('class="collapse " id="tiles-'.$b->id.'"', $dmmGrp, 'فرع الدمام غير مطوي');
        $this->assertStringContainsString('data-place="HZ-01/DMM"', $dmmGrp);

        // بعد التبديل إلى الدمام يصير هو المفتوح
        $this->actingAs($salama)->post(route('app.building.switch', $b));
        $h = $this->actingAs($salama)->get(route('app.home'))->assertOk()->getContent();
        $this->assertStringContainsString('class="collapse show" id="tiles-'.$b->id.'"', $h);
        $this->assertStringContainsString('class="collapse " id="tiles-'.$main->id.'"', $h);

        // فني في الدمام: مبنى واحد ← مربعاته بلا عناوين كما كان
        $fani = $this->user('fani.b', 'field_worker', $b, Place::where('building_id', $b->id)->where('category', 'HZ-01')->first());
        $h = $this->actingAs($fani)->get(route('app.home'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-tile-group="', $h);
        $this->assertSame(1, substr_count($h, 'data-place="'));
    }
}
