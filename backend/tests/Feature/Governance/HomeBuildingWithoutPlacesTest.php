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
 * ٣٢-٢ (قرار ٩١، بكلمته «اضفها في الشاشة وأنا أضيف الأماكن لاحقاً» ٢٠٢٦-١٠-١٧):
 * ترحيل يُنشئ مبنى فرع الرياض مرة واحدة مربوطاً بفرعه في الهيكل وبلا أماكن؛ والصفحة الأولى تعرض زره لمن يرى الكل
 * وشبكته رسالة «لم تُنشأ أماكنه بعد» وباب ملف المبنى، ورسمه أصفار. مدير فرع آخر لا يراه.
 */
class HomeBuildingWithoutPlacesTest extends TestCase
{
    use RefreshDatabase;

    private const STRUCTURE = 'database/migrations/2026_10_08_100007_structure_main_center_and_branches.php';
    private const MIGRATION = 'database/migrations/2026_10_17_100001_add_riyadh_branch_building.php';

    private function user(string $username, string $role, ?EmergencyBuilding $building = null, ?Place $place = null, ?OrganizationUnit $unit = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234']);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'building_id' => $building?->id, 'place_id' => $place?->id, 'organization_unit_id' => $unit?->id]);
        return $u;
    }

    public function test_migration_creates_the_riyadh_building_once_and_never_over_an_existing_one(): void
    {
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
        $this->artisan('migrate:refresh', ['--path' => self::STRUCTURE])->assertSuccessful();
        $unit = OrganizationUnit::where('code', 'br-ryd')->firstOrFail();
        $this->assertSame(1, EmergencyBuilding::count(), 'قبل الترحيل: الملز وحده');

        $this->artisan('migrate:refresh', ['--path' => self::MIGRATION])->assertSuccessful();
        $b = EmergencyBuilding::where('code', 'RYD')->first();
        $this->assertNotNull($b, 'لم يُنشأ مبنى فرع الرياض');
        $this->assertSame($unit->id, $b->branch_unit_id, 'المبنى غير مربوط بفرع الرياض');
        $this->assertSame('فرع الرياض', $b->branch);
        $this->assertSame('معهد الادارة العامة فرع الرياض', $b->name);
        $this->assertSame(0, Place::where('building_id', $b->id)->count(), 'أُنشئت أماكن — أماكنه بيده');
        $this->assertSame(2, EmergencyBuilding::count());

        // مرة ثانية: لا تكرار
        $this->artisan('migrate:refresh', ['--path' => self::MIGRATION])->assertSuccessful();
        $this->assertSame(2, EmergencyBuilding::count(), 'الترحيل كرّر المبنى');

        // مبنى أضافه المستخدم من الشاشة لفرع الرياض برمز آخر: الترحيل لا يضيف فوقه
        $b->delete();
        $mine = EmergencyBuilding::create(['code' => 'RUH-1', 'name' => 'مبنى الفرع — الرياض', 'branch' => 'فرع الرياض', 'branch_unit_id' => $unit->id]);
        $this->artisan('migrate:refresh', ['--path' => self::MIGRATION])->assertSuccessful();
        $this->assertSame(2, EmergencyBuilding::count(), 'الترحيل أضاف مبنى فوق ما أضافه المستخدم');
        $this->assertNull(EmergencyBuilding::where('code', 'RYD')->first());
        $this->assertNotNull(EmergencyBuilding::find($mine->id));
    }

    public function test_home_shows_a_building_without_places_as_a_tab_with_a_door_to_its_file(): void
    {
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
        $main = EmergencyBuilding::main();
        $hq = OrganizationUnit::create(['code' => 'hq', 'name' => 'المركز الرئيسي', 'unit_type' => 'company']);
        $main->update(['branch_unit_id' => $hq->id]);
        $dmmU = OrganizationUnit::create(['code' => 'br-dmm', 'name' => 'فرع الشرقية', 'unit_type' => 'region', 'parent_id' => $hq->id]);
        $rydU = OrganizationUnit::create(['code' => 'br-ryd', 'name' => 'فرع الرياض', 'unit_type' => 'region', 'parent_id' => $hq->id]);
        $dmm = EmergencyBuilding::create(['code' => 'DMM', 'name' => 'معهد الادارة فرع الدمام', 'branch' => 'فرع الشرقية', 'branch_unit_id' => $dmmU->id]);
        Place::createCategoriesFor($dmm);
        $ryd = EmergencyBuilding::create(['code' => 'RYD', 'name' => 'معهد الادارة العامة فرع الرياض', 'branch' => 'فرع الرياض', 'branch_unit_id' => $rydU->id]);

        // مسؤول السلامة: ثلاثة أزرار، والرياض شبكته رسالة وباب ملفه، ورسمه أصفار؛ المربعات ١٨ كما هي
        $salama = $this->user('salama', 'system_admin');
        $h = $this->actingAs($salama)->get(route('app.home'))->assertOk()->getContent();
        $this->assertSame(3, substr_count($h, 'role="tab" data-bld="'), 'زر لكل مبنى ومنها الذي بلا أماكن');
        $this->assertSame(18, substr_count($h, 'data-place="'));
        $this->assertStringContainsString('data-bld="'.$ryd->id.'" aria-selected="false"', $h);
        $this->assertStringContainsString('بلا أماكن بعد', $h);
        $this->assertStringContainsString('class="pl-empty" data-tile-group="'.$ryd->id.'" id="tiles-'.$ryd->id.'" role="tabpanel" hidden>', $h, 'شبكة الرياض ليست رسالة مخفية');
        $this->assertStringContainsString('لم تُنشأ أماكن هذا المبنى بعد', $h);
        $this->assertStringContainsString(route('emergency.buildings.show', $ryd), $h, 'لا باب إلى ملف المبنى');
        preg_match('~<script type="application/json" id="snapshot">(.*?)</script>~s', $h, $m);
        $D = json_decode($m[1], true);
        $this->assertSame(0, $D['buildings'][$ryd->id]['total']['incidents'], 'رسم الرياض ليس أصفاراً');
        $this->assertSame('معهد الادارة العامة فرع الرياض', $D['buildings'][$ryd->id]['name']);
        $this->assertSame($main->id, $D['sel']);

        // بعد التبديل إلى الرياض: زره مختار ورسالته ظاهرة والرسم يبدأ بأصفاره
        $this->actingAs($salama)->post(route('app.building.switch', $ryd));
        $h = $this->actingAs($salama)->get(route('app.home'))->assertOk()->getContent();
        $this->assertStringContainsString('data-bld="'.$ryd->id.'" aria-selected="true"', $h);
        $this->assertStringContainsString('class="pl-empty" data-tile-group="'.$ryd->id.'" id="tiles-'.$ryd->id.'" role="tabpanel">', $h);
        $this->assertStringContainsString('id="chartScope">'.e($ryd->name).'</span>', $h);
        $this->assertStringContainsString('data-k="incidents" data-n="0"', $h);

        // مدير فرع الدمام: لا يرى الرياض
        $mgr = $this->user('mgr.dmm', 'branch_manager', $dmm, null, $dmmU);
        $h = $this->actingAs($mgr)->get(route('app.home'))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="bldTabs"', $h);
        $this->assertStringNotContainsString('فرع الرياض', $h);
    }
}
