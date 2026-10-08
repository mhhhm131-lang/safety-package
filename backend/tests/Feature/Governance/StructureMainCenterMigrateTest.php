<?php

namespace Tests\Feature\Governance;

use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Services\DeptSync;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * بكلمته «ابدأ» (٢٠٢٦-١٠-٠٨): الملز هو «المركز الرئيسي» والفروع الأربعة تحته — ترحيل الهيكل يعمل مرة واحدة
 * على الهيكل القائم (المبذور وما أضافه المستخدم من الشاشة) ولا يكرر شيئاً إن أُعيد.
 */
class StructureMainCenterMigrateTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_08_100007_structure_main_center_and_branches.php';

    /** كُشف على المنشور: وحدة الفرع كانت على مكاتب الملز فظهرت مكاتب الملز لمنسق الفرع — مكانها مكاتب مبناها، وبلا مبنى فارغ */
    public function test_branch_unit_place_is_its_building_offices_or_empty(): void
    {
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
        $this->artisan('migrate:refresh', ['--path' => 'database/migrations/2026_10_08_100007_structure_main_center_and_branches.php'])->assertSuccessful();
        $dmm = OrganizationUnit::where('code', 'br-dmm')->firstOrFail();
        $this->assertSame(\App\Modules\Governance\Models\Place::idByCode('HZ-06'), $dmm->place_id, 'الحالة قبل الإصلاح: مكاتب الملز');
        $b = EmergencyBuilding::create(['code' => 'DMM', 'name' => 'فرع الدمام', 'branch' => 'فرع الشرقية', 'branch_unit_id' => $dmm->id]);
        \App\Modules\Governance\Models\Place::createCategoriesFor($b);

        $this->artisan('migrate:refresh', ['--path' => 'database/migrations/2026_10_08_100011_branch_units_place_is_their_building_offices.php'])->assertSuccessful();
        $this->assertSame(\App\Modules\Governance\Models\Place::where('building_id', $b->id)->where('category', 'HZ-06')->value('id'), $dmm->fresh()->place_id, 'مكان الفرع ليس مكاتب مبناه');
        $this->assertNull(OrganizationUnit::where('code', 'br-mka')->value('place_id'), 'فرع بلا مبنى بقي على مكاتب الملز');

        // منسق الفرع: نطاقه مبناه، لا مكاتب الملز
        $u = \App\Models\User::create(['username' => 'usf', 'name' => 'منسق', 'password' => '1234']);
        \App\Modules\Governance\Models\UserProfile::create(['user_id' => $u->id, 'role' => 'safety_coordinator', 'is_active' => true, 'organization_unit_id' => $dmm->id, 'building_id' => $b->id, 'place_id' => \App\Modules\Governance\Models\Place::where('building_id', $b->id)->where('category', 'HZ-00')->value('id')]);
        $codes = \App\Modules\Governance\Services\ScopeService::forUser($u)->codes();
        $this->assertNotContains('HZ-06', $codes, 'منسق الفرع يرى مكاتب الملز');
        $this->assertContains('HZ-06/DMM', $codes);
        // ورأس الفرع ليس إدارة في قائمة وحدات مكاتب مبناه
        $list = \App\Modules\Emergency\Services\PlaceProfile::unitList('HZ-06', [], $b->id);
        $this->assertNotContains('br-dmm', array_column($list, 'dept'));
    }

    public function test_main_center_becomes_the_single_root_with_the_four_branches_under_it(): void
    {
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
        // ما أضافه المستخدم من الشاشة على المنشور
        $fm = OrganizationUnit::create(['code' => 'uuf6npl', 'name' => 'ادارة المرافق والصيانة', 'unit_type' => 'department', 'parent_id' => OrganizationUnit::where('code', 'adm-eng')->value('id')]);
        $roots = OrganizationUnit::whereNull('parent_id')->pluck('code')->all();
        $this->assertCount(10, $roots);

        $this->artisan('migrate:refresh', ['--path' => self::MIGRATION])->assertSuccessful();

        $hq = OrganizationUnit::where('name', 'المركز الرئيسي')->first();
        $this->assertNotNull($hq);
        $this->assertNull($hq->parent_id);
        $this->assertSame([$hq->id], OrganizationUnit::whereNull('parent_id')->pluck('id')->all(), 'بقي رأس غير المركز الرئيسي');
        $this->assertSame(10, OrganizationUnit::where('parent_id', $hq->id)->whereIn('code', $roots)->count(), 'الرؤوس العشرة لم تنتقل تحت المركز الرئيسي');
        $branches = OrganizationUnit::where('parent_id', $hq->id)->where('unit_type', 'region')->whereIn('name', ['فرع الرياض', 'فرع الشرقية', 'فرع مكة', 'فرع عسير'])->pluck('name')->all();
        $this->assertCount(4, $branches);
        $this->assertSame($fm->id, OrganizationUnit::where('code', 'uuf6npl')->value('id'));
        $this->assertSame(OrganizationUnit::where('code', 'adm-eng')->value('id'), $fm->fresh()->parent_id, 'ما أضافه المستخدم مُسّ');
        $this->assertSame($hq->id, EmergencyBuilding::main()->fresh()->branch_unit_id, 'الملز لم يُربط بالمركز الرئيسي');
        $this->assertSame(32 + 1 + 1 + 4, OrganizationUnit::count());

        // إعادة التشغيل لا تكرر
        $this->artisan('migrate:refresh', ['--path' => self::MIGRATION])->assertSuccessful();
        $this->assertSame(38, OrganizationUnit::count());
        $this->assertSame(1, OrganizationUnit::where('name', 'المركز الرئيسي')->count());

        // لوحة الملز ترى الهيكل كله ما دام لا مبنى لفرع؛ والرأس بلا أب في وثيقتها
        $doc = app(DeptSync::class)->toDocument(EmergencyBuilding::main()->id);
        $this->assertSame('', collect($doc)->firstWhere('id', 'hq')['parent']);
        $this->assertSame('hq', collect($doc)->firstWhere('id', 'gm')['parent']);
        $this->assertContains('فرع الشرقية', array_column($doc, 'name'));
    }
}
