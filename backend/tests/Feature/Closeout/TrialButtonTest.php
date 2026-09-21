<?php

namespace Tests\Feature\Closeout;

use App\Core\Trial\TrialFill;
use App\Core\Trial\TrialMode;
use App\Models\User;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Risk\Models\Risk;
use Database\Seeders\AffectedGroupsSeeder;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Database\Seeders\RiskBookSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * المرحلة ٢١-٩ (قرار ٥٤): «وضع التجربة» من شاشة الإغلاق — المنشور بلا سطر أوامر ويقطع الطلب عند ٦٠ ثانية،
 * فالتشغيل بطلب، والتعبئة بطلبات تُستأنف حتى تكتمل، والإنهاء بكلمة تأكيد. لمسؤول السلامة وحده.
 */
class TrialButtonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class, AffectedGroupsSeeder::class, RiskBookSeeder::class]);
    }

    private function user(string $username, string $role): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => 'secret-real', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true]);
        return $u;
    }

    public function test_safety_officer_starts_fills_in_resumable_requests_and_stops_with_the_confirm_word(): void
    {
        $salama = $this->user('salama', 'system_admin');
        $munawib = $this->user('munawib', 'system_staff');
        $usersBefore = User::count();
        $risksBefore = Risk::count();

        // لمسؤول السلامة وحده
        $this->actingAs($munawib)->postJson('/app/closeout/trial/start')->assertForbidden();
        $this->actingAs($salama)->get('/app/closeout')->assertOk()->assertSee('id="trialStart"', false)->assertDontSee('id="trialResume"', false);

        // التشغيل يعيد كلمة المرور مرة واحدة ولا يعبّئ في الطلب نفسه
        $r = $this->actingAs($salama)->postJson('/app/closeout/trial/start')->assertOk();
        $password = $r->json('password');
        $this->assertGreaterThanOrEqual(12, strlen((string) $password));
        $this->assertSame(TrialFill::PREFIX, $r->json('prefix'));
        $this->assertTrue(TrialMode::isOn());
        $this->assertSame(0, User::where('username', 'like', TrialFill::PREFIX.'%')->count());
        $this->actingAs($salama)->postJson('/app/closeout/trial/start')->assertStatus(409); // لا يُشغَّل مرتين

        // التعبئة تُستأنف: نقطع الميزانية إلى الصفر فيعمل كل طلب عنصراً واحداً على الأقل ويحفظ موضعه
        $calls = 0;
        $fill = app(TrialFill::class);
        $first = $fill->next(0);
        $this->assertFalse($first['done']);
        $this->actingAs($salama)->get('/app/closeout')->assertOk()->assertSee('id="trialResume"', false); // بدأت ولم تكتمل: «أكمل التعبئة»
        $this->assertSame(1, User::where('username', 'like', TrialFill::PREFIX.'%')->count(), 'الطلب بميزانية صفر لم يعمل عنصراً واحداً بالضبط');
        do {
            $j = $this->actingAs($salama)->postJson('/app/closeout/trial/fill')->assertOk()->json();
            $calls++;
        } while (!$j['done'] && $calls < 60);
        $this->assertTrue($j['done'], 'التعبئة لم تكتمل');
        $this->assertSame(184, User::where('username', 'like', TrialFill::PREFIX.'%')->count());
        $this->assertSame(320, Risk::where('risk_type', 'active')->count());
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check($password, User::where('username', TrialFill::PREFIX.'hr.m')->value('password')));

        // الشاشة تعرض ما أُنشئ وزر الإنهاء؛ والإنهاء بكلمة التأكيد وحدها
        $this->actingAs($salama)->get('/app/closeout')->assertOk()->assertSee('أنهِ التجربة واحذف بياناتها')->assertDontSee('id="trialStart"', false)->assertDontSee('id="trialResume"', false); // اكتملت: لا «أكمل التعبئة»
        $this->actingAs($salama)->post('/app/closeout/trial/stop', ['confirm' => 'نعم'])->assertSessionHasErrors('confirm');
        $this->assertTrue(TrialMode::isOn());
        $this->actingAs($salama)->post('/app/closeout/trial/stop', ['confirm' => 'أنهِ التجربة'])->assertSessionHas('ok');
        $this->assertFalse(TrialMode::isOn());
        $this->assertSame($usersBefore, User::count());
        $this->assertSame($risksBefore, Risk::count());
        $this->assertSame(0, DB::table('trial_state')->count());
    }
}
