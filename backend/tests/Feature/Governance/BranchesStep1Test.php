<?php

namespace Tests\Feature\Governance;

use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Store\Models\InstituteDocument;
use Database\Seeders\PlacesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مرحلة الفروع — الخطوة ١ (قرار ٧٨، معتمدة بكلمته «تماماً ابدأ» ٢٠٢٦-١٠-٠٨، `backend/docs/plan-branches-2026-10-08.html`):
 * الفرع وحدة في الهيكل يشير إليها المبنى؛ المكان يحمل صنفه (٨+١) خانةً مستقلة والملز يبقى برموزه؛
 * والوثيقة التشغيلية تحمل مبناها. لا شاشة في هذه الخطوة.
 */
class BranchesStep1Test extends TestCase
{
    use RefreshDatabase;

    private function secondBuilding(): EmergencyBuilding
    {
        return EmergencyBuilding::create(['code' => 'IPA-DMM', 'name' => 'فرع الشرقية — المبنى الرئيسي', 'branch' => 'الشرقية']);
    }

    public function test_seeded_places_carry_their_category_and_malaz_codes_do_not_change(): void
    {
        $this->seed(PlacesSeeder::class);
        $this->assertSame(9, Place::count());
        foreach (Place::all() as $p) {
            $this->assertSame($p->code, $p->category, "صنف {$p->code} ليس رمزه");
        }
        $this->assertSame('HZ-06', Place::find(Place::idByCode('HZ-06'))?->code, 'رمز الملز تغيّر');
    }

    public function test_same_category_in_two_buildings_is_two_places_and_twice_in_one_building_is_refused(): void
    {
        $this->seed(PlacesSeeder::class);
        $main = EmergencyBuilding::main();
        $b = $this->secondBuilding();

        $p = Place::create(['code' => 'HZ-06/DMM', 'category' => 'HZ-06', 'name' => 'المكاتب الإدارية', 'sort' => 6, 'building_id' => $b->id]);
        $this->assertNotSame(Place::idByCode('HZ-06'), $p->id);
        $this->assertSame(2, Place::where('category', 'HZ-06')->count());
        $this->assertSame($main->id, Place::find(Place::idByCode('HZ-06'))->building_id, 'مكان الملز انتقل');
        $this->assertSame('HZ-06/DMM', $p->fresh()->code, 'الرمز لا يتسع للصيغة الجديدة');
        $this->assertSame($b->id, $p->fresh()->building_id);

        $this->expectException(QueryException::class);
        Place::create(['code' => 'HZ-06/DMM-2', 'category' => 'HZ-06', 'name' => 'مكرر', 'sort' => 7, 'building_id' => $b->id]);
    }

    public function test_place_created_by_code_alone_takes_the_category_from_its_code(): void
    {
        // الكود القائم (الاختبارات والبذور) ينشئ المكان برمزه وحده: صنفه ما قبل «/» في رمزه
        $x = Place::create(['code' => 'HZ-09', 'name' => 'تجربة', 'sort' => 99]);
        $this->assertSame('HZ-09', $x->fresh()->category);
        $b = $this->secondBuilding();
        $y = Place::create(['code' => 'HZ-01/DMM', 'name' => 'القبو', 'sort' => 1, 'building_id' => $b->id]);
        $this->assertSame('HZ-01', $y->fresh()->category);
    }

    public function test_building_points_to_its_branch_unit_in_the_structure(): void
    {
        $branch = OrganizationUnit::create(['code' => 'dmm', 'name' => 'فرع الشرقية', 'unit_type' => 'branch']);
        $b = $this->secondBuilding();
        $b->update(['branch_unit_id' => $branch->id]);
        $this->assertSame('فرع الشرقية', $b->fresh()->branchUnit?->name);
        $this->assertSame('الشرقية', $b->fresh()->branch, 'النص يبقى للعرض');
        // ربط الملز بـ«المركز الرئيسي» في الترحيل: BranchesStep1MigrateTest
    }

    public function test_documents_carry_their_building_and_the_same_key_lives_once_per_building(): void
    {
        $main = EmergencyBuilding::mainOrCreate();
        $b = $this->secondBuilding();
        $d1 = InstituteDocument::create(['key' => 'ipa-park-form-v10', 'data' => '{}', 'version' => 1]);
        $this->assertSame($main->id, $d1->fresh()->building_id, 'الوثيقة بلا مبنى لا تتبع الملز');
        $d2 = InstituteDocument::create(['key' => 'ipa-park-form-v10', 'data' => '{}', 'version' => 1, 'building_id' => $b->id]);
        $this->assertSame(2, InstituteDocument::where('key', 'ipa-park-form-v10')->count());
        $this->assertNotSame($d1->id, $d2->id);

        $this->expectException(QueryException::class);
        InstituteDocument::create(['key' => 'ipa-park-form-v10', 'data' => '{}', 'version' => 1, 'building_id' => $b->id]);
    }

}
