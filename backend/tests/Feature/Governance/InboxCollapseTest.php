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
 * المرحلة ٢٥-٣-ب (بكلمة المستخدم «المطلوب الآن ما ينتظرك قائمة منسدلة»، ٢٠٢٦-٠٩-٢٧): «ما ينتظرك» سطر عدّادات ثم مجموعات
 * تُفتح وتُغلق بضغطة عنوانها؛ حتى ثلاث مهام تُفتح كلها، وأكثر تُطوى ويبقى في عنوانها العدد والمتأخر.
 */
class InboxCollapseTest extends TestCase
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

    public function test_many_tasks_are_folded_under_counters_and_few_tasks_stay_open(): void
    {
        $salama = $this->user('salama', 'system_admin');
        // مهمة واحدة: تبقى مفتوحة
        $this->post('/incident/normal', ['description' => 'بلاط مكسور ١', 'place_id' => Place::idByCode('HZ-06')])->assertRedirect();
        $h = $this->actingAs($salama)->get('/app')->assertOk()->getContent();
        $this->assertStringContainsString('id="inboxSummary"', $h);
        $this->assertMatchesRegularExpression('~<a[^>]*class="[^"]*chip[^"]*"[^>]*data-group="بلاغات الشاغلين" data-n="1" data-od="0"~', $h);
        $this->assertMatchesRegularExpression('~<section data-module="بلاغات الشاغلين"[^>]*>.*?data-bs-toggle="collapse".*?class="collapse show"~s', $h);

        // أربع مهام: تُطوى، والعنوان يحمل العدد، والبطاقات كلها موجودة في الصفحة (لا يُخفى شيء عن البحث والسكربتات)
        foreach ([2, 3, 4] as $i) $this->post('/incident/normal', ['description' => "بلاط مكسور $i", 'place_id' => Place::idByCode('HZ-06')])->assertRedirect();
        $h = $this->actingAs($salama)->get('/app')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~data-group="بلاغات الشاغلين" data-n="4" data-od="0"~', $h);
        $this->assertMatchesRegularExpression('~<section data-module="بلاغات الشاغلين"[^>]*>.*?<button[^>]*data-bs-toggle="collapse"[^>]*>.*?<span class="badge text-bg-dark">4</span>~s', $h);
        $this->assertMatchesRegularExpression('~<section data-module="بلاغات الشاغلين"[^>]*>.*?class="collapse"~s', $h);
        $this->assertStringNotContainsString('class="collapse show"', $h);
        $this->assertSame(4, substr_count($h, 'data-task="'));
        // العدّاد يقفز إلى مجموعته
        $this->assertStringContainsString('href="#grp-0"', $h);
    }
}
