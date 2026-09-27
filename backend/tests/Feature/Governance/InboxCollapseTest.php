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

    public function test_groups_are_one_folded_line_each_with_icon_count_and_red_mark(): void
    {
        $salama = $this->user('salama', 'system_admin');
        // مهمة واحدة: مطويّة أيضاً — لا فتح تلقائي (بكلمته)
        $this->post('/incident/normal', ['description' => 'بلاط مكسور ١', 'place_id' => Place::idByCode('HZ-06')])->assertRedirect();
        $h = $this->actingAs($salama)->get('/app')->assertOk()->getContent();
        $this->assertStringNotContainsString('id="inboxSummary"', $h); // سطر العدّادات حُذف (تكرار)
        $this->assertMatchesRegularExpression('~<section data-module="بلاغات الشاغلين" data-n="1" data-od="0">\s*<button class="grp-h collapsed"[^>]*data-bs-toggle="collapse"[^>]*>.*?<span class="grp-ic "><i class="bi bi-megaphone-fill"></i><span class="grp-n">1</span></span>.*?class="collapse" id="grp-0"~s', $h);
        $this->assertStringNotContainsString('class="collapse show"', $h);
        $this->assertStringNotContainsString('<span class="grp-od"', $h); // لا متأخر ← لا علامة حمراء

        // أربع مهام: السطر نفسه بالعدد ٤، والبطاقات كلها في الصفحة (لا يُحذف شيء)
        foreach ([2, 3, 4] as $i) $this->post('/incident/normal', ['description' => "بلاط مكسور $i", 'place_id' => Place::idByCode('HZ-06')])->assertRedirect();
        $h = $this->actingAs($salama)->get('/app')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~data-module="بلاغات الشاغلين" data-n="4" data-od="0"~', $h);
        $this->assertStringContainsString('<span class="grp-n">4</span>', $h);
        $this->assertSame(4, substr_count($h, 'data-task="'));

        // متأخر: مهلة البلاغ انقضت ← أيقونة حمراء و«١ متأخر»
        \App\Modules\Incident\Models\Incident::query()->orderBy('id')->first()->update(['deadline_at' => now()->subHour()]);
        $h = $this->actingAs($salama)->get('/app')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~data-module="بلاغات الشاغلين" data-n="4" data-od="1"~', $h);
        $this->assertStringContainsString('<span class="grp-ic late">', $h);
        $this->assertStringContainsString('<span class="grp-od" title="1 متأخر">1 متأخر</span>', $h);
    }
}
