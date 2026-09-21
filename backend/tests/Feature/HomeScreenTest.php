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

        // ١ الأرقام: ينتظرك ١ (تصنيف البلاغ)، قبل الاستلام ١، بلا إقرار ٠، الفجوة «لا بيانات» لا صفر
        $this->assertMatchesRegularExpression('~data-tile="waiting".*?<span class="n">1</span>~s', $html);
        $this->assertMatchesRegularExpression('~data-tile="pending".*?<span class="n">1</span>~s', $html);
        $this->assertMatchesRegularExpression('~data-tile="unack".*?<span class="n[^"]*">0</span>~s', $html);
        $this->assertMatchesRegularExpression('~data-tile="gap".*?لا بيانات~s', $html);
        // ٢ الرسم بشهر واحد فيه بلاغ، والخريطة بالأماكن التسعة والمكاتب الإدارية أعلى درجة
        $this->assertStringContainsString('id="trend"', $html);
        $this->assertStringContainsString('data-month="'.now()->format('Y-m').'" data-count="1"', $html);
        $this->assertSame(9, preg_match_all('~class="place lvl\d"~', $html));
        $this->assertStringContainsString('class="place lvl3" data-place="HZ-06" data-count="1"', $html);
        // ٣ و٤ و٥ بالترتيب
        $this->assertStringContainsString($i->code, $html);
        $this->assertStringContainsString('دخول مسؤول السلامة', $html);
        $order = [strpos($html, 'id="tiles"'), strpos($html, 'id="homeDetails"'), strpos($html, 'id="inboxList"'), strpos($html, 'id="intents"'), strpos($html, 'id="recent"')];
        $this->assertSame($order, array_values(array_filter($order, fn ($p) => $p !== false)));
        $sorted = $order; sort($sorted);
        $this->assertSame($sorted, $order, 'الأجزاء الخمسة بترتيبها');
        // الزر الأحمر الثابت: مسؤول السلامة يملك «فعّل حالة طارئة»
        $this->assertMatchesRegularExpression('~id="sosBar".*?data-intent="trigger"~s', $html);
        $this->assertStringContainsString('<body class="has-sos">', $html);
    }

    public function test_technician_has_no_numbers_but_keeps_tasks_intents_and_sos(): void
    {
        $fani = $this->user('fani', 'field_worker', 'HZ-06');
        $html = $this->actingAs($fani)->get('/app')->assertOk()->getContent();
        $this->assertStringNotContainsString('id="tiles"', $html);
        $this->assertStringNotContainsString('id="homeDetails"', $html);
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
        $this->assertStringContainsString('أستغيث الآن', $html);
        $this->assertStringContainsString('<body class="has-sos">', $html);

        // القيادة تبقى على «فعّل حالة طارئة»، والزر يتبعها في كل شاشة لا الشاشة الأولى وحدها
        $salama = $this->user('salama', 'system_admin');
        $this->actingAs($salama)->get('/app/users')->assertOk()
            ->assertSee('id="sosBar"', false)
            ->assertSee('فعّل حالة طارئة', false);
    }

    public function test_guest_pages_carry_the_report_button_except_the_report_page_itself(): void
    {
        $this->get('/incident')->assertOk()->assertDontSee('id="sosBar"', false);
        $this->get('/incident/track')->assertOk()->assertSee('id="sosBar"', false)->assertSee('data-intent="report"', false);
    }
}
