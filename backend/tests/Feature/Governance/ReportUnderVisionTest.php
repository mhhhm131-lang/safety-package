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
 * ٢٦-٢ (بكلمته «كل البلاغات تحت الرؤية… في الرؤية فقط»، ٢٠٢٦-٠٩-٢٨): بلاغ الشاغلين بأنواعه من صفحة البلاغ العامة وحدها
 * (الرؤية، QR، قنوات الإبلاغ، شريط الضيف). داخل الحساب لا زر بلاغ في «أريد أن»، ولا «أبلغ هنا» في ملف المكان،
 * ولا «صفحة البلاغ العامة» في «المزيد». السري لا يصلح داخل الحساب لأن الجلسة تدل على صاحبه.
 */
class ReportUnderVisionTest extends TestCase
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

    public function test_no_report_door_inside_the_account_and_the_public_page_keeps_all_three_types(): void
    {
        $emp = $this->user('emp', 'employee', 'HZ-06');
        $salama = $this->user('salama', 'system_admin');
        $offices = Place::where('code', 'HZ-06')->first();

        // داخل الحساب: لا زر بلاغ في «أريد أن»
        $h = $this->actingAs($emp)->get('/app')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-intent="report"', $h);
        $this->assertStringNotContainsString('أبلّغ عن خطر', $h);
        // ولا في ملف المكان
        $f = $this->actingAs($emp)->get("/app/places/{$offices->id}/file")->assertOk()->getContent();
        $this->assertStringNotContainsString('أبلغ عن خطر هنا', $f);
        // ولا في «المزيد»
        $this->actingAs($salama)->get('/app')->assertOk()->assertDontSee('صفحة البلاغ العامة');

        // تحت الرؤية: صفحة البلاغ العامة بأنواعها الثلاثة لأي أحد، وشريط الضيف يقود إليها
        $p = $this->get('/incident')->assertOk()->getContent();
        foreach (['عادي', 'سري'] as $t) $this->assertStringContainsString($t, $p); // ٢٦-٣: «عاجل» صار الزر الأحمر
        $this->assertStringContainsString('href="'.url('/incident/normal').'"', $p);
        $this->assertStringContainsString('href="'.url('/incident/secret').'"', $p);
        $this->get('/incident/track')->assertOk()->assertSee('data-intent="emergency-call"', false); // ٢٦-٣: زر الضيف = اتصال
        // وصاحب الحساب يستطيع البلاغ العادي من الصفحة العامة نفسها (المكان مقترح من حسابه)
        $this->actingAs($emp)->get('/incident/normal')->assertOk()->assertSee('HZ-06', false);
    }
}
