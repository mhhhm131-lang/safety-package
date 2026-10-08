<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Governance\Models\Place;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * المرحلة ٢٠-١ (قرار ٥١): ما يفعله الترحيل بالصفوف القديمة (قبل المبنى) — كان في BuildingTest.
 * ٢٨-١ (قرار ٧٨): ترحيل الفروع يقف على `building_id` (قيد صنف واحد لكل مبنى)، فالتراجع يشمل الترحيلين معاً،
 * وبلا معاملة (DatabaseMigrations) لأن إعادة بناء الجدول على SQLite تعطّل فحص المفاتيح الأجنبية بـ PRAGMA ولا يعمل داخل معاملة.
 * لا يُبذر الهيكل هنا: حذف جدول الهيكل وفيه صفوف يفشل على SQLite (مرجع الأب في الجدول نفسه) — ملاحظة في المؤجلات.
 */
class BuildingMigrateTest extends TestCase
{
    use DatabaseMigrations;

    private const MIGRATIONS = [
        'database/migrations/2026_09_20_100001_add_building_to_places_and_profiles.php',
        'database/migrations/2026_10_08_100004_add_branch_unit_place_category_and_document_building.php',
    ];

    public function test_existing_rows_without_a_building_are_attached_to_the_main_building_on_migrate(): void
    {
        // بيانات قديمة (قبل ٢٠-١): مكان وحساب بلا مبنى — الترحيل يُلحقهما بالملز
        $this->seed(PlacesSeeder::class);
        DB::table('places')->update(['building_id' => null]);
        $u = User::create(['username' => 'old', 'name' => 'قديم', 'password' => '1234']);
        DB::table('user_profiles')->insert(['user_id' => $u->id, 'role' => 'employee', 'is_active' => true, 'building_id' => null, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('emergency_buildings')->update(['branch' => null]);

        $this->artisan('migrate:refresh', ['--path' => self::MIGRATIONS])->assertSuccessful();

        $main = EmergencyBuilding::main();
        $this->assertSame(0, Place::whereNull('building_id')->count());
        $this->assertSame($main->id, (int) DB::table('user_profiles')->where('user_id', $u->id)->value('building_id'));
        $this->assertSame('الرياض', $main->fresh()->branch);
        $this->assertSame(1, EmergencyBuilding::count(), 'الترحيل أنشأ مبنى ثانياً');
        $this->assertSame(0, Place::whereNull('category')->count(), '٢٨-١: مكان بلا صنف بعد الترحيلين');
    }
}
