<?php

namespace Tests\Feature\Governance;

use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ٢٨-١ (قرار ٧٨): ما يفعله الترحيل بالصفوف القائمة على المنشور — الوثائق كلها للملز، وصنف كل مكان من رمزه،
 * والملز يُربط بـ«المركز الرئيسي» إن وُجد. بلا معاملة (DatabaseMigrations): إعادة بناء الجدول على SQLite
 * تعطّل فحص المفاتيح الأجنبية بـ PRAGMA، وهو لا يعمل داخل معاملة.
 */
class BranchesStep1MigrateTest extends TestCase
{
    use DatabaseMigrations;

    private const MIGRATION = 'database/migrations/2026_10_08_100004_add_branch_unit_place_category_and_document_building.php';

    public function test_existing_rows_are_attached_on_migrate(): void
    {
        $this->seed(PlacesSeeder::class);
        DB::table('institute_documents')->insert(['key' => 'ipa-office-form-v10', 'data' => '{}', 'version' => 3, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('places')->update(['category' => null]);

        $this->artisan('migrate:refresh', ['--path' => self::MIGRATION])->assertSuccessful();

        $main = EmergencyBuilding::main();
        $this->assertSame($main->id, (int) DB::table('institute_documents')->where('key', 'ipa-office-form-v10')->value('building_id'));
        $this->assertSame(0, Place::whereNull('category')->count(), 'مكان بلا صنف بعد الترحيل');
        $this->assertSame('HZ-06', Place::find(Place::idByCode('HZ-06'))->category);
        $this->assertSame(9, Place::count(), 'الترحيل أضاع مكاناً');
        $this->assertSame(1, EmergencyBuilding::count(), 'الترحيل أنشأ مبنى ثانياً');
    }

    public function test_main_building_is_linked_to_headquarters_unit_when_it_exists(): void
    {
        $this->seed(PlacesSeeder::class);
        $root = OrganizationUnit::create(['code' => 'hq', 'name' => 'المركز الرئيسي', 'unit_type' => 'company']);
        $this->assertNull(EmergencyBuilding::main()->branch_unit_id, 'رُبط قبل الترحيل');

        $this->artisan('migrate:refresh', ['--path' => self::MIGRATION])->assertSuccessful();

        $main = EmergencyBuilding::main()->fresh();
        $this->assertSame($root->id, $main->branch_unit_id, 'الملز لم يُربط بالمركز الرئيسي');
        $this->assertSame('المركز الرئيسي', $main->branchUnit?->name);
        $this->assertSame('الرياض', $main->branch, 'نص الفرع القديم ضاع');
    }
}
