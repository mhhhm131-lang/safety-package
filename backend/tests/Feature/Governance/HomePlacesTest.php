<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Store\Models\InstituteDocument;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٢٥-١ (قرار ٦٤): الأماكن في الصفحة الأولى مربعات تُضغط وتفتح ملف المكان، لكل حساب في نطاقه
 * (الموظف مكانه، الفني ما يغطيه، مسؤول السلامة الكل) — لا شرط `report.view`. وباب واحد: رابط
 * «الأماكن وملفاتها» يختفي من القائمة، ومساره يحوّل إلى الصفحة الأولى، ويبقى `?k=` لقائمة بلاغات الفحص.
 */
class HomePlacesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class]);
        $stamp = now()->subHours(30)->format('Y/m/d').' — '.now()->subHours(30)->format('H:i');
        $def = ['name' => 'نظام', 'code' => '٠١', 'items' => [['بند', 'SBC']], 'sched' => [['شهري', 'فحص', 'الفني المختص']]];
        InstituteDocument::create(['key' => 'ipa-park-form-v10', 'version' => 1, 'data' => json_encode(['defs' => ['p01' => $def], 'reports' => [
            ['row' => 'p01-i-0', 'id' => 'ب — ٠١', 'sys' => 'التهوية', 'item' => 'مراوح لا تعمل', 'imp' => 'none', 'due' => 'فوري', 'when' => $stamp, 'sent' => $stamp, 'path' => 'إداري',
                'levels' => [1 => ['up' => true, 'back' => false]]], // مفتوح ومتجاوز في القبو
        ]], JSON_UNESCAPED_UNICODE)]);
    }

    private function user(string $username, string $role, ?string $placeCode = null, ?string $unitCode = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234']);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'place_id' => Place::idByCode($placeCode),
            'organization_unit_id' => $unitCode ? OrganizationUnit::where('code', $unitCode)->value('id') : null]);
        return $u;
    }

    public function test_home_shows_clickable_place_tiles_for_every_account_in_its_scope(): void
    {
        $offices = Place::where('code', 'HZ-06')->first();
        $park = Place::where('code', 'HZ-01')->first();

        // الموظف: بلا report.view — ومع ذلك يرى مكانه وحده، والمربع يفتح ملف المكان
        $emp = $this->user('emp', 'employee', 'HZ-06');
        $h = $this->actingAs($emp)->get('/app')->assertOk()->getContent();
        $this->assertStringContainsString('id="places"', $h);
        $this->assertSame(1, substr_count($h, 'data-place="HZ-'));
        $this->assertStringContainsString('data-place="HZ-06"', $h);
        $this->assertStringContainsString('href="'.url("/app/places/{$offices->id}/file").'"', $h);
        $this->assertStringNotContainsString('id="homeDetails"', $h); // الرسم ما زال لمن يملك report.view (يتغيّر في ٢٥-٢)

        // الفني يغطي القبو: مربع القبو وحده بلونه من حال الفحص (متجاوز = أحمر)
        $fani = $this->user('fani', 'field_worker', 'HZ-06');
        $fani->profile->coverage()->sync([$park->id]);
        $h = $this->actingAs($fani)->get('/app')->assertOk()->getContent();
        $this->assertSame(1, substr_count($h, 'data-place="HZ-'));
        $this->assertStringContainsString('data-place="HZ-01" data-cls="late" data-open="1" data-od="1"', $h);
        $this->assertStringContainsString('href="'.url("/app/places/{$park->id}/file").'"', $h);

        // مدير الإدارة (المالية في المكاتب): مكان إدارته وحده
        $mudir = $this->user('mudir', 'department_manager', null, 'fin');
        $h = $this->actingAs($mudir)->get('/app')->assertOk()->getContent();
        $this->assertSame(1, substr_count($h, 'data-place="HZ-'));
        $this->assertStringContainsString('data-place="HZ-06"', $h);

        // مسؤول السلامة: التسعة كلها بترتيب اللوحة (القبو قبل مركز السلامة)
        $salama = $this->user('salama', 'system_admin');
        $h = $this->actingAs($salama)->get('/app')->assertOk()->getContent();
        $this->assertSame(9, substr_count($h, 'data-place="HZ-'));
        $this->assertTrue(strpos($h, 'data-place="HZ-01"') < strpos($h, 'data-place="HZ-00"'));
        // الشبكة القديمة الميتة (div بلا رابط) لم تعد موجودة
        $this->assertStringNotContainsString('class="place lvl', $h);
    }

    public function test_one_door_the_duplicate_menu_link_is_gone_and_its_route_goes_home(): void
    {
        $salama = $this->user('salama', 'system_admin');
        $this->actingAs($salama)->get('/app')->assertOk()->assertDontSee('الأماكن وملفاتها');
        $this->actingAs($salama)->get('/app/users')->assertOk()->assertDontSee('الأماكن وملفاتها');
        // المسار القديم لا يُكسر: يحوّل إلى الصفحة الأولى عند الأماكن
        $this->actingAs($salama)->get('/app/places/units')->assertRedirect(url('/app').'#places');
        // ومع ?k= يبقى قائمة بلاغات الفحص بتصنيفها (تفتحها أعمدة الرسم)
        $this->actingAs($salama)->get('/app/places/units?k=open')->assertOk()->assertSee('id="kList"', false)->assertSee('مراوح لا تعمل');
        // الموظف لا يملك report.view: القائمة ليست له
        $emp = $this->user('emp', 'employee', 'HZ-06');
        $this->actingAs($emp)->get('/app/places/units?k=open')->assertRedirect(url('/app').'#places');
    }
}
