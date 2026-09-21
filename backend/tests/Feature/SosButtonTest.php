<?php

namespace Tests\Feature;

use App\Core\Intents\IntentRegistry;
use App\Models\User;
use App\Modules\Emergency\Models\AssemblyPoint;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\PanicAlert;
use App\Modules\Emergency\Services\EmergencyService;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٢٢-٣ (د): الاستغاثة تصل من مكانها.
 *
 * العيب المُعاد إنتاجه (جولة ٢٢-١): زر الاستغاثة مبنيّ لكل حساب في الخلفية، لكنه لا يوجد إلا
 * داخل لوحة مركز الطوارئ التي لا يفتحها الموظف؛ ونية «أستغيث الآن» تشترط صلاحية استجابة
 * ثم تفتح لوحةً ولا تطلق الاستغاثة.
 *
 * البوابة: استغاثة من جوال موظف بلا أي صلاحية طوارئ تصل لوحة المركز وبطاقات المناوب.
 */
class SosButtonTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;
    private User $munawib;
    private User $salama;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlacesSeeder::class);
        $this->seed(OrganizationUnitsSeeder::class);
        $this->seed(EmergencySeeder::class);

        $this->employee = $this->user('emp', 'employee', 'HZ-06');
        $this->munawib = $this->user('munawib', 'system_staff');
        $this->salama = $this->user('salama', 'system_admin');

        AssemblyPoint::create(['building_id' => EmergencyBuilding::main()->id, 'code' => 'A1',
            'name' => 'الساحة الأمامية', 'is_primary' => true, 'capacity' => 300]);
    }

    private function user(string $username, string $role, ?string $placeCode = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'place_id' => Place::idByCode($placeCode)]);
        return $u;
    }

    /** الشاشة لكل حساب، وفيها الأنواع الأربعة بضغطة واحدة لكل نوع. */
    public function test_sos_screen_opens_for_plain_employee(): void
    {
        $this->actingAs($this->employee)->get('/app/emergency/sos')
            ->assertOk()
            ->assertSee('طوارئ طبية', false)
            ->assertSee('حريق', false)
            ->assertSee('تهديد أمني', false);
    }

    /** ضغطة واحدة تُطلق الاستغاثة فعلاً — لا تفتح شاشة أخرى. */
    public function test_one_press_raises_a_real_alert(): void
    {
        $this->assertSame(0, PanicAlert::count());

        $this->actingAs($this->employee)
            ->post('/app/emergency/sos', ['alert_type' => 'medical'])
            ->assertRedirect('/app/emergency/sos');

        $alert = PanicAlert::first();
        $this->assertNotNull($alert, 'لم تُنشأ استغاثة');
        $this->assertSame($this->employee->id, $alert->user_id);
        $this->assertSame('medical', $alert->alert_type);
        $this->assertSame(Place::idByCode('HZ-06'), $alert->place_id, 'الاستغاثة لم تحمل مكان صاحبها');
        $this->assertSame('triggered', $alert->status);
    }

    /** تصل المركز: لوحة الذعر وبطاقات المناوب. */
    public function test_alert_reaches_the_centre(): void
    {
        $this->actingAs($this->employee)->post('/app/emergency/sos', ['alert_type' => 'security']);

        $this->actingAs($this->munawib)->get('/app/emergency/panic')
            ->assertOk()
            ->assertSee('اسم emp', false);

        $this->actingAs($this->munawib)->get('/app')
            ->assertOk()
            ->assertSee('تنبيه ذعر', false)
            ->assertSee('اسم emp', false);
    }

    /** النية: للموظف، وتشير إلى الفعل لا إلى لوحة المركز. */
    public function test_sos_intent_exists_for_employee_and_points_at_the_action(): void
    {
        $sos = IntentRegistry::forUser($this->employee)->firstWhere('key', 'sos');
        $this->assertNotNull($sos, 'الموظف بلا نية استغاثة');
        $this->assertStringContainsString('/app/emergency/sos', $sos->url);
        $this->assertStringNotContainsString('/app/emergency/buildings', $sos->url);
    }

    /** الزر الأحمر على الجوال: «أستغيث» عادةً، و«ماذا أفعل» أثناء حالة مفتوحة تخصّه. */
    public function test_red_bar_switches_to_my_screen_during_an_open_incident(): void
    {
        $this->actingAs($this->employee)->get('/app')->assertOk()->assertSee('أستغيث الآن', false);

        app(EmergencyService::class)->triggerAlarm(
            EmergencyBuilding::main(), 'fire', $this->salama, 'high', false, 'اختبار', Place::idByCode('HZ-06')
        );

        $this->actingAs($this->employee)->get('/app')->assertOk()->assertSee('حالة طارئة — ماذا أفعل', false);
    }

    /** القيادة تحتفظ بزرها: «فعّل حالة طارئة» لا يُزاحَم. */
    public function test_commander_keeps_trigger_on_the_red_bar(): void
    {
        $this->actingAs($this->salama)->get('/app')->assertOk()->assertSee('فعّل حالة طارئة', false);
    }
}
