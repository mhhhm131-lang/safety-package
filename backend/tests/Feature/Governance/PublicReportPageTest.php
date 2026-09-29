<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ٢٦-٤ (قرار ٦٦): صفحة البلاغ العامة غرضها البلاغ — نوعان (عادي وسري) وتتبع، وبلا كتلة «أريد أن».
 * ما كان في الكتلة له بابه: المنظومة وقنوات الإبلاغ والتتبع في ذيل الصفحة، والزر الأحمر للاتصال.
 */
class PublicReportPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class]);
    }

    public function test_public_report_page_has_two_types_and_no_intents_block(): void
    {
        $h = $this->get('/incident')->assertOk()->getContent();
        $this->assertStringNotContainsString('id="intents"', $h);
        $this->assertStringNotContainsString('أريد أن', $h);
        preg_match_all('~data-intent="([^"]+)"~', $h, $m);
        $this->assertSame(['emergency-call'], array_values(array_unique($m[1])), 'لا زر نية في الصفحة غير الزر الأحمر');
        $this->assertStringContainsString('data-type="normal"', $h);
        $this->assertStringContainsString('data-type="secret"', $h);
        $this->assertStringNotContainsString('data-type="urgent"', $h);
        // أبواب ما كان في الكتلة
        $this->assertStringContainsString('href="'.route('incident.track').'"', $h);
        $this->assertStringContainsString('href="/index.html"', $h);
        $this->assertStringContainsString('reporting-channels.html', $h);

        // وبحساب: الصفحة نفسها بلا الكتلة
        $u = User::create(['username' => 'emp', 'name' => 'اسم emp', 'password' => '1234', 'email' => 'emp@example.test']);
        UserProfile::create(['user_id' => $u->id, 'role' => 'employee', 'is_active' => true, 'place_id' => Place::idByCode('HZ-06')]);
        $h2 = $this->actingAs($u)->get('/incident')->assertOk()->getContent();
        $this->assertStringNotContainsString('id="intents"', $h2);
        $this->assertStringContainsString('data-type="secret"', $h2);
    }
}
