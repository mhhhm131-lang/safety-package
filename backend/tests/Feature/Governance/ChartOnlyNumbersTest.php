<?php

namespace Tests\Feature\Governance;

use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\AffectedGroupsSeeder;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ٢٦-١٠ (قرار ٦٦ «أرقام واحدة في الصفحة الأولى»، بكلمته «ابدأ» ٢٠٢٦-٠٩-٣٠): الأرقام الأربعة الكبيرة حُذفت —
 * كانت تكرر الرسم وعنوان «ما ينتظرك»، ولا يراها إلا من يملك التقارير. الرسم «حال الآن» وحده، وزر «التقارير» تحته لمن يملكها.
 * ولا يُخفى شيء (قرار ٦١): من يملك التقارير ولا مكان في نطاقه (لا رسم عنده) يبقى له الزر.
 */
class ChartOnlyNumbersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class, EmergencySeeder::class]);
    }

    private function user(string $username, string $role, ?string $placeCode = null, bool $unit = false): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'place_id' => Place::idByCode($placeCode), 'organization_unit_id' => $unit ? OrganizationUnit::first()->id : null]);
        return $u;
    }

    /** زر التقارير في الصفحة: [عدده، هل هو داخل بطاقة الرسم بعد الأعمدة] */
    private function reportsDoor(string $html): array
    {
        $n = substr_count($html, 'id="reportsDoor"');
        $chart = strpos($html, 'id="chart"');
        $bars = strpos($html, 'id="bars"');
        $door = strpos($html, 'id="reportsDoor"');
        $snap = strpos($html, 'id="snapshot"');
        $under = $chart !== false && $door !== false && $bars < $door && $door < $snap;
        return [$n, $under];
    }

    public function test_safety_officer_sees_no_big_numbers_and_reports_button_under_the_chart(): void
    {
        $salama = $this->user('salama', 'system_admin');
        $html = $this->actingAs($salama)->get('/app')->assertOk()->getContent();

        $this->assertStringNotContainsString('id="tiles"', $html);
        $this->assertStringNotContainsString('data-tile=', $html);
        // «فجوة الاستجابة» تبقى كلمةً في تلميح زر التقارير لا رقماً في الصفحة
        foreach (['بلاغات شاغلين قبل استلام الفني', 'حالات طارئة بلا إقرار', 'ينتظرك الآن</span>', 'من البلاغ إلى استلام الفني'] as $gone) $this->assertStringNotContainsString($gone, $html, $gone);

        // الرسم بأعمدته الستة كما هو، وأول ما في الصفحة الأماكن والرسم
        $this->assertSame(6, preg_match_all('~<a class="bar[^"]*" data-k="~', $html));
        $this->assertLessThan(strpos($html, 'id="chart"'), strpos($html, 'id="places"'));

        // زر واحد «التقارير» تحت الأعمدة داخل بطاقة الرسم، يفتح صفحة فيها «فجوة الاستجابة» أولاً
        [$n, $under] = $this->reportsDoor($html);
        $this->assertSame(1, $n);
        $this->assertTrue($under, 'زر التقارير ليس تحت أعمدة الرسم');
        $this->assertMatchesRegularExpression('~<a[^>]*id="reportsDoor"[^>]*href="'.preg_quote(route('reports.dashboard'), '~').'"[^>]*>.*?التقارير~s', $html);
        $this->assertSame(1, substr_count($html, 'href="'.route('reports.dashboard').'"'), 'باب واحد للتقارير في الصفحة الأولى');
        $this->actingAs($salama)->get(route('reports.dashboard'))->assertOk()->assertSee('فجوة الاستجابة');

        // «ينتظرك» بقي في عنوان قسمه وزر الشريط
        $this->assertStringContainsString('id="navInbox"', $html);
    }

    /** الزر لمن يملك التقارير وحده؛ والرسم لكل حساب له مكان — المناوب والفني والموظف يرون ما يراه غيرهم من أرقام */
    public function test_reports_button_follows_the_permission_for_every_role_with_and_without_a_place(): void
    {
        foreach (array_keys(PermissionRegistry::ROLES) as $i => $role) {
            $can = PermissionRegistry::hasPermission($role, 'report.view');
            foreach ([['p', 'HZ-06', true], ['b', null, false]] as [$prefix, $hz, $unit]) {
                $u = $this->user("$prefix$i", $role, $hz, $unit);
                $html = $this->actingAs($u)->get('/app')->assertOk()->getContent();
                $who = $role.($hz ? '' : ' (بلا مكان)');
                $this->assertStringNotContainsString('id="tiles"', $html, $who);
                $hasChart = str_contains($html, 'id="chart"');
                [$n, $under] = $this->reportsDoor($html);
                $this->assertSame($can ? 1 : 0, $n, "$who: زر التقارير");
                if ($can && $hasChart) $this->assertTrue($under, "$who: زر التقارير ليس تحت الرسم");
                if ($can) $this->actingAs($u)->get(route('reports.dashboard'))->assertOk();
            }
        }
        // أضعف من يملك التقارير: مدير إدارة بلا مكان ولا وحدة — لا رسم عنده، والزر باقٍ
        $bare = $this->user('mudir.bare', 'department_manager');
        $html = $this->actingAs($bare)->get('/app')->assertOk()->getContent();
        $this->assertStringNotContainsString('id="chart"', $html);
        $this->assertSame(1, substr_count($html, 'id="reportsDoor"'));
    }
}
