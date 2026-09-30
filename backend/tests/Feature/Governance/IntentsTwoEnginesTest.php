<?php

namespace Tests\Feature\Governance;

use App\Core\Inbox\InboxService;
use App\Core\Intents\IntentRegistry;
use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Incident\Models\Incident;
use Database\Seeders\AffectedGroupsSeeder;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ٢٦-٨ (قرار ٦٧): محركان فقط — «ما ينتظرك» ما يُراد من الشخص، و«أريد أن» ما يبدؤه بنفسه.
 * «أريد أن» بالمجموعات العشر نفسها التي في «ما ينتظرك» وترتيبها وأيقوناتها؛ المجموعة الفارغة لا تظهر؛
 * كل ما أبدؤه له زر واحد، وما له باب في ملف المكان أو الرسم أو المزيد لا يتكرر هنا؛ «أتابع بلاغاتي» زر جديد لصاحب الحساب.
 */
class IntentsTwoEnginesTest extends TestCase
{
    use RefreshDatabase;

    /** الأزرار التي خرجت من «أريد أن» لأن بابها في مكان آخر (ملف المكان، الرسم، المزيد، المربع) */
    private const GONE = ['makani', 'forms', 'permit', 'permits', 'reports', 'settings', 'nominate', 'units', 'my_techs', 'my_coordinator', 'places', 'report'];

    /** الطوارئ تُخفى من القائمة (٢٦-٧) وتبقى في السجل للزر الأحمر */
    private const HIDDEN = ['sos', 'trigger', 'center', 'arrived', 'drill', 'teams', 'medical', 'systems'];

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

    /** أزرار «أريد أن» المعروضة في الصفحة الأولى بترتيبها، ومجموعاتها بترتيبها */
    private function shown(string $html): array
    {
        $start = strpos($html, 'id="intents"');
        $end = strpos($html, '<!-- intents:end -->');
        $this->assertNotFalse($start, 'قسم «أريد أن» غائب');
        $this->assertNotFalse($end, 'علامة نهاية «أريد أن» غائبة');
        $section = substr($html, $start, $end - $start);
        preg_match_all('~data-intent="([a-z_]+)"~', $section, $k);
        preg_match_all('~data-group="([^"]+)"~', $section, $g);
        return [$k[1], $g[1], $section];
    }

    public function test_intent_groups_mirror_inbox_groups_in_name_order_and_icon(): void
    {
        $groups = InboxService::GROUPS;
        $this->assertSame(['بلاغات الشاغلين', 'بلاغات الفحص', 'جولات الفحص', 'الطوارئ', 'الفريق الأولي', 'التصاريح', 'المخاطر', 'النماذج', 'المقاولون', 'الحسابات'], array_keys($groups));

        $unit = OrganizationUnit::first();
        foreach ([['salama', 'system_admin', null, null], ['mudir', 'department_manager', null, $unit->id], ['fani', 'field_worker', 'HZ-06', null], ['emp', 'employee', 'HZ-06', $unit->id]] as [$name, $role, $hz, $uid]) {
            $u = $this->user($name, $role, $hz, $uid);
            foreach (IntentRegistry::forUser($u) as $i) {
                $this->assertArrayHasKey($i->group, $groups, "$role: زر «{$i->label}» في مجموعة ليست من العشر: {$i->group}");
            }
            $html = $this->actingAs($u)->get('/app')->assertOk()->getContent();
            [$keys, $shownGroups, $section] = $this->shown($html);
            $this->assertNotEmpty($keys, "$role بلا أزرار");
            $this->assertSame(array_unique($shownGroups), $shownGroups, "$role: مجموعة مكررة");
            $order = array_values(array_intersect(array_keys($groups), $shownGroups));
            $this->assertSame($order, $shownGroups, "$role: ترتيب المجموعات ليس ترتيب «ما ينتظرك»");
            foreach ($shownGroups as $g) {
                $this->assertStringContainsString('data-group="'.$g.'" data-icon="'.$groups[$g].'"', $section, "$role: أيقونة «{$g}» ليست أيقونة ما ينتظرك");
                $this->assertMatchesRegularExpression('~data-group="'.preg_quote($g, '~').'"[^>]*>.*?data-intent="~s', $section, "$role: مجموعة «{$g}» فارغة ومعروضة");
            }
            foreach (array_merge(self::GONE, self::HIDDEN) as $k) $this->assertNotContains($k, $keys, "$role: زر «{$k}» ما زال في أريد أن");
        }
    }

