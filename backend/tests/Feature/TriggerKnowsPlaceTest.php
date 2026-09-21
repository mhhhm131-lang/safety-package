<?php

namespace Tests\Feature;

use App\Core\Intents\IntentRegistry;
use App\Models\User;
use App\Modules\Emergency\Models\AssemblyPoint;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٢٢-٨ (د): ما يعرفه النظام لا يُسأل، والزران يندمجان.
 *
 * العيب من جرد ٢٢-١: شاشة التفعيل تسأل عن المكان إلزاماً («اختر المكان…») ولو جاء المفعِّل من ملف
 * مكان أو كان له مكان في حسابه؛ ونيّتا «فعّل حالة طارئة» و«إخلاء أو إغلاق» تفتحان الشاشة نفسها.
 *
 * البوابة: تفعيل من ملف مكان بلا اختيار مكان، والمكان يبقى قابلاً للتغيير.
 */
class TriggerKnowsPlaceTest extends TestCase
{
    use RefreshDatabase;

    private User $munawib;    // مكانه HZ-00 (مركز السلامة)
    private User $marafiq;    // بلا مكان
    private EmergencyBuilding $building;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlacesSeeder::class);
        $this->seed(OrganizationUnitsSeeder::class);
        $this->seed(EmergencySeeder::class);

        $this->munawib = $this->user('munawib', 'system_staff', 'HZ-00');
        $this->marafiq = $this->user('marafiq', 'facilities_manager');
        $this->building = EmergencyBuilding::main();
        AssemblyPoint::create(['building_id' => $this->building->id, 'code' => 'A1',
            'name' => 'الساحة الأمامية', 'is_primary' => true, 'capacity' => 300]);
    }

    private function user(string $username, string $role, ?string $placeCode = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'place_id' => Place::idByCode($placeCode)]);
        return $u;
    }

    private function selected(string $html): ?string
    {
        // رمز المكان المختار مسبقاً في قائمة الأماكن
        if (preg_match('/<option value="(\d+)"[^>]*selected[^>]*>\s*([A-Z]{2}-\d{2})/u', $html, $m)) {
            return $m[2];
        }
        return null;
    }

    /** جاء من ملف مكان: مكانه هو المختار، بلا أن يُسأل. */
    public function test_place_comes_from_the_link_when_triggering_from_a_place_file(): void
    {
        $html = $this->actingAs($this->munawib)
            ->get("/app/emergency/buildings/{$this->building->id}/control?place=HZ-07")
            ->assertOk()->getContent();

        $this->assertSame('HZ-07', $this->selected($html), 'المكان لم يُملأ من الرابط');
        $this->assertStringNotContainsString('>اختر المكان…<', $html, 'ما زال يسأل عن المكان');
    }

    /** بلا رابط: مكان حسابه هو المختار — النظام يعرفه فلا يسأله. */
    public function test_place_falls_back_to_the_account_place(): void
    {
        $html = $this->actingAs($this->munawib)
            ->get("/app/emergency/buildings/{$this->building->id}/control")
            ->assertOk()->getContent();

        $this->assertSame('HZ-00', $this->selected($html), 'المكان لم يُملأ من حساب المفعِّل');
    }

    /** ولا يعرفه: يبقى السؤال، فلا يُخترع مكان. */
    public function test_it_still_asks_when_it_does_not_know(): void
    {
        $html = $this->actingAs($this->marafiq)
            ->get("/app/emergency/buildings/{$this->building->id}/control")
            ->assertOk()->getContent();

        $this->assertNull($this->selected($html), 'اختار مكاناً بلا سند');
        $this->assertStringContainsString('اختر المكان', $html);
    }

    /** المكان يبقى قابلاً للتغيير — لا يُقفل. */
    public function test_the_place_stays_changeable(): void
    {
        $html = $this->actingAs($this->munawib)
            ->get("/app/emergency/buildings/{$this->building->id}/control?place=HZ-07")
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<select name="place_id"(?![^>]*disabled)/u', $html);
        $this->assertGreaterThan(5, substr_count($html, '<option value="'), 'بقية الأماكن اختفت');
    }

    /** الزران يندمجان: «إخلاء أو إغلاق» لم تعد نيةً مستقلة، والإغلاق الأمني باقٍ في الشاشة نفسها. */
    public function test_the_lockdown_intent_is_merged_into_trigger(): void
    {
        $intents = IntentRegistry::forUser($this->munawib);
        $this->assertNull($intents->firstWhere('key', 'lockdown'), 'نية «إخلاء أو إغلاق» ما زالت مستقلة');

        $trigger = $intents->firstWhere('key', 'trigger');
        $this->assertNotNull($trigger);
        $this->assertStringContainsString('إخلاء', $trigger->label.' '.$trigger->hint);

        $this->actingAs($this->munawib)
            ->get("/app/emergency/buildings/{$this->building->id}/control")
            ->assertOk()
            ->assertSee('إغلاق أمني', false);   // الوظيفة باقية في الشاشة
    }
}
