<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * المرحلة ٢٠-١ (قرار ٥١): المبنى في البيانات — الملز المبنى الرئيسي وله فرع، وكل مكان وكل حساب يتبع مبناه.
 * لا شاشة جديدة؛ التوسع لاحقاً إضافة سطر لا إعادة بناء.
 */
class BuildingTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_place_and_account_belongs_to_the_main_building(): void
    {
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class]);
        $main = EmergencyBuilding::main();
        $this->assertNotNull($main, 'لا مبنى رئيسي بعد الترحيل والبذر');
        $this->assertSame('IPA-MAIN', $main->code);
        $this->assertSame('الرياض', $main->branch);

        $this->assertSame(9, Place::count());
        $this->assertSame(9, Place::where('building_id', $main->id)->count(), 'مكان بلا مبنى');
        $this->assertSame('HZ-01', $main->places()->where('code', 'HZ-01')->value('code'));

        $u = User::create(['username' => 'fani', 'name' => 'الفني', 'password' => '1234']);
        $p = UserProfile::create(['user_id' => $u->id, 'role' => 'field_worker', 'is_active' => true, 'place_id' => Place::idByCode('HZ-01')]);
        $this->assertSame($main->id, $p->fresh()->building_id, 'الحساب الجديد بلا مبنى');
        $this->assertSame($main->id, $p->fresh()->myBuilding()?->id);

        // مكان يُنشأ بعد ذلك يتبع الرئيسي أيضاً
        $x = Place::create(['code' => 'HZ-09', 'name' => 'تجربة', 'sort' => 99]);
        $this->assertSame($main->id, $x->fresh()->building_id);
    }

    public function test_existing_rows_without_a_building_are_attached_to_the_main_building_on_migrate(): void
    {
        // بيانات قديمة (قبل ٢٠-١): مكان وحساب بلا مبنى — الترحيل يُلحقهما بالملز
        $this->seed(PlacesSeeder::class);
        DB::table('places')->update(['building_id' => null]);
        $u = User::create(['username' => 'old', 'name' => 'قديم', 'password' => '1234']);
        DB::table('user_profiles')->insert(['user_id' => $u->id, 'role' => 'employee', 'is_active' => true, 'building_id' => null, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('emergency_buildings')->update(['branch' => null]);

        $this->artisan('migrate:refresh', ['--path' => 'database/migrations/2026_09_20_100001_add_building_to_places_and_profiles.php'])->assertSuccessful();

        $main = EmergencyBuilding::main();
        $this->assertSame(0, Place::whereNull('building_id')->count());
        $this->assertSame($main->id, (int) DB::table('user_profiles')->where('user_id', $u->id)->value('building_id'));
        $this->assertSame('الرياض', $main->fresh()->branch);
        $this->assertSame(1, EmergencyBuilding::count(), 'الترحيل أنشأ مبنى ثانياً');
    }
}
