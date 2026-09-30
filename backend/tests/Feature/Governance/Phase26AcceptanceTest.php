<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\AffectedGroupsSeeder;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ٢٦-١٤ (قبول المرحلة ٢٦ «باب واحد لكل شيء»، قرار ٦٦): ما وعدت به الخطة يُقاس.
 * «الوصول إلى أي شيء في الطوارئ بضغطتين: مربع المركز ثم الشاشة» — جولة القبول عدّت ثلاثاً عند من له أماكن كثيرة،
 * لأن مربع «مركز السلامة» كان يرشّح الرسم ولا يفتح صفحته إلا بزر ثانٍ. المربع يفتح صفحة المركز مباشرة للجميع.
 */
class Phase26AcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class, EmergencySeeder::class]);
    }

    private function user(string $username, string $role, ?string $placeCode = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'place_id' => Place::idByCode($placeCode)]);
        return $u;
    }

    public function test_any_emergency_screen_is_two_presses_away_for_accounts_with_many_places(): void
    {
        foreach (['salama' => 'system_admin', 'munawib' => 'system_staff'] as $name => $role) {
            $u = $this->user($name, $role);
            $home = $this->actingAs($u)->get('/app')->assertOk()->getContent();
            // الضغطة الأولى: مربع المركز رابط إلى صفحته، ولا يُعترَض لترشيح الرسم
            $this->assertSame(1, preg_match('~<a class="pl-tile[^"]*" href="([^"]+)" data-place="HZ-00"([^>]*)>~', $home, $m), "$role: مربع المركز غائب");
            $this->assertSame(route('emergency.dashboard'), $m[1]);
            $this->assertStringNotContainsString('data-filter', $m[2], "$role: مربع المركز يرشّح الرسم بدل أن يفتح صفحته");
            // بقية الأماكن الثمانية ترشّح الرسم كما كانت
            $this->assertSame(8, substr_count($home, 'data-filter="1"'));
            // الضغطة الثانية: الباب داخل صفحة المركز
            $center = $this->actingAs($u)->get($m[1])->assertOk()->getContent();
            foreach (['الفريق الأولي' => route('emergency.teams.index'), 'سجل الحالات الطارئة' => route('emergency.incidents.index'), 'سجل بلاغات الشاغلين' => route('incidents.index')] as $door => $url) {
                $this->assertStringContainsString('href="'.$url.'" data-door="'.$door.'"', $center, "$role: باب «{$door}»");
                $this->actingAs($u)->get($url)->assertOk();
            }
        }
    }

    /** من له مكان واحد: مربعه يفتح ملفه بضغطة كما كان */
    public function test_single_place_account_opens_its_file_in_one_press(): void
    {
        $emp = $this->user('emp', 'employee', 'HZ-06');
        $home = $this->actingAs($emp)->get('/app')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-filter="1"', $home);
        $this->assertSame(1, preg_match('~<a class="pl-tile[^"]*" href="([^"]+)" data-place="HZ-06"~', $home, $m));
        $this->actingAs($emp)->get($m[1])->assertOk();
    }
}
