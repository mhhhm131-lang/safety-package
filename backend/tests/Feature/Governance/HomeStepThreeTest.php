<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\PlaceUnit;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٢٥-٣ (قرار ٦٥، من مراجعة محادثة «تحليل وتحسين تجربة المستخدم»): الترتيب ما ينتظرك ← الأماكن ← الرسم ← أريد أن…؛
 * لا تكرار: سطر «مكاني» يُحذف (المربع يقوم مقامه، وهاتف المركز في بطاقة الأماكن)، و«أستغيث الآن» شريط ثابت لا زر ثانٍ،
 * و«الأماكن» لا نية لها (صارت في الصفحة الأولى)؛ مركز السلامة وإدارة الطوارئ باب واحد في «أريد أن…» ومنه سجل بلاغات الشاغلين؛
 * وملف المكاتب الإدارية يعرض الإدارات كلها من الهيكل التنظيمي.
 */
class HomeStepThreeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
    }

    private function user(string $username, string $role, ?string $placeCode = null, ?int $unitId = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'place_id' => Place::idByCode($placeCode), 'organization_unit_id' => $unitId]);
        return $u;
    }

    public function test_order_and_no_duplicates_on_the_home_screen(): void
    {
        $emp = $this->user('emp', 'employee', 'HZ-06');
        $this->post('/incident/normal', ['description' => 'بلاط مكسور', 'place_id' => Place::idByCode('HZ-06')])->assertRedirect();
        $h = $this->actingAs($emp)->get('/app')->assertOk()->getContent();

        // الترتيب: ما ينتظرك ← الأماكن ← الرسم ← أريد أن…
        $inbox = strpos($h, 'id="inboxList"'); if ($inbox === false) $inbox = strpos($h, 'id="inboxEmpty"'); // بلا مهام: بطاقة «لا شيء ينتظرك» في الموضع نفسه
        // ٢٦-١ (قرار ٦٦، بكلمته «نفّذ الترتيب: الأماكن، الرسم، ما ينتظرك، ثم أريد أن»)
        $pos = [strpos($h, 'id="places"'), strpos($h, 'id="chart"'), $inbox, strpos($h, 'id="intents"')];
        $this->assertNotContains(false, $pos);
        $sorted = $pos; sort($sorted);
        $this->assertSame($sorted, $pos, 'الترتيب: الأماكن ← الرسم ← ما ينتظرك ← أريد أن');
        // لا سطر «مكاني» فوق المربع، وهاتف المركز في بطاقة الأماكن
        $this->assertStringNotContainsString('id="makaniLine"', $h);
        $this->assertMatchesRegularExpression('~id="placesCard".*?href="tel:0505498966".*?id="places"~s', $h);
        // «أستغيث الآن» مرة واحدة: الشريط الثابت، لا زر ثانٍ داخل «أريد أن…»
        $this->assertSame(1, substr_count($h, 'data-intent="sos"'));
        $this->assertStringContainsString('id="sosBar"', $h);
        // «الأماكن» لم تعد نية (صارت في الصفحة الأولى)
        $this->assertStringNotContainsString('data-intent="places"', $h);
    }

    public function test_safety_center_and_emergency_are_one_door_in_intents(): void
    {
        $salama = $this->user('salama', 'system_admin');
        $h = $this->actingAs($salama)->get('/app')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-intent="incidents"', $h);
        $this->assertStringNotContainsString('data-intent="emergency"', $h);
        $this->assertMatchesRegularExpression('~data-intent="center"[^>]*>.*?مركز السلامة وإدارة الطوارئ~s', $h);
        $this->assertStringContainsString('href="'.route('emergency.dashboard').'" data-intent="center"', $h);
        // الباب الواحد: من لوحته يُفتح سجل بلاغات الشاغلين
        $d = $this->actingAs($salama)->get(route('emergency.dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('مركز السلامة وإدارة الطوارئ', $d);
        $this->assertStringContainsString('href="'.route('incidents.index').'"', $d);
        $this->assertStringContainsString('سجل بلاغات الشاغلين', $d);
        // الفني: يملك سجل البلاغات ولا يملك الطوارئ ← الباب نفسه يفتح له السجل
        $fani = $this->user('fani', 'field_worker', 'HZ-06');
        $h = $this->actingAs($fani)->get('/app')->assertOk()->getContent();
        $this->assertStringContainsString('href="'.route('incidents.index').'" data-intent="center"', $h);
        // الطبيب: لا سجل بلاغات (قرار ٥٥) لكن له الطوارئ ← اللوحة
        $tabib = $this->user('tabib', 'clinic_doctor', 'HZ-06');
        $h = $this->actingAs($tabib)->get('/app')->assertOk()->getContent();
        $this->assertStringContainsString('href="'.route('emergency.dashboard').'" data-intent="center"', $h);
    }

    public function test_offices_place_file_lists_every_department_from_the_org_structure(): void
    {
        $salama = $this->user('salama', 'system_admin');
        $offices = Place::where('code', 'HZ-06')->first();
        $n = OrganizationUnit::where('place_id', $offices->id)->where('is_active', true)->count();
        $this->assertGreaterThan(1, $n);
        // إدارة واحدة سُجّل دورها وموقعها
        $fin = OrganizationUnit::where('code', 'fin')->firstOrFail();
        PlaceUnit::create(['place_id' => $offices->id, 'type' => 'department', 'organization_unit_id' => $fin->id, 'name' => $fin->name, 'floor' => '٦', 'location' => 'الجناح الشرقي', 'is_active' => true]);

        $f = $this->actingAs($salama)->get("/app/places/{$offices->id}/file")->assertOk()->getContent();
        $this->assertSame($n, substr_count($f, 'data-unit-chip="'), 'الإدارات كلها من الهيكل لا ما سُجّل دوره فقط');
        $this->assertMatchesRegularExpression('~data-unit-chip="fin"[^>]*>.*?الدور ٦.*?الجناح الشرقي~s', $f);
        $this->assertStringContainsString('الوحدات <span class="badge text-bg-dark">'.$n.'</span>', $f);
        // الموظف يراها أيضاً (لا صلاحية جديدة: الهيكل كان ظاهراً في الفرق الأولية)
        $emp = $this->user('emp', 'employee', 'HZ-06');
        $this->assertSame($n, substr_count($this->actingAs($emp)->get("/app/places/{$offices->id}/file")->assertOk()->getContent(), 'data-unit-chip="'));
    }
}
