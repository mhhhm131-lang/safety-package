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
 * المرحلة ١٩-٤ (قرار ٤٨): صورة المبنى والأماكن التسعة في الخلفية (dashboard.html:602-632) —
 * فسيفساء بحالة كل مكان وأعداده، وأربعة أرقام قابلة للنقر لأدوار القرار تعرض بلاغاتها.
 */
class BuildingPictureTest extends TestCase
{
    use RefreshDatabase;

    private User $salama; private User $fani; private User $mudir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class]);
        $this->salama = $this->user('salama', 'system_admin');
        $this->fani = $this->user('fani', 'field_worker');
        $this->mudir = $this->user('mudir', 'department_manager', 'fin'); // إدارته في المكاتب HZ-06
        $stamp = fn (int $h) => now()->subHours($h)->format('Y/m/d').' — '.now()->subHours($h)->format('H:i');
        $def = ['name' => 'نظام', 'code' => '٠١', 'items' => [['بند', 'SBC']], 'sched' => [['شهري', 'فحص', 'الفني المختص']]];
        InstituteDocument::create(['key' => 'ipa-park-form-v10', 'version' => 1, 'data' => json_encode(['defs' => ['p01' => $def], 'reports' => [
            ['row' => 'p01-i-0', 'id' => 'ب — ٠١', 'sys' => 'التهوية', 'item' => 'مراوح لا تعمل', 'imp' => 'none', 'due' => 'فوري', 'when' => $stamp(30), 'sent' => $stamp(30), 'path' => 'إداري',
                'levels' => [1 => ['up' => true, 'back' => false]]],                                              // مفتوح، متجاوز، فئة أ
            ['row' => 'p01-i-1', 'id' => 'ب — ٠٢', 'sys' => 'التهوية', 'item' => 'توقف المسار عند المدير', 'imp' => 'alt', 'due' => '٧٢ ساعة', 'when' => $stamp(5), 'sent' => $stamp(5), 'path' => 'رقابي',
                'levels' => [1 => ['up' => true, 'back' => false]]],                                              // مفتوح، رقابي، في مهلته
            ['row' => 'p01-i-2', 'id' => 'ب — ٠٣', 'sys' => 'التهوية', 'item' => 'أُصلح', 'due' => '٢٤ ساعة', 'when' => $stamp(60), 'sent' => $stamp(60), 'path' => 'إداري',
                'levels' => [1 => ['up' => false, 'back' => false]]],                                             // مغلق
        ]], JSON_UNESCAPED_UNICODE)]);
        InstituteDocument::create(['key' => 'ipa-elec-form-v10', 'version' => 1, 'data' => json_encode(['defs' => ['e01' => $def], 'reports' => []], JSON_UNESCAPED_UNICODE)]); // فُتح، بلا بلاغات
    }

    private function user(string $username, string $role, ?string $unitCode = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234']);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true,
            'organization_unit_id' => $unitCode ? OrganizationUnit::where('code', $unitCode)->value('id') : null]);
        return $u;
    }

    public function test_places_mosaic_states_and_counts(): void
    {
        $h = $this->actingAs($this->salama)->get('/app/places/units')->assertOk()->getContent();
        $this->assertSame(9, substr_count($h, 'data-place="'));
        $this->assertStringContainsString('data-place="HZ-01" data-cls="late" data-open="2" data-od="1" data-a="1"', $h);
        $this->assertStringContainsString('data-place="HZ-02" data-cls="calm" data-open="0" data-od="0" data-a="0"', $h);
        $this->assertStringContainsString('data-place="HZ-03" data-cls="none"', $h);
        $this->assertStringContainsString('لم تُفتح جولة بعد', $h);
        $this->assertStringContainsString('لا بلاغات مفتوحة', $h);
        // ترتيب اللوحة: HZ-01 قبل HZ-00
        $this->assertTrue(strpos($h, 'data-place="HZ-01"') < strpos($h, 'data-place="HZ-00"'));
        // مدير الإدارة يرى مكان إدارته وحده
        $hd = $this->actingAs($this->mudir)->get('/app/places/units')->assertOk()->getContent();
        $this->assertSame(1, substr_count($hd, 'data-place="'));
        $this->assertStringContainsString('data-place="HZ-06"', $hd);
    }

    public function test_building_picture_kpis_are_clickable_for_decision_roles_only(): void
    {
        $h = $this->actingAs($this->salama)->get('/app/places/units')->assertOk()->getContent();
        foreach (['open' => 2, 'od' => 1, 'a' => 1, 'reg' => 1] as $k => $v) $this->assertStringContainsString('data-k="'.$k.'" data-v="'.$v.'"', $h);
        $this->assertStringNotContainsString('id="kList"', $h); // لا قائمة قبل النقر
        // النقر = ?k= : «تصعيد رقابي» يعرض بلاغه وحده
        $r = $this->actingAs($this->salama)->get('/app/places/units?k=reg')->assertOk()->getContent();
        $sec = substr($r, strpos($r, 'id="kList"'), 3000);
        $this->assertStringContainsString('توقف المسار عند المدير', $sec);
        $this->assertStringNotContainsString('مراوح لا تعمل', $sec);
        $this->assertStringContainsString('href="/HZ-01-basement/inspection-form.html#open=p01-i-1"', $sec);
        // «مفتوح»: الأشد تأخراً أولاً، والمغلق لا يظهر
        $o = $this->actingAs($this->salama)->get('/app/places/units?k=open')->assertOk()->getContent();
        $so = substr($o, strpos($o, 'id="kList"'), 4000);
        $this->assertTrue(strpos($so, 'مراوح لا تعمل') < strpos($so, 'توقف المسار عند المدير'));
        $this->assertStringNotContainsString('أُصلح', $so);
        // الفني ليس من أدوار القرار: يرى الفسيفساء بلا الأرقام — و(٢٠-٥) ما يغطيه فقط: بلا تغطية لا مكان، وبتغطية القبو بلاطة القبو
        $this->assertSame(0, substr_count($this->actingAs($this->fani)->get('/app/places/units')->assertOk()->getContent(), 'data-place="'));
        $this->fani->profile->coverage()->sync([Place::idByCode('HZ-01')]);
        $hf = $this->actingAs($this->fani)->get('/app/places/units')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-k="open"', $hf);
        $this->assertSame(1, substr_count($hf, 'data-place="'));
        $this->assertStringContainsString('data-place="HZ-01"', $hf);
        // النية «الأماكن» لأدوار الواجهة
        $this->actingAs($this->fani)->get('/app')->assertOk()->assertSee('data-intent="places"', false);
    }
}
