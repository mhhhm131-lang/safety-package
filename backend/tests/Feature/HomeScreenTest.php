<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Governance\Models\AuditLog;
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
 * المرحلة ١٣-٢ (قرار ٣٩): الشاشة الأولى خمسة أجزاء بالترتيب — أرقام كبيرة · رسم وخريطة · ما ينتظرك · أريد أن… · آخر الإجراءات —
 * والزر الأحمر الثابت على الجوال. الأرقام لمن يملك `report.view` وحده، ولا رقم مخترع: «لا بيانات» حين لا بلاغ استُلم.
 */
class HomeScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // المبنى الرئيسي لازم لنية «فعّل حالة طارئة» (كما على المنشور)
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class, EmergencySeeder::class]);
    }

    private function user(string $username, string $role, ?string $placeCode = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'place_id' => Place::idByCode($placeCode)]);
        return $u;
    }

    public function test_safety_officer_sees_the_five_parts_in_order_with_honest_numbers(): void
    {
        $salama = $this->user('salama', 'system_admin');
        $this->post('/incident/normal', ['description' => 'بلاط مكسور قرب المصعد', 'place_id' => Place::idByCode('HZ-06')])->assertRedirect();
        $i = Incident::first();
        AuditLog::create(['user_id' => $salama->id, 'action' => 'login', 'model_name' => 'User', 'description' => 'دخول مسؤول السلامة', 'created_at' => now()]);

        $html = $this->actingAs($salama)->get('/app')->assertOk()->getContent();

        // ١ الأرقام (٢٦-١٠، قرار ٦٦ «أرقام واحدة»): لا أرقام كبيرة — ينتظرك ١ في عنوان قسمه، والحالات الطارئة ٠ في عمودها، والفجوة في التقارير «لا بيانات» لا صفر
        $this->assertStringNotContainsString('id="tiles"', $html);
        $this->assertStringNotContainsString('data-tile=', $html);
        $this->assertMatchesRegularExpression('~id="inboxCount">1<~', $html);
        $this->assertMatchesRegularExpression('~data-k="emergency" data-n="0"~', $html);
        $this->assertMatchesRegularExpression('~id="reportsDoor" href="'.preg_quote(route('reports.dashboard'), '~').'"~', $html);
        $this->assertMatchesRegularExpression('~data-kpi="incident_avg">\s*لا بيانات~u', $this->actingAs($salama)->get(route('reports.dashboard'))->assertOk()->getContent());
        // ٢ الأماكن التسعة مربعات تُضغط (٢٥-١)، والرسم «حال الآن» بستة أعمدة وبلاغ شاغل واحد مفتوح (٢٥-٢) — لا رسم شهري
        $this->assertSame(9, preg_match_all('~class="pl-tile [a-z]+" href="[^"]+" data-place="HZ-~', $html)); // ٢٦-٧: مربع HZ-00 يفتح صفحة المركز لا ملفاً
        $this->assertStringContainsString('href="'.route('emergency.dashboard').'" data-place="HZ-00"', $html);
        $this->assertStringContainsString('id="chart"', $html);
        $this->assertMatchesRegularExpression('~data-k="incidents" data-n="1"~', $html);
        $this->assertStringNotContainsString('id="trend"', $html);
        // ٣ و٤ و٥ بالترتيب
        $this->assertStringContainsString($i->code, $html);
        $this->assertStringContainsString('دخول مسؤول السلامة', $html);
        // ٢٦-١ (قرار ٦٦): الأماكن ← الرسم ← ما ينتظرك ← أريد أن…
        $order = [strpos($html, 'id="places"'), strpos($html, 'id="chart"'), strpos($html, 'id="inboxList"'), strpos($html, 'id="intents"'), strpos($html, 'id="recent"')];
        $this->assertSame($order, array_values(array_filter($order, fn ($p) => $p !== false)));
        $sorted = $order; sort($sorted);
        $this->assertSame($sorted, $order, 'الأجزاء الخمسة بترتيبها');
        // ٢٦-٣: الزر الأحمر واحد للجميع «طوارئ الآن» — والتفعيل داخل صفحة الاستغاثة لمن يملكه
        $this->assertMatchesRegularExpression('~id="sosBar".*?data-intent="sos"~s', $html);
        $this->assertStringContainsString('<body class="has-sos">', $html);
    }

    /** ٢٥-١ (قرار ٦٤): كان «الفني بلا أرقام»؛ صار يرى مكانه مربعاً يُضغط ورسم حاله (٢٥-٢)، والأرقام الكبيرة حُذفت للجميع (٢٦-١٠) */
    public function test_technician_sees_his_place_without_report_numbers_and_keeps_tasks_intents_and_sos(): void
    {
        $fani = $this->user('fani', 'field_worker', 'HZ-06');
        $html = $this->actingAs($fani)->get('/app')->assertOk()->getContent();
        $this->assertStringNotContainsString('id="tiles"', $html);
        $this->assertStringContainsString('id="chart"', $html);
        $this->assertSame(1, substr_count($html, 'data-place="HZ-'));
        $this->assertStringContainsString('data-place="HZ-06"', $html);
        $this->assertStringContainsString('لا شيء ينتظرك الآن', $html);
        $this->assertStringContainsString('id="intents"', $html);
        // لا سجل تدقيق للفني ← «آخر الإجراءات» من إشعاراته، ولا إشعار له بعد ← القسم لا يظهر
        $this->assertStringNotContainsString('id="recent"', $html);
        // الفني يملك emergency.respond بلا trigger ← «أستغيث الآن»
        $this->assertMatchesRegularExpression('~id="sosBar".*?data-intent="sos"~s', $html);
    }

    /**
     * ٢٢-٣ (د، ٢٠٢٦-٠٩-٢٢): كان هذا الاختبار يؤكّد أن الموظف **بلا** زر أحمر، وهو السلوك الذي غيّرته
     * المرحلة عمداً: الاستغاثة صارت لكل حساب مفعَّل بعد أن كانت مبنية في الخلفية ولا تُرى إلا داخل
     * لوحة مركز الطوارئ. فصار الاختبار يحرس الأولوية الجديدة: كلٌّ يأخذ زره الصحيح.
     */
    public function test_every_account_has_the_right_red_bar_and_it_follows_every_screen(): void
    {
        $emp = $this->user('emp', 'employee');
        $html = $this->actingAs($emp)->get('/app')->assertOk()->getContent();
        $this->assertStringContainsString('id="sosBar"', $html);
        $this->assertStringContainsString('طوارئ الآن', $html);
        $this->assertStringContainsString('<body class="has-sos">', $html);

        // ٢٦-٣: الزر نفسه للقيادة، ويتبعها في كل شاشة لا الشاشة الأولى وحدها
        $salama = $this->user('salama', 'system_admin');
        $this->actingAs($salama)->get('/app/users')->assertOk()
            ->assertSee('id="sosBar"', false)
            ->assertSee('طوارئ الآن', false);
    }

    public function test_guest_pages_carry_the_report_button_except_the_report_page_itself(): void
    {
        // ٢٦-٣: الضيف زره «طوارئ الآن» = اتصال، في صفحة البلاغ والتتبع؛ ولا شريط فوق نموذج البلاغ نفسه
        $this->get('/incident')->assertOk()->assertSee('id="sosBar"', false)->assertSee('data-intent="emergency-call"', false);
        $this->get('/incident/track')->assertOk()->assertSee('id="sosBar"', false)->assertSee('data-intent="emergency-call"', false);
        $this->get('/incident/normal')->assertOk()->assertDontSee('id="sosBar"', false);
    }
}
