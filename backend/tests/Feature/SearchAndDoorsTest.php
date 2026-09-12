<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Incident\Models\Incident;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use Database\Seeders\AffectedGroupsSeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ١١-٤ (قرار ٣٤): الأبواب الثلاثة — ما ينتظرك · بحث · الإعدادات — والقائمة خلف «المزيد».
 */
class SearchAndDoorsTest extends TestCase
{
    use RefreshDatabase;

    private User $salama; private User $fani; private User $emp; private User $mudir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class]);
        $this->salama = $this->user('salama', 'system_admin');
        $this->fani = $this->user('fani', 'field_worker', 'HZ-06');
        $this->emp = $this->user('emp', 'employee');
        $this->mudir = $this->user('mudir', 'department_manager', null, OrganizationUnit::first()->id);
    }

    private function user(string $username, string $role, ?string $placeCode = null, ?int $unitId = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'place_id' => Place::idByCode($placeCode), 'organization_unit_id' => $unitId]);
        return $u;
    }

    public function test_layout_has_three_doors_and_menu_is_behind_more(): void
    {
        $h = $this->actingAs($this->salama)->get('/app')->assertOk()->getContent();
        $this->assertStringContainsString('id="navInbox"', $h);
        $this->assertStringContainsString('id="navSearch"', $h);
        $this->assertStringContainsString('id="navMore"', $h);
        $this->assertStringContainsString('class="offcanvas offcanvas-end side"', $h);
        $this->assertStringContainsString('href="'.url('/app/settings').'"', $h);
        $this->assertStringNotContainsString('col-md-2 py-3 side', $h); // لا عمود ثابت يتكدس فوق المحتوى
        // الموظف: لا «الإعدادات»
        $this->actingAs($this->emp)->get('/app')->assertOk()->assertDontSee('href="'.url('/app/settings').'"', false);
    }

    public function test_search_fans_out_by_permission_and_codes_open_records_directly(): void
    {
        $this->post('/incident/normal', ['description' => 'سلك كهربائي مكشوف قرب الطابعة', 'place_id' => Place::idByCode('HZ-06')]);
        $i = Incident::first();
        $cat = RiskCategory::create(['name' => 'الكهربائية', 'abbreviation' => 'EL', 'created_at' => now()]);
        $risk = Risk::create(['risk_type' => 'reference', 'code' => 'EL-01-01', 'title' => 'صعق كهربائي من سلك مكشوف', 'description' => 'x', 'category_id' => $cat->id, 'severity' => 4, 'likelihood' => 2, 'status' => 'approved']);

        // المركز يجد البلاغ والخطر والمكان
        $r = $this->actingAs($this->salama)->get('/app/search?q=مكشوف')->assertOk();
        $r->assertSee('data-group="بلاغات الشاغلين"', false)->assertSee($i->code)->assertSee('data-group="المخاطر"', false)->assertSee('صعق كهربائي');
        $this->actingAs($this->salama)->get('/app/search?q=المكاتب')->assertOk()->assertSee('data-group="الأماكن"', false)->assertSee('/dashboard.html#place=HZ-06');
        // الفني: بلاغ مكانه نعم، المخاطر لا (بلا risk.list)
        $this->actingAs($this->fani)->get('/app/search?q=مكشوف')->assertOk()->assertSee($i->code)->assertDontSee('data-group="المخاطر"', false);
        // الموظف: لا شيء
        $this->actingAs($this->emp)->get('/app/search?q=مكشوف')->assertOk()->assertSee('لا نتائج');
        // الاختصارات
        $this->actingAs($this->salama)->get('/app/search?q='.$i->code)->assertRedirect("/app/incidents/{$i->id}");
        $this->actingAs($this->salama)->get('/app/search?q=hz-06')->assertRedirect('/dashboard.html#place=HZ-06');
        $this->actingAs($this->emp)->get('/app/search?q='.$i->code)->assertOk(); // لا يراه فلا يُحوَّل
        // فارغ
        $this->actingAs($this->salama)->get('/app/search')->assertOk()->assertDontSee('لا نتائج');
    }

    public function test_settings_page_groups_setup_screens_for_safety_officer_only(): void
    {
        $r = $this->actingAs($this->salama)->get('/app/settings')->assertOk();
        foreach (['الناس والهيكل', 'الأماكن والمبنى', 'المهل والقواعد', 'الأنظمة والربط', 'النماذج والمحتوى', 'الصيانة'] as $g) {
            $r->assertSee('data-settings-group="'.$g.'"', false);
        }
        $r->assertSee(url('/app/users'))->assertSee(url('/app/incidents/settings'))->assertSee(url('/app/emergency/iot/devices'))->assertSee(url('/app/closeout'));
        $this->actingAs($this->fani)->get('/app/settings')->assertForbidden();
        $this->actingAs($this->mudir)->get('/app/settings')->assertForbidden();
        // كل رابط في الإعدادات يفتح (لا 404 ولا 500) لمسؤول السلامة
        preg_match_all('/<a class="list-group-item[^"]*" href="([^"]+)"/', $r->getContent(), $m);
        $this->assertGreaterThan(15, count($m[1]));
        foreach ($m[1] as $url) {
            $code = $this->actingAs($this->salama)->get($url)->getStatusCode();
            $this->assertContains($code, [200, 302], "الرابط $url أعاد $code");
        }
    }
}
