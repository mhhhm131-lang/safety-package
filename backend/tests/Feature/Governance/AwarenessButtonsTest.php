<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskSubCategory;
use Database\Seeders\AffectedGroupsSeeder;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ٢٦-١٣ (قرار ٦٦ «الزر يفي بوعده»، بكلمته «أنجز البنود الثلاثة المتبقية» ٢٠٢٦-٠٩-٣٠): زرّا التوعية يفتحان مكان الشخص.
 * «خطة مكاني» ← ملف مكانه وخطتاه أول ما فيه (كان يفتح فهرس وثائق المكان)؛ «أعرف أخطار مكاني» ← أخطار مكانه من كتاب المعهد
 * (كان يفتح الكتاب كله بلا مكان). مكان الشخص = مكان حسابه وإلا مكان إدارته. من لا مكان واحد له يبقى على الفهرس والكتاب كله.
 */
class AwarenessButtonsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class, EmergencySeeder::class]);
    }

    private function user(string $username, string $role, ?string $placeCode = null, ?int $unitId = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'place_id' => Place::idByCode($placeCode), 'organization_unit_id' => $unitId]);
        return $u;
    }

    /** رابط زر في «أريد أن» */
    private function intentUrl(string $html, string $key): string
    {
        $this->assertSame(1, preg_match('~<a class="btn btn-o btn-sm" href="([^"]+)" data-intent="'.$key.'"~', $html, $m), "زر «{$key}» غائب");
        return html_entity_decode($m[1]);
    }

    public function test_both_buttons_open_the_persons_own_place(): void
    {
        $offices = Place::where('code', 'HZ-06')->firstOrFail();
        $emp = $this->user('emp', 'employee', 'HZ-06');
        $html = $this->actingAs($emp)->get('/app')->assertOk()->getContent();

        $plans = $this->intentUrl($html, 'plans');
        $this->assertSame(route('app.places.units.file', $offices).'#plans', $plans);
        $file = $this->actingAs($emp)->get($plans)->assertOk()->getContent();
        // الخطتان أول ما في الملف، كل واحدة تفتح وثيقتها، والمرساة تبرزهما
        $this->assertMatchesRegularExpression('~id="pfPlanSafety"[^>]*href="/HZ-06-offices/safety-plan\.html"~', $file);
        $this->assertMatchesRegularExpression('~id="pfPlanResponse"[^>]*href="/HZ-06-offices/response-plan\.html"~', $file);
        $this->assertLessThan(strpos($file, 'id="pfForms"'), strpos($file, 'id="pfPlanResponse"'));
        $this->assertStringContainsString("location.hash==='#plans'", $file);

        $this->assertSame(route('hazards.index', ['place' => 'HZ-06']), $this->intentUrl($html, 'hazards'));
    }

    /** مكان الشخص من إدارته حين لا مكان في حسابه (أغلب الموظفين) */
    public function test_place_comes_from_the_department_when_the_account_has_none(): void
    {
        $unit = OrganizationUnit::whereNotNull('place_id')->firstOrFail();
        $place = Place::findOrFail($unit->place_id);
        $emp = $this->user('emp', 'employee', null, $unit->id);
        $html = $this->actingAs($emp)->get('/app')->assertOk()->getContent();
        $this->assertSame(route('app.places.units.file', $place).'#plans', $this->intentUrl($html, 'plans'));
        $this->assertSame(route('hazards.index', ['place' => $place->code]), $this->intentUrl($html, 'hazards'));
    }

    /** من لا مكان واحد له (مسؤول السلامة، القيادات): الفهرس والكتاب كله كما كانا */
    public function test_accounts_without_one_place_keep_the_index_and_the_whole_book(): void
    {
        $html = $this->actingAs($this->user('salama', 'system_admin'))->get('/app')->assertOk()->getContent();
        $this->assertSame('/index.html', $this->intentUrl($html, 'plans'));
        $this->assertSame(route('hazards.index'), $this->intentUrl($html, 'hazards'));
    }

    public function test_hazards_page_shows_the_places_own_hazards_and_keeps_the_whole_book_one_press_away(): void
    {
        $cat = RiskCategory::create(['name' => 'الكهربائية', 'is_active' => true, 'created_at' => now()]);
        $sub = RiskSubCategory::create(['category_id' => $cat->id, 'name' => 'التمديدات']);
        $base = ['description' => 'وصف', 'risk_type' => 'reference', 'status' => 'approved', 'severity' => 3, 'likelihood' => 3, 'category_id' => $cat->id, 'sub_category_id' => $sub->id];
        $mine = Risk::create($base + ['title' => 'مقبس محمّل فوق طاقته', 'code' => 'EL-01-01']);
        $other = Risk::create($base + ['title' => 'لوحة كهرباء مكشوفة', 'code' => 'EL-01-02']);
        $offices = Place::where('code', 'HZ-06')->firstOrFail();
        // خطر فعّلته إدارة في المكاتب، ومسودة في المكاتب لا تُعرض
        Risk::create(['title' => $mine->title, 'description' => 'وصف', 'risk_type' => 'active', 'status' => 'active', 'severity' => 3, 'likelihood' => 3,
            'parent_reference_id' => $mine->id, 'place_id' => $offices->id, 'category_id' => $cat->id, 'sub_category_id' => $sub->id]);
        Risk::create(['title' => $other->title, 'description' => 'وصف', 'risk_type' => 'active', 'status' => 'draft', 'severity' => 3, 'likelihood' => 3,
            'parent_reference_id' => $other->id, 'place_id' => $offices->id, 'category_id' => $cat->id, 'sub_category_id' => $sub->id]);

        // بمكانه: أخطار مكانه وحدها، باسم المكان وعددها، والبلاغ يحمل المكان
        $p = $this->get(route('hazards.index', ['place' => 'HZ-06']))->assertOk()->getContent();
        $this->assertStringContainsString('data-risk="EL-01-01"', $p);
        $this->assertStringNotContainsString('data-risk="EL-01-02"', $p);
        $this->assertMatchesRegularExpression('~id="placeHazards"[^>]*data-place="HZ-06" data-n="1"~', $p);
        $this->assertStringContainsString('أخطار المكاتب الإدارية', $p);
        $this->assertStringContainsString('risk='.$mine->id.'&amp;place=HZ-06', $p);
        // الكتاب كله بضغطة
        $this->assertStringContainsString('href="'.e(route('hazards.index', ['place' => 'HZ-06', 'all' => 1])).'"', $p);
        $all = $this->get(route('hazards.index', ['place' => 'HZ-06', 'all' => 1]))->assertOk()->getContent();
        $this->assertStringContainsString('data-risk="EL-01-01"', $all);
        $this->assertStringContainsString('data-risk="EL-01-02"', $all);

        // مكان لم تُسجَّل له أخطار: يُقال ذلك، ويُعرض الكتاب كله
        $none = $this->get(route('hazards.index', ['place' => 'HZ-01']))->assertOk()->getContent();
        $this->assertStringContainsString('id="placeHazardsNone"', $none);
        $this->assertStringContainsString('data-risk="EL-01-01"', $none);
        $this->assertStringContainsString('data-risk="EL-01-02"', $none);

        // بلا مكان: الكتاب كله كما كان، بلا سطر المكان
        $book = $this->get(route('hazards.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="placeHazards', $book);
        $this->assertStringContainsString('data-risk="EL-01-02"', $book);
    }
}
