<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Governance\Services\ScopeService;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مرحلة الفروع — الخطوة ٢ (قرار ٧٨): شاشة المباني تزيد «الفرع» من رؤوس الهيكل، وزر «أماكن المبنى» ينشئ الأصناف التسعة
 * بضغطة برموز `HZ-06/<رمز المبنى>`؛ الصنف الغائب يُعطَّل لا يُحذف؛ أماكن الملز كما هي. الأماكن وQR وصفحة البلاغ بالمباني.
 */
class BranchesStep2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
    }

    private function admin(): User
    {
        $u = User::create(['username' => 'salama', 'name' => 'مسؤول السلامة', 'password' => '1234']);
        UserProfile::create(['user_id' => $u->id, 'role' => 'system_admin', 'is_active' => true]);
        return $u;
    }

    private function branchUnit(): OrganizationUnit
    {
        return OrganizationUnit::create(['code' => 'dmm', 'name' => 'فرع الشرقية', 'unit_type' => 'branch']);
    }

    private function building(?string $code = 'DMM'): EmergencyBuilding
    {
        return EmergencyBuilding::create(['code' => $code, 'name' => 'فرع الشرقية — المبنى الرئيسي', 'branch' => 'الشرقية']);
    }

    public function test_building_form_offers_the_branch_from_structure_roots_and_saves_it(): void
    {
        $unit = $this->branchUnit();
        $this->actingAs($this->admin());
        $this->get(route('emergency.buildings.create'))->assertOk()->assertSee('فرع الشرقية')->assertSee('نائب المدير العام للتدريب');
        $this->post(route('emergency.buildings.store'), [
            'name' => 'فرع الشرقية — المبنى الرئيسي', 'code' => 'DMM', 'building_type' => 'government', 'floors_count' => 2,
            'risk_level' => 'medium', 'branch_unit_id' => $unit->id,
        ])->assertRedirect();
        $b = EmergencyBuilding::where('code', 'DMM')->first();
        $this->assertNotNull($b);
        $this->assertSame($unit->id, $b->branch_unit_id, 'المبنى لم يُربط بوحدة الفرع');
        $this->assertSame('فرع الشرقية', $b->branch, 'النص لم يتبع الوحدة');
        $this->get(route('emergency.buildings.edit', $b))->assertOk()->assertSee('فرع الشرقية');
        $this->get(route('emergency.buildings.index'))->assertOk()->assertSee('فرع الشرقية');
    }

    public function test_one_press_creates_the_nine_categories_with_building_codes_and_malaz_is_untouched(): void
    {
        $b = $this->building();
        $this->actingAs($this->admin());
        $this->get(route('emergency.buildings.show', $b))->assertOk()->assertSee('غير منشأ');
        $this->post(route('emergency.buildings.places.store', $b))->assertRedirect()->assertSessionHas('success');

        $this->assertSame(9, $b->places()->count());
        $office = Place::where('code', 'HZ-06/DMM')->first();
        $this->assertNotNull($office, 'رمز مكان الفرع ليس صنفه/رمز مبناه');
        $this->assertSame('HZ-06', $office->category);
        $this->assertSame('المكاتب الإدارية', $office->name);
        $this->assertSame($b->id, $office->building_id);
        $this->assertSame(18, Place::count());
        $main = EmergencyBuilding::main();
        $this->assertSame(9, Place::where('building_id', $main->id)->count());
        $this->assertSame($main->id, Place::find(Place::idByCode('HZ-06'))->building_id, 'مكان الملز انتقل');

        // الضغطة الثانية لا تكرر
        $this->post(route('emergency.buildings.places.store', $b))->assertRedirect();
        $this->assertSame(18, Place::count());
        $this->get(route('emergency.buildings.show', $b))->assertOk()->assertSee('HZ-06/DMM')->assertDontSee('غير منشأ');
    }

    public function test_building_without_a_code_cannot_get_places(): void
    {
        $b = $this->building(null);
        $this->actingAs($this->admin());
        $this->post(route('emergency.buildings.places.store', $b))->assertRedirect()->assertSessionHas('error');
        $this->assertSame(0, $b->places()->count());
        $this->assertSame(9, Place::count());
    }

    public function test_absent_category_is_deactivated_not_deleted_and_malaz_places_stay(): void
    {
        $b = $this->building();
        Place::createCategoriesFor($b);
        $dc = Place::where('code', 'HZ-04/DMM')->firstOrFail();
        $admin = $this->admin();
        $this->actingAs($admin);

        $this->put(route('app.places.active', $dc))->assertRedirect()->assertSessionHas('ok');
        $this->assertFalse($dc->fresh()->is_active);
        $this->assertSame(18, Place::count(), 'التعطيل حذف');

        $codes = ScopeService::forUser($admin)->codes();
        $this->assertNotContains('HZ-04/DMM', $codes, 'المعطَّل ما زال في النطاق');
        $this->assertContains('HZ-04', $codes);
        $this->assertContains('HZ-06/DMM', $codes);

        $this->get(route('incident.form', 'secret'))->assertOk()->assertSee('HZ-06/DMM')->assertDontSee('HZ-04/DMM');
        $this->get(route('app.places.qr'))->assertOk()->assertSee('HZ-06/DMM')->assertDontSee('HZ-04/DMM');

        // الملز ثابت
        $mainDc = Place::find(Place::idByCode('HZ-04'));
        $this->put(route('app.places.active', $mainDc))->assertRedirect()->assertSessionHas('error');
        $this->assertTrue($mainDc->fresh()->is_active);

        // الإعادة
        $this->put(route('app.places.active', $dc))->assertRedirect();
        $this->assertTrue($dc->fresh()->is_active);
    }

    public function test_places_screen_groups_by_building_and_qr_works_by_id(): void
    {
        $b = $this->building();
        Place::createCategoriesFor($b);
        $this->actingAs($this->admin());
        $html = $this->get(route('app.places.index'))->assertOk()->getContent();
        $this->assertStringContainsString('HZ-06/DMM', $html);
        $this->assertStringContainsString($b->name, $html);
        $this->assertStringContainsString(EmergencyBuilding::main()->name, $html);
        $this->assertStringContainsString('تعطيل', $html);

        $office = Place::where('code', 'HZ-06/DMM')->firstOrFail();
        $this->get(route('app.places.qr.one', $office))->assertOk()->assertSee('incident?place=HZ-06/DMM');
        $this->assertSame('/app/places/'.$office->id.'/qr', $office->links()[array_key_last($office->links())][1]);
        $this->assertSame('/HZ-06-offices/safety-plan.html', $office->links()[1][1], 'وثائق الصنف لا تصل مكان الفرع');
    }
}
