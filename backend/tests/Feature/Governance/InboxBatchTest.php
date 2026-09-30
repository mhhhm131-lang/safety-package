<?php

namespace Tests\Feature\Governance;

use App\Core\Inbox\Batch;
use App\Core\Inbox\InboxService;
use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Services\EmergencyService;
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
 * ٢٦-١١ (قرار ٦٦، بكلمته «موافق كمل» ٢٠٢٦-٠٩-٣٠): البطاقات المتطابقة في «ما ينتظرك» — السؤال نفسه عن المكان نفسه ولا يميّزها إلا الرقم —
 * تصير بطاقة واحدة بعددها («بلاغان في القبو: لا فني للمكان»)، زرها يفتح بنودها تحتها، وكل بند برقمه وعنوانه وزره كما كان.
 * لا يُحذف شيء، والعدّادات تبقى عدد الأشياء؛ ما له اسم يميّزه لا يُدمج.
 */
class InboxBatchTest extends TestCase
{
    use RefreshDatabase;

    private User $salama;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class, EmergencySeeder::class]);
        $this->salama = $this->user('salama', 'system_admin');
    }

    private function user(string $username, string $role, ?string $placeCode = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'place_id' => Place::idByCode($placeCode)]);
        return $u;
    }

    private function report(string $text, string $hz): Incident
    {
        $this->post('/incident/normal', ['description' => $text, 'place_id' => Place::idByCode($hz)])->assertRedirect();
        return Incident::latest('id')->firstOrFail();
    }

    private function home(?User $u = null): string
    {
        return $this->actingAs($u ?? $this->salama)->get('/app')->assertOk()->getContent();
    }

    /** قسم «بلاغات الشاغلين» من الصفحة */
    private function section(string $html, string $module = 'بلاغات الشاغلين'): string
    {
        $this->assertSame(1, preg_match('~<section data-module="'.preg_quote($module, '~').'".*?</section>~s', $html, $m), "لا قسم «{$module}»");
        return $m[0];
    }

    public function test_two_identical_cards_in_one_place_become_one_card_that_opens_its_items(): void
    {
        $a = $this->report('تسرب ماء عند المدخل الشرقي', 'HZ-01');
        $b = $this->report('إنارة معطلة في الممر الثاني', 'HZ-01');
        $html = $this->home();
        $sec = $this->section($html);

        // بطاقة واحدة بدل اثنتين، بجملة العدد والمكان والحال
        $this->assertSame(1, substr_count($sec, 'class="card task '), 'البطاقتان لم تُدمجا');
        $this->assertMatchesRegularExpression('~data-batch="incident\.refer" data-n="2"~', $sec);
        $this->assertStringContainsString('بلاغان في HZ-01 القبو ومواقف السيارات: لا فني للمكان', $sec);
        // زر واحد يفتح البنود في مكانها (مطوية عند الفتح)
        $this->assertSame(1, preg_match('~<button class="btn btn-g collapsed"[^>]*data-bs-toggle="collapse" data-bs-target="#(batch-[a-z0-9-]+)"[^>]*>\s*اعرضهما~u', $sec, $m));
        $this->assertStringContainsString('<div class="collapse" id="'.$m[1].'"', $sec);
        // كل بند برقمه وعنوانه وزره كما كان
        foreach ([$a, $b] as $i) {
            $this->assertMatchesRegularExpression('~<div class="batch-row[^"]*" data-task="incident:'.$i->id.':center">.*?'.preg_quote($i->code, '~').'.*?'.preg_quote(mb_substr($i->title, 0, 20), '~').'.*?>أحِله</a>~su', $sec);
            $this->assertStringContainsString(rawurlencode(route('incidents.show', $i)), $sec);
        }
        // العدّادات تعدّ الأشياء لا البطاقات
        $this->assertMatchesRegularExpression('~id="inboxCount">2<~', $html);
        $this->assertMatchesRegularExpression('~data-module="بلاغات الشاغلين" data-n="2"~', $html);
        $this->assertSame(2, app(InboxService::class)->countFor($this->salama));
    }

    public function test_a_single_card_and_another_place_are_not_merged(): void
    {
        $this->report('تسرب ماء عند المدخل الشرقي', 'HZ-01');
        $this->report('إنارة معطلة في الممر الثاني', 'HZ-01');
        $lone = $this->report('كرسي مكسور في المكتب', 'HZ-06');
        $sec = $this->section($this->home());

        $this->assertSame(2, substr_count($sec, 'class="card task '), 'دفعة القبو + بطاقة المكاتب');
        $this->assertSame(1, substr_count($sec, 'data-batch='));
        // بطاقة المكان الآخر كما كانت: سؤالها وزرها
        $this->assertMatchesRegularExpression('~<div class="card task[^"]*" data-task="incident:'.$lone->id.':center">.*?لا فني للمكان — أحِله~su', $sec);
        $this->assertStringNotContainsString('HZ-06 المكاتب الإدارية: لا فني للمكان<', $sec);
    }

    public function test_count_words_and_the_late_mark_follow_the_items(): void
    {
        $this->assertSame('بلاغان في القبو: لا فني للمكان', Batch::question('incident.refer', 2, 'القبو'));
        $this->assertSame('3 بلاغات في القبو: لا فني للمكان', Batch::question('incident.refer', 3, 'القبو'));
        $this->assertSame('10 بلاغات في القبو: لا فني للمكان', Batch::question('incident.refer', 10, 'القبو'));
        $this->assertSame('14 بلاغاً في القبو: لا فني للمكان', Batch::question('incident.refer', 14, 'القبو'));
        $this->assertSame('حالتان في المكاتب: لا تقرير بعد الانتهاء', Batch::question('emergency.aar', 2, 'المكاتب'));
        $this->assertSame('27 حالة: لا تقرير بعد الانتهاء', Batch::question('emergency.aar', 27, null));

        $first = $this->report('تسرب ماء عند المدخل الشرقي', 'HZ-01');
        $this->report('إنارة معطلة في الممر الثاني', 'HZ-01');
        $this->report('باب الطوارئ لا يُغلق', 'HZ-01');
        $sec = $this->section($this->home());
        $this->assertStringContainsString('3 بلاغات في HZ-01 القبو ومواقف السيارات: لا فني للمكان', $sec);
        $this->assertMatchesRegularExpression('~اعرضها~u', $sec);
        $this->assertStringNotContainsString('task-late', $sec);

        $first->update(['deadline_at' => now()->subHour()]);
        $sec = $this->section($this->home());
        $this->assertMatchesRegularExpression('~class="card task task-late[^"]*" data-batch="incident\.refer" data-n="3" data-od="1"~', $sec);
        $this->assertStringContainsString('1 متأخر', $sec);
    }

    /** البنود ذات الفعل المباشر تحتفظ بأزرارها؛ والفعل على بند ينقص الدفعة، وحين يبقى واحد يعود بطاقة عادية */
    public function test_post_actions_stay_per_item_and_the_batch_shrinks_as_items_are_done(): void
    {
        $building = EmergencyBuilding::main();
        $svc = app(EmergencyService::class);
        $cases = [];
        foreach ([1, 2, 3] as $n) {
            $inc = $svc->triggerAlarm($building, 'fire', $this->salama, 'high', false, "حالة اختبار $n", Place::idByCode('HZ-06'));
            $svc->endIncident($inc->fresh(), $this->salama, 'انتهى');
            $cases[] = $inc->fresh();
        }
        $sec = $this->section($this->home(), 'الطوارئ');
        $this->assertMatchesRegularExpression('~data-batch="emergency\.aar" data-n="3"~', $sec);
        $this->assertStringContainsString('3 حالات في المكاتب الإدارية: لا تقرير بعد الانتهاء', $sec);
        foreach ($cases as $c) {
            $this->assertMatchesRegularExpression('~data-task="aarmissing:'.$c->id.'">.*?'.preg_quote($c->incident_code, '~').'.*?<form method="post" action="'.preg_quote(route('emergency.incidents.aar', $c), '~').'"[^>]*>.*?<button class="btn btn-g[^"]*">اكتبه</button>~su', $sec);
        }

        $this->actingAs($this->salama)->post(route('emergency.incidents.aar', $cases[0]))->assertRedirect();
        $sec = $this->section($this->home(), 'الطوارئ');
        $this->assertMatchesRegularExpression('~data-batch="emergency\.aar" data-n="2"~', $sec);
        $this->assertStringContainsString('حالتان في المكاتب الإدارية: لا تقرير بعد الانتهاء', $sec);

        $this->actingAs($this->salama)->post(route('emergency.incidents.aar', $cases[1]))->assertRedirect();
        $sec = $this->section($this->home(), 'الطوارئ');
        $this->assertStringNotContainsString('data-batch=', $sec);
        $this->assertMatchesRegularExpression('~<div class="card task[^"]*" data-task="aarmissing:'.$cases[2]->id.'">.*?ولا تقرير بعدها — اكتبه~su', $sec);
    }

    /** ما يميّزه اسمه لا يُدمج: الفني يرى بلاغيه المحالين إليه بعنوانيهما بطاقتين */
    public function test_cards_that_carry_their_own_name_are_not_merged(): void
    {
        $fani = $this->user('fani', 'field_worker', 'HZ-01');
        $a = $this->report('تسرب ماء عند المدخل الشرقي', 'HZ-01');
        $b = $this->report('إنارة معطلة في الممر الثاني', 'HZ-01');
        foreach ([$a, $b] as $i) $i->update(['status' => 'forwarded', 'incident_field_team_id' => $fani->id]);

        $sec = $this->section($this->home($fani));
        $this->assertStringNotContainsString('data-batch=', $sec);
        $this->assertSame(2, substr_count($sec, 'class="card task '));
        $this->assertStringContainsString('تسرب ماء عند المدخل الشرقي', $sec);
        $this->assertStringContainsString('إنارة معطلة في الممر الثاني', $sec);
    }
}
