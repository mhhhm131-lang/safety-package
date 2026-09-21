<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Emergency\Models\AssemblyPoint;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Models\EmergencyMassMessage;
use App\Modules\Emergency\Models\EmergencyMessageResponse;
use App\Modules\Emergency\Models\EmergencyMessageTemplate;
use App\Modules\Emergency\Services\EmergencyService;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٢٢-٥ (د): الرسائل الجماعية — زر الإرسال.
 *
 * العيب المُعاد إنتاجه (جولة ٢٢-١): ستّ عشرة وظيفة للرسائل والقوالب مبنية في الخلفية،
 * و**الردّ** له زر منذ ١٨-١ («أنا بخير» في بطاقات «ما ينتظرك») بينما **الإرسال بلا زر** —
 * أي ردٌّ على رسالة لا يمكن إرسالها. شاشة الحالة لا تحمل أي مدخل للإرسال.
 *
 * البوابة: المركز يرسل من شاشة الحالة بقالب جاهز، فتصل الموظف ويردّ «أنا بخير» فيزيد العدّاد،
 * ثم «تابِع من لم يردّ» تصل غير الرادّين وحدهم.
 */
class MassMessageButtonTest extends TestCase
{
    use RefreshDatabase;

    private User $salama;
    private User $emp1;
    private User $emp2;
    private EmergencyBuilding $building;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlacesSeeder::class);
        $this->seed(OrganizationUnitsSeeder::class);
        $this->seed(EmergencySeeder::class);

        $this->salama = $this->user('salama', 'system_admin');
        $this->emp1 = $this->user('emp1', 'employee', 'HZ-06');
        $this->emp2 = $this->user('emp2', 'employee', 'HZ-06');

        $this->building = EmergencyBuilding::main();
        AssemblyPoint::create(['building_id' => $this->building->id, 'code' => 'A1',
            'name' => 'الساحة الأمامية', 'is_primary' => true, 'capacity' => 300]);
    }

    private function user(string $username, string $role, ?string $placeCode = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'place_id' => Place::idByCode($placeCode)]);
        return $u;
    }

    private function trigger(): EmergencyIncident
    {
        return app(EmergencyService::class)->triggerAlarm(
            $this->building, 'fire', $this->salama, 'high', false, 'اختبار الرسائل', Place::idByCode('HZ-06')
        );
    }

    public function test_live_screen_carries_a_send_button_and_the_templates(): void
    {
        $incident = $this->trigger();

        $this->actingAs($this->salama)->get("/app/emergency/incidents/{$incident->id}/live")
            ->assertOk()
            ->assertSee('أرسل رسالة للجميع', false)
            ->assertSee('أمر إخلاء', false)        // قالب جاهز من العشرة
            ->assertSee('انتهاء الخطر', false);
    }

    public function test_centre_sends_from_a_template_with_the_incident_filled_in(): void
    {
        $incident = $this->trigger();
        $tpl = EmergencyMessageTemplate::where('code', 'EVACUATION')->firstOrFail();

        $this->actingAs($this->salama)
            ->post("/app/emergency/incidents/{$incident->id}/message", ['template_id' => $tpl->id])
            ->assertRedirect("/app/emergency/incidents/{$incident->id}/live");

        $msg = EmergencyMassMessage::latest('id')->first();
        $this->assertNotNull($msg, 'لم تُرسل الرسالة');
        $this->assertSame($incident->id, $msg->incident_id);
        $this->assertSame('أمر إخلاء', $msg->title);
        // المتغيّرات تُملأ من الحالة نفسها، فلا يبقى قالب خام في نص يصل الناس
        $this->assertStringNotContainsString('{{', $msg->message);
        $this->assertStringContainsString('الساحة الأمامية', $msg->message);
        $this->assertGreaterThanOrEqual(3, $msg->total_recipients);
    }

    public function test_centre_can_write_its_own_message(): void
    {
        $incident = $this->trigger();

        $this->actingAs($this->salama)->post("/app/emergency/incidents/{$incident->id}/message", [
            'title' => 'تحديث', 'message' => 'ابقوا في نقطة التجمع حتى إشعار آخر.',
        ])->assertRedirect();

        $msg = EmergencyMassMessage::latest('id')->firstOrFail();
        $this->assertSame('تحديث', $msg->title);
        $this->assertStringContainsString('نقطة التجمع', $msg->message);
    }

    public function test_employee_answers_and_the_counter_moves(): void
    {
        $incident = $this->trigger();
        $tpl = EmergencyMessageTemplate::where('code', 'EVACUATION')->firstOrFail();
        $this->actingAs($this->salama)->post("/app/emergency/incidents/{$incident->id}/message", ['template_id' => $tpl->id]);
        $msg = EmergencyMassMessage::latest('id')->firstOrFail();

        // الموظف يرى السؤال في بطاقاته ويردّ بضغطة (المسار مبنيّ منذ ١٨-١)
        $this->actingAs($this->emp1)->get('/app')->assertOk()->assertSee('أنا بخير', false);
        $this->actingAs($this->emp1)->post("/api/emergency/messages/{$msg->id}/quick/safe");

        $this->assertSame(1, EmergencyMessageResponse::where('message_id', $msg->id)
            ->whereNotNull('responded_at')->count());

        // والعدّاد يظهر للمركز على شاشة الحالة
        $this->actingAs($this->salama)->get("/app/emergency/incidents/{$incident->id}/live")
            ->assertOk()
            ->assertSee('ردّوا', false)
            ->assertSee('تابِع من لم يردّ', false);
    }

    public function test_follow_up_reaches_only_those_who_did_not_answer(): void
    {
        $incident = $this->trigger();
        $tpl = EmergencyMessageTemplate::where('code', 'EVACUATION')->firstOrFail();
        $this->actingAs($this->salama)->post("/app/emergency/incidents/{$incident->id}/message", ['template_id' => $tpl->id]);
        $msg = EmergencyMassMessage::latest('id')->firstOrFail();
        $before = $msg->total_recipients;

        $this->actingAs($this->emp1)->post("/api/emergency/messages/{$msg->id}/quick/safe");

        $this->actingAs($this->salama)->post("/app/emergency/messages/{$msg->id}/follow-up")->assertRedirect();

        $followUp = EmergencyMassMessage::latest('id')->firstOrFail();
        $this->assertNotSame($msg->id, $followUp->id, 'لم تُنشأ رسالة متابعة');
        $this->assertSame($before - 1, $followUp->total_recipients, 'المتابعة لم تستثنِ من ردّ');
    }

    public function test_only_the_centre_sends(): void
    {
        $incident = $this->trigger();
        $this->actingAs($this->emp1)
            ->post("/app/emergency/incidents/{$incident->id}/message", ['title' => 'ا', 'message' => 'محاولة'])
            ->assertForbidden();
    }
}