    public function test_safety_officer_sees_exactly_what_he_starts_himself(): void
    {
        $salama = $this->user('salama', 'system_admin');
        [$keys] = $this->shown($this->actingAs($salama)->get('/app')->assertOk()->getContent());
        sort($keys);
        // ٢٦-٩: السجلات الأربعة التي خرجت من «المزيد» ولا باب لها في مكان أو رسم تُقرأ من هنا (١٥ + ٤)
        $expected = ['activate', 'book', 'competency', 'forms_log', 'gate', 'hazards', 'my_medical', 'my_reports', 'myforms', 'parties_log', 'party', 'plans', 'project', 'projects_log', 'roles', 'roles_map', 'sendform', 'worker', 'workers_log'];
        $this->assertSame($expected, $keys);
        // كل زر يفتح
        foreach (IntentRegistry::forUser($salama) as $i) {
            if (!str_starts_with($i->url, url('/app'))) continue;
            $this->assertContains($this->actingAs($salama)->get($i->url)->getStatusCode(), [200, 302], "زر «{$i->label}» ← {$i->url}");
        }
    }

    public function test_managers_have_one_accounts_button_and_no_place_bound_buttons(): void
    {
        $unit = OrganizationUnit::first();
        $mudir = $this->user('mudir', 'department_manager', null, $unit->id);
        [$keys, , $section] = $this->shown($this->actingAs($mudir)->get('/app')->assertOk()->getContent());
        $this->assertContains('my_accounts', $keys);
        $this->assertStringContainsString('منسق سلامة', $section);
        foreach (['my_coordinator', 'my_techs', 'nominate', 'units', 'permit'] as $k) $this->assertNotContains($k, $keys, $k);

        $marafiq = $this->user('marafiq', 'facilities_manager');
        [$keys, , $section] = $this->shown($this->actingAs($marafiq)->get('/app')->assertOk()->getContent());
        $this->assertContains('my_accounts', $keys);
        $this->assertStringContainsString('فنياً', $section);
        $this->assertNotContains('my_techs', $keys);

        $fani = $this->user('fani', 'field_worker', 'HZ-06');
        [$keys] = $this->shown($this->actingAs($fani)->get('/app')->assertOk()->getContent());
        $this->assertContains('inspect', $keys);
        $this->assertNotContains('makani', $keys);
        $this->assertNotContains('forms', $keys);
    }

    /** «أتابع بلاغاتي»: لصاحب الحساب زر في مجموعة بلاغات الشاغلين يفتح بلاغاته هو وحدها */
    public function test_my_reports_button_opens_my_own_reports_only(): void
    {
        $unit = OrganizationUnit::first();
        $emp = $this->user('emp', 'employee', 'HZ-06', $unit->id);
        $other = $this->user('emp2', 'employee', 'HZ-06', $unit->id);
        $this->actingAs($emp)->post('/incident/normal', ['description' => 'بلاط مكسور عند المدخل', 'place_id' => Place::idByCode('HZ-06')])->assertRedirect();
        $this->actingAs($other)->post('/incident/normal', ['description' => 'تسرب ماء في الدور الثاني', 'place_id' => Place::idByCode('HZ-06')])->assertRedirect();
        $mine = Incident::where('actor_id', $emp->id)->firstOrFail();
        $his = Incident::where('actor_id', $other->id)->firstOrFail();

        [$keys, , $section] = $this->shown($this->actingAs($emp)->get('/app')->assertOk()->getContent());
        $this->assertContains('my_reports', $keys);
        $this->assertMatchesRegularExpression('~data-group="بلاغات الشاغلين"[^>]*>.*?data-intent="my_reports"~s', $section);

        $p = $this->actingAs($emp)->get(route('incidents.mine'))->assertOk();
        $p->assertSee($mine->code)->assertSee('بلاط مكسور')->assertDontSee($his->code)->assertDontSee('تسرب ماء')
          ->assertSee('href="'.route('incidents.show', $mine).'"', false);
        $this->actingAs($emp)->get(route('incidents.show', $mine))->assertOk();
    }

    /** ما خرج من «أريد أن» لا يُخفى (قرار ٦١): نماذج الفحص كلها من ملف المكان، وطابور اعتماد المخاطر من داخل السجل */
    public function test_screens_that_left_the_menu_keep_a_door_inside_their_parent(): void
    {
        $salama = $this->user('salama', 'system_admin');
        $f = $this->actingAs($salama)->get('/app/places/'.Place::idByCode('HZ-06').'/file')->assertOk()->getContent();
        $this->assertStringContainsString('href="'.route('app.inspections').'"', $f);
        $r = $this->actingAs($salama)->get(route('risk.active.index'))->assertOk()->getContent();
        $this->assertStringContainsString('href="'.route('risk.approval.queue').'"', $r);
    }
}
