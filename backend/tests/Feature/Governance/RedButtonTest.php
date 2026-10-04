<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ٢٦-٣ (قرار ٦٦، بكلمته «موافق»): زر أحمر واحد «طوارئ الآن» في كل شاشة. بحساب: يفتح الاستغاثة (النوع + الاسم + المكان)
 * ومعها الاتصال، ومن يملك التفعيل يجد فيها «فعّل حالة طارئة في المبنى». بلا حساب: يتصل بالمركز مباشرة.
 * بطاقة «عاجل» تختفي من صفحة البلاغ (الزر الأحمر يقوم مقامها). أثناء حالة مفتوحة تخصّه يبقى الزر شاشته.
 */
class RedButtonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
    }

    private function user(string $username, string $role, ?string $placeCode = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'place_id' => Place::idByCode($placeCode)]);
        return $u;
    }

    public function test_one_red_button_for_accounts_opens_the_sos_page_with_call_and_trigger_for_commanders(): void
    {
        $emp = $this->user('emp', 'employee', 'HZ-06');
        $salama = $this->user('salama', 'system_admin', 'HZ-00');

        foreach ([$emp, $salama] as $u) {
            $h = $this->actingAs($u)->get('/app')->assertOk()->getContent();
            $this->assertMatchesRegularExpression('~<div class="sos-bar" id="sosBar"><a class="btn btn-red" href="'.preg_quote(route('emergency.sos'), '~').'" data-intent="sos">.*?طوارئ الآن</a>~s', $h, $u->username);
            $this->assertStringNotContainsString('أستغيث الآن', $h);
            $this->assertStringNotContainsString('<body class="has-sos">'.'', ''); // (لا شيء) — الجسم يحمل has-sos
            $this->assertStringContainsString('<body class="has-sos">', $h);
        }
        // الزر نفسه في كل شاشة، ولمسؤول السلامة أيضاً (لا «فعّل» في الشريط)
        $this->actingAs($salama)->get('/app/users')->assertOk()->assertSee('data-intent="sos"', false)->assertDontSee('data-intent="trigger"', false);

        // صفحة الاستغاثة: الأنواع + الاتصال؛ والتفعيل لمن يملكه فقط
        $s = $this->actingAs($salama)->get(route('emergency.sos'))->assertOk()->getContent();
        $this->assertStringContainsString('طوارئ الآن', $s);
        $this->assertStringContainsString('href="tel:'.Place::CENTER_PHONE.'"', $s);
        $this->assertStringContainsString('فعّل حالة طارئة في المبنى', $s);
        $this->assertStringContainsString('/app/emergency/buildings/', $s);
        $e = $this->actingAs($emp)->get(route('emergency.sos'))->assertOk()->getContent();
        $this->assertStringNotContainsString('فعّل حالة طارئة في المبنى', $e);
        $this->assertStringContainsString('name="alert_type" value="medical"', $e);
    }

    public function test_guests_get_a_call_button_everywhere_and_the_urgent_card_is_gone(): void
    {
        // صفحة البلاغ: عادي وسري فقط، والزر الأحمر يتصل
        $p = $this->get('/incident')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-type="urgent"', $p);
        $this->assertStringContainsString('data-type="normal"', $p);
        $this->assertStringContainsString('data-type="secret"', $p);
        $this->assertMatchesRegularExpression('~<div class="sos-bar" id="sosBar"><a class="btn btn-red" href="tel:'.Place::CENTER_PHONE.'" data-intent="emergency-call">.*?طوارئ الآن</a>~s', $p);
        // وفي التتبع كذلك
        $this->get('/incident/track')->assertOk()->assertSee('data-intent="emergency-call"', false)->assertSee('href="tel:'.Place::CENTER_PHONE.'"', false);
        // نموذج البلاغ نفسه بلا شريط حتى لا يغطي زر الإرسال
        $this->get('/incident/secret')->assertOk()->assertDontSee('id="sosBar"', false); // قرار ٧٢: العادي بحساب — نموذج الضيف هو السري
    }
}
