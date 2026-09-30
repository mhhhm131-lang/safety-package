<?php

namespace Tests\Feature\Governance;

use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Project\Models\ExternalParty;
use Database\Seeders\AffectedGroupsSeeder;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ٢٦-٩ (قرار ٦٦): «المزيد» للإعدادات فقط — تسعة روابط لمسؤول السلامة: الإعدادات، المستخدمون، الهيكل التنظيمي،
 * الأماكن، سجل التدقيق، البريد، الإغلاق والتسليم، مهل البلاغات، مهل التصعيد. من لا إعداد له لا زر «المزيد» عنده.
 *
 * ولا يُخفى شيء (قرار ٦١): كل شاشة خرجت من القائمة لها باب لكل دور يملكها — «أريد أن» (قرار ٦٧: كل ما أبدؤه له باب)،
 * أو الرسم (سجل التصاريح)، أو ملف المكان (مخاطر المكان)، أو الجرس (الإشعارات).
 */
class MoreSettingsOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class, EmergencySeeder::class]);
    }

    private function user(string $username, string $role, ?string $placeCode = 'HZ-06', ?int $partyId = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test", 'external_party_id' => $partyId]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'place_id' => Place::idByCode($placeCode), 'organization_unit_id' => OrganizationUnit::first()->id]);
        return $u;
    }

    /** روابط «المزيد» بترتيبها، أو null إن لم تكن القائمة في الصفحة */
    private function more(string $html): ?array
    {
        if (!preg_match('~<aside[^>]*id="moreNav".*?</aside>~s', $html, $m)) return null;
        preg_match_all('~<a href="([^"]+)"~', $m[0], $h);
        return $h[1];
    }

    /** الصفحة بلا القائمة — ما يُرى بلا فتح «المزيد» */
    private function withoutMore(string $html): string
    {
        return preg_replace('~<aside[^>]*id="moreNav".*?</aside>~s', '', $html);
    }

    /** الإعدادات التي يملكها الدور، بترتيب القائمة */
    private function settingsFor(string $role): array
    {
        $can = fn (string $p) => PermissionRegistry::hasPermission($role, $p);
        return array_values(array_filter([
            $can('system.settings') ? route('app.settings') : null,
            $can('system.users') ? route('app.users.index') : null,
            $can('system.org') ? route('app.org.index') : null,
            $can('system.settings') ? route('app.places.index') : null,
            $can('system.audit') ? route('app.audit') : null,
            $can('system.settings') ? route('app.mail.index') : null,
            $can('system.settings') ? route('app.closeout.index') : null,
            $can('system.settings') ? route('incidents.settings') : null,
            $can('emergency.manage') ? route('emergency.settings') : null,
        ]));
    }

    public function test_safety_officer_more_is_nine_settings_links(): void
    {
        $salama = $this->user('salama', 'system_admin', null);
        $links = $this->more($this->actingAs($salama)->get('/app')->assertOk()->getContent());
        $this->assertSame([
            route('app.settings'), route('app.users.index'), route('app.org.index'), route('app.places.index'), route('app.audit'),
            route('app.mail.index'), route('app.closeout.index'), route('incidents.settings'), route('emergency.settings'),
        ], $links);
        foreach ($links as $url) $this->actingAs($salama)->get($url)->assertOk();
    }

    public function test_each_role_sees_only_its_settings_and_no_button_when_it_has_none(): void
    {
        foreach (array_keys(PermissionRegistry::ROLES) as $n => $role) {
            $u = $this->user("u$n", $role);
            $html = $this->actingAs($u)->get('/app')->assertOk()->getContent();
            $expected = $this->settingsFor($role);
            if ($expected) {
                $this->assertSame($expected, $this->more($html), "$role: روابط «المزيد» ليست إعداداته");
                $this->assertStringContainsString('id="navMore"', $html, "$role: زر «المزيد» غائب");
            } else {
                $this->assertNull($this->more($html), "$role: قائمة «المزيد» معروضة بلا إعدادات");
                $this->assertStringNotContainsString('id="navMore"', $html, "$role: زر «المزيد» يفتح قائمة فارغة");
            }
        }
        // المناوب والمنسق: إعداداتهما القليلة لا أكثر
        $this->assertCount(3, $this->settingsFor('system_staff'));
        $this->assertCount(1, $this->settingsFor('safety_coordinator'));
        $this->assertCount(0, $this->settingsFor('employee'));
    }

    /** قرار ٦١: ما خرج من «المزيد» له باب في الصفحة الأولى لكل دور يملكه، والباب يفتح */
    public function test_every_screen_that_left_more_keeps_a_door_for_every_role_that_owns_it(): void
    {
        $doors = [
            'risk.list'           => ['السجل العام للمعهد' => route('risk.reference.index')],
            'form.list'           => ['النماذج الرقمية' => route('forms.index')],
            'project.list'        => ['المشاريع' => route('projects.index')],
            'external_party.list' => ['الأطراف الخارجية' => route('external-parties.index')],
            'worker.list'         => ['العمال' => route('workers.index')],
            'competency.view'     => ['الكفاءات والمهن' => route('competency.matrix')],
            'permit.list'         => ['جاهزية العامل' => route('permits.gate')],
            'report.view'         => ['التقارير' => route('reports.dashboard')],
        ];
        // كل دور مرتين: بمكان ووحدة، ثم أضعف حساب — بلا مكان ولا وحدة (لا مربعات عنده ولا رسم)
        $cases = [];
        foreach (array_keys(PermissionRegistry::ROLES) as $n => $role) {
            $cases[] = [$role, $this->user("u$n", $role)];
            $bare = User::create(['username' => "b$n", 'name' => "اسم b$n", 'password' => '1234', 'email' => "b$n@example.test"]);
            UserProfile::create(['user_id' => $bare->id, 'role' => $role, 'is_active' => true]);
            $cases[] = [$role.' (بلا مكان)', $bare];
        }
        foreach ($cases as [$role, $u]) {
            $can = fn (string $p) => PermissionRegistry::hasPermission($u->role(), $p);
            $page = $this->withoutMore($this->actingAs($u)->get('/app')->assertOk()->getContent());
            $hasPlaces = str_contains($page, 'class="pl-tile');

            // لكل حساب: نماذجه، وإشعاراته من الجرس
            $this->assertStringContainsString('href="'.route('forms.mine').'"', $page, "$role: «نماذجي» بلا باب");
            $this->assertStringContainsString('class="bell" href="'.route('app.notifications.index').'"', $page, "$role: الجرس غائب");

            foreach ($doors as $perm => $screens) {
                if (!$can($perm)) continue;
                foreach ($screens as $name => $url) {
                    $this->assertStringContainsString('href="'.$url.'"', $page, "$role: «{$name}» بلا باب بعد خروجها من المزيد");
                    $this->actingAs($u)->get($url)->assertOk();
                }
            }
            // سجل التصاريح من عمود الرسم «تصاريح فعالة»؛ ومن لا رسم عنده فزرّ في «أريد أن» — باب واحد لا بابان
            if ($can('permit.list')) {
                $bar = (bool) preg_match('~data-k="permits"[^>]*href="'.preg_quote(route('permits.index'), '~').'\?~', $page);
                $btn = str_contains($page, 'href="'.route('permits.index').'" data-intent="permits_log"');
                $this->assertSame($hasPlaces, $bar, "$role: عمود «تصاريح فعالة»");
                $this->assertSame(!$hasPlaces, $btn, "$role: زر «سجل التصاريح» — يظهر لمن لا رسم عنده وحده");
                $this->actingAs($u)->get(route('permits.index'))->assertOk();
            }
            // مخاطر الإدارات والأماكن من ملف المكان (البند ٤)؛ ومن لا مكان في نطاقه فزرّ في «أريد أن»
            if ($can('risk.list')) {
                $btn = str_contains($page, 'href="'.route('risk.active.index').'" data-intent="risks_log"');
                $this->assertSame(!$hasPlaces, $btn, "$role: زر «مخاطر الإدارات والأماكن» — يظهر لمن لا مكان في نطاقه وحده");
                if ($hasPlaces) {
                    preg_match('~class="pl-tile[^"]*" href="([^"]+/file)"~', $page, $m);
                    $this->assertNotEmpty($m, "$role: لا مربع مكان يفتح ملفاً");
                    $file = $this->actingAs($u)->get($m[1])->assertOk()->getContent();
                    $this->assertMatchesRegularExpression('~href="'.preg_quote(route('risk.active.index'), '~').'\?place=HZ-0\d"~', $file, "$role: «مخاطر الإدارات والأماكن» بلا باب في ملف المكان");
                }
                $this->actingAs($u)->get(route('risk.active.index'))->assertOk();
            }
        }
    }

    /** السجل قراءةً والإنشاء فعلاً سؤالان (قرار ٦٧-٥): مدير الإدارة يقرأ السجلات ولا يملك الإنشاء */
    public function test_manager_reads_registers_from_want_to_without_create_buttons(): void
    {
        $mudir = $this->user('mudir', 'department_manager', null);
        $page = $this->withoutMore($this->actingAs($mudir)->get('/app')->assertOk()->getContent());
        foreach (['projects_log', 'parties_log', 'workers_log', 'forms_log'] as $k) $this->assertStringContainsString('data-intent="'.$k.'"', $page, $k);
        foreach (['project', 'party', 'worker', 'sendform'] as $k) $this->assertStringNotContainsString('data-intent="'.$k.'"', $page, $k);
        $this->assertMatchesRegularExpression('~data-group="المقاولون"[^>]*>.*?data-intent="projects_log".*?data-intent="parties_log".*?data-intent="workers_log"~s', $page);
        $this->assertMatchesRegularExpression('~data-group="النماذج"[^>]*>.*?data-intent="forms_log"~s', $page);
    }

    /** حساب المقاول يفتح على بوابته (لا يمر بالصفحة الأولى): ما كان يجده في «المزيد» يجده في «أريد أن» أسفل بوابته */
    public function test_contractor_portal_carries_want_to_so_nothing_is_lost(): void
    {
        $party = ExternalParty::create(['name' => 'شركة الصيانة', 'party_type' => 'contractor', 'cr_number' => '1010123456', 'contact_person' => 'سعد', 'phone' => '0500000001']);
        $sup = $this->user('mushrif', 'contractor_supervisor', null, $party->id);
        $this->actingAs($sup)->get('/app')->assertRedirect(route('contractor.home'));
        $html = $this->actingAs($sup)->get(route('contractor.home'))->assertOk()->getContent();
        $this->assertNull($this->more($html));
        $this->assertStringNotContainsString('id="navMore"', $html);
        foreach (['myforms' => route('forms.mine'), 'projects_log' => route('projects.index'), 'parties_log' => route('external-parties.index'), 'competency' => route('competency.matrix'), 'gate' => route('permits.gate')] as $k => $url) {
            $this->assertMatchesRegularExpression('~href="'.preg_quote($url, '~').'" data-intent="'.$k.'"~', $html, $k);
            $this->actingAs($sup)->get($url)->assertOk();
        }
        // البوابة لا تعرض زراً يفتحها هي
        $this->assertStringNotContainsString('data-intent="portal"', $html);
    }
}
