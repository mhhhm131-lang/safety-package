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
 * ٢٦-١٢ (قرار ٦٦ «اسم واحد لكل شاشة»، بكلمته «أنجز البنود الثلاثة المتبقية» ٢٠٢٦-٠٩-٣٠): الاسم المكتوب على الباب هو عنوان الشاشة التي يفتحها.
 * يُقاس على الأبواب المسمّاة لمسؤول السلامة: «المزيد»، صفحة الإعدادات، أبواب صفحة المركز، وأزرار السجلات في «أريد أن».
 * أزرار الأفعال («أسجّل عاملاً»، «أتابع بلاغاتي») أفعال لا أسماء شاشات (قرار ٦٧) فليست في القياس، وصفحة الكيان باسم كيانه (ملف المكان، المبنى).
 */
class OneNamePerScreenTest extends TestCase
{
    use RefreshDatabase;

    /** أزرار «أريد أن» التي هي اسم سجل أو شاشة لا فعل */
    private const NAMED_INTENTS = ['book', 'forms_log', 'projects_log', 'parties_log', 'workers_log', 'competency', 'roles_map'];

    private User $salama;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class, EmergencySeeder::class]);
        $this->salama = User::create(['username' => 'salama', 'name' => 'مسؤول السلامة', 'password' => '1234', 'email' => 'salama@example.test']);
        UserProfile::create(['user_id' => $this->salama->id, 'role' => 'system_admin', 'is_active' => true]);
    }

    private function page(string $url): string
    {
        return $this->actingAs($this->salama)->get($url)->assertOk()->getContent();
    }

    /** @return array<int, array{0:string,1:string,2:string}> [الموضع، الاسم، الرابط] */
    private function doors(): array
    {
        $out = [];
        $home = $this->page('/app');
        $this->assertSame(1, preg_match('~<aside[^>]*id="moreNav".*?</aside>~s', $home, $m));
        preg_match_all('~<a href="([^"]+)"[^>]*><i class="bi [^"]+"></i>([^<]+)</a>~u', $m[0], $a, PREG_SET_ORDER);
        foreach ($a as $x) $out[] = ['المزيد', trim($x[2]), $x[1]];

        preg_match_all('~<a class="btn btn-o btn-sm" href="([^"]+)" data-intent="([a-z_]+)"[^>]*><i class="bi [^"]+"></i>\s*([^<]+)</a>~u', $home, $a, PREG_SET_ORDER);
        foreach ($a as $x) if (in_array($x[2], self::NAMED_INTENTS, true)) $out[] = ['أريد أن', trim($x[3]), $x[1]];

        preg_match_all('~<a class="list-group-item[^"]*" href="([^"]+)"><div class="fw-bold">([^<]+)</div>~u', $this->page(route('app.settings')), $a, PREG_SET_ORDER);
        foreach ($a as $x) $out[] = ['الإعدادات', trim($x[2]), $x[1]];

        preg_match_all('~<a class="btn btn-sm btn-o" href="([^"]+)" data-door="([^"]+)">~u', $this->page(route('emergency.dashboard')), $a, PREG_SET_ORDER);
        foreach ($a as $x) $out[] = ['المركز', html_entity_decode($x[2]), $x[1]];
        return $out;
    }

    public function test_the_name_on_the_door_is_the_title_of_the_screen_it_opens(): void
    {
        $doors = $this->doors();
        $this->assertGreaterThan(45, count($doors), 'أبواب قليلة — القياس لم يلتقط القوائم');
        $entity = route('emergency.buildings.show', \App\Modules\Emergency\Models\EmergencyBuilding::main()); // صفحة كيان: عنوانها اسم المبنى
        $bad = [];
        foreach ($doors as [$where, $label, $url]) {
            $url = html_entity_decode($url);
            if ($url === $entity) continue;
            $html = $this->page($url);
            $this->assertSame(1, preg_match('~<title>(.*?) — معهد الإدارة العامة</title>~su', $html, $t), "$where «{$label}»: لا عنوان للصفحة");
            if (trim($t[1]) !== $label) $bad[] = "$where: الباب «{$label}» والشاشة «".trim($t[1]).'»';
        }
        $this->assertSame([], $bad, "أبواب اسمها غير اسم شاشتها:\n".implode("\n", $bad));
    }

    /** الشاشة الواحدة باسم واحد في أبوابها كلها: الرابط نفسه لا يحمل اسمين في موضعين */
    public function test_one_screen_does_not_carry_two_names_across_the_menus(): void
    {
        $byUrl = [];
        foreach ($this->doors() as [$where, $label, $url]) $byUrl[html_entity_decode($url)][$label][] = $where;
        $two = array_filter($byUrl, fn ($names) => count($names) > 1);
        $this->assertSame([], array_map(fn ($names) => implode(' / ', array_keys($names)), $two));
    }

    public function test_the_two_decided_names_and_the_risk_register_replace_their_old_names_everywhere(): void
    {
        // سجل بلاغات الشاغلين: عنوان الشاشة ورأسها، ومسار الرجوع من صفحة البلاغ
        $reg = $this->page(route('incidents.index'));
        $this->assertStringContainsString('<title>سجل بلاغات الشاغلين — ', $reg);
        $this->assertMatchesRegularExpression('~<h1[^>]*>\s*سجل بلاغات الشاغلين~u', $reg);
        $this->post('/incident/normal', ['description' => 'بلاط مكسور قرب المصعد', 'place_id' => Place::idByCode('HZ-06')])->assertRedirect();
        $i = \App\Modules\Incident\Models\Incident::firstOrFail();
        $this->assertMatchesRegularExpression('~<a href="'.preg_quote(route('incidents.index'), '~').'"[^>]*>سجل بلاغات الشاغلين</a>~u', $this->page(route('incidents.show', $i)));

        // مركز السلامة وإدارة الطوارئ: أزرار الرجوع في شاشات الطوارئ باسمه
        foreach ([route('emergency.incidents.index'), route('emergency.settings'), route('emergency.iot.dashboard')] as $url) {
            $this->assertMatchesRegularExpression('~<a[^>]*href="'.preg_quote(route('emergency.dashboard'), '~').'"[^>]*>مركز السلامة وإدارة الطوارئ</a>~u', $this->page($url), $url);
        }

        // السجل العام للمعهد: الزر والشاشة وما يتفرع منها
        $this->assertStringContainsString('<title>السجل العام للمعهد — ', $this->page(route('risk.reference.index')));
        $this->assertStringContainsString('السجل العام للمعهد', $this->page(route('risk.reference.create')));

        // الأسماء القديمة لا تبقى في نص أي شاشة (التعليقات تاريخ لا يُعرض)
        $old = ['سجل مركز السلامة', 'مركز الطوارئ', 'ما يجري الآن', 'السجل المرجعي', 'مصفوفة الكفاءة', 'تحليلات الطوارئ'];
        $left = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $f) {
            if (!str_ends_with($f->getFilename(), '.blade.php')) continue;
            $src = preg_replace('~\{\{--.*?--\}\}~s', '', file_get_contents($f->getPathname()));
            foreach ($old as $o) if (str_contains($src, $o)) $left[] = $o.' ← '.str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $f->getPathname());
        }
        foreach (['app/Core/Intents/IntentRegistry.php', 'app/Modules/Governance/Controllers/SettingsController.php', 'app/Modules/Governance/Controllers/SearchController.php'] as $rel) {
            $src = implode('', array_map(fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all(file_get_contents(base_path($rel)))));
            foreach ($old as $o) if (str_contains($src, $o)) $left[] = $o.' ← '.$rel;
        }
        $this->assertSame([], $left);
    }

    /** الكسرة تحت الحاء كانت تُقرأ نقطةً على الشاشة («أجله») — الزر يُكتب بلا شكل */
    public function test_refer_button_is_written_without_the_mark_that_reads_as_a_dot(): void
    {
        $this->post('/incident/normal', ['description' => 'بلاط مكسور قرب المصعد', 'place_id' => Place::idByCode('HZ-06')])->assertRedirect();
        $home = $this->page('/app');
        $this->assertStringContainsString('>أحله</a>', $home);
        $this->assertStringNotContainsString('أحِل', $home);
    }
}
