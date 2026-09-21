<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Emergency\Models\AssemblyPoint;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyEventLog;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Models\EvacuationCheckIn;
use App\Modules\Emergency\Services\EmergencyService;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٢٢-٦ (د): بطاقة «من طلب مساعدة» — تُغلق بزر (قرار ٥٩: بلا أي بيان طبي).
 *
 * ما ثبت في ٢٢-١ بالتشغيل: بطاقة «يحتاجون مساعدة» موجودة في شاشة الحالة وتعمل (هنا صحّحتُ ادعاءً
 * سابقاً لي بأنها غير موجودة). الناقص: **وقت الطلب**، و**زر يُغلق الطلب** — فيبقى معلّقاً بلا نهاية
 * ولا يُعرف من عالجه، وهو النقص نفسه الذي أُغلق في النداء الهاتفي بزر «نوديَ».
 */
class HelpRequestCardTest extends TestCase
{
    use RefreshDatabase;

    private User $salama;
    private User $medic;
    private User $employee;
    private EmergencyBuilding $building;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlacesSeeder::class);
        $this->seed(OrganizationUnitsSeeder::class);
        $this->seed(EmergencySeeder::class);

        $this->salama = $this->user('salama', 'system_admin');
        $this->medic = $this->user('medic', 'medic', 'HZ-06');
        $this->employee = $this->user('emp', 'employee', 'HZ-06');

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

    private function triggerAndAskForHelp(): array
    {
        $incident = app(EmergencyService::class)->triggerAlarm(
            $this->building, 'fire', $this->salama, 'high', false, 'اختبار طلب المساعدة', Place::idByCode('HZ-06')
        );
        $this->actingAs($this->employee)->post('/app/emergency/me/help', [
            'help_type' => 'mobility', 'notes' => 'لا أستطيع النزول من الدرج',
        ]);
        $checkIn = EvacuationCheckIn::where('incident_id', $incident->id)
            ->where('user_id', $this->employee->id)->firstOrFail();
        return [$incident, $checkIn];
    }

    public function test_card_shows_who_asked_what_where_and_when(): void
    {
        [$incident] = $this->triggerAndAskForHelp();

        $this->actingAs($this->medic)->get("/app/emergency/incidents/{$incident->id}/live")
            ->assertOk()
            ->assertSee('يحتاجون مساعدة', false)
            ->assertSee('اسم emp', false)
            ->assertSee('صعوبة حركة', false)
            ->assertSee('لا أستطيع النزول من الدرج', false)
            ->assertSee('منذ', false)          // وقت الطلب — الناقص
            ->assertSee('عولج', false);        // زر الإغلاق — الناقص
    }

    public function test_responder_closes_the_request_with_one_button(): void
    {
        [$incident, $checkIn] = $this->triggerAndAskForHelp();

        $this->actingAs($this->medic)
            ->post("/app/emergency/incidents/{$incident->id}/help/{$checkIn->id}/done")
            ->assertRedirect();

        $fresh = $checkIn->fresh();
        $this->assertFalse((bool) $fresh->needs_assistance, 'الطلب بقي مفتوحاً');
        $this->assertDatabaseHas('emergency_event_logs', [
            'incident_id' => $incident->id, 'user_id' => $this->medic->id,
            'event_type' => EmergencyEventLog::TYPE_HELP_REQUESTED,
        ]);

        // وتختفي البطاقة، ولا يبقى العدّاد يشير إلى طلب معلّق
        $this->actingAs($this->medic)->get("/app/emergency/incidents/{$incident->id}/live")
            ->assertOk()
            ->assertDontSee('لا أستطيع النزول من الدرج', false);
    }

    /** صاحب الطلب يرى أنه عولج، فلا يظل ينتظر. */
    public function test_asker_sees_that_his_request_was_handled(): void
    {
        [$incident, $checkIn] = $this->triggerAndAskForHelp();
        $this->actingAs($this->medic)->post("/app/emergency/incidents/{$incident->id}/help/{$checkIn->id}/done");

        $this->actingAs($this->employee)->get('/app/emergency/me')
            ->assertOk()
            ->assertSee('عولج طلبك', false)
            ->assertDontSee('طلبك وصل', false);
    }

    /**
     * عيب كشفه فحص المراجعة (٢٠٢٦-٠٩-٢٢): من سجّل وصوله «بأمان» ثم طلب مساعدة تنقلب حالته،
     * ولا تعود بعد «عولج» — فيقول عدّاد الآمنين صفراً ورجلٌ واقف في نقطة التجمع.
     */
    public function test_asking_for_help_does_not_lose_a_person_from_the_headcount(): void
    {
        $incident = app(EmergencyService::class)->triggerAlarm(
            $this->building, 'fire', $this->salama, 'high', false, 'حصر', Place::idByCode('HZ-06')
        );
        $point = AssemblyPoint::where('building_id', $this->building->id)->firstOrFail();

        $this->actingAs($this->employee)->post('/app/emergency/me/check-in', ['assembly_point_id' => $point->id]);
        $checkIn = EvacuationCheckIn::where('incident_id', $incident->id)
            ->where('user_id', $this->employee->id)->firstOrFail();
        $this->assertTrue($checkIn->fresh()->isSafe());

        // يطلب مساعدة وهو في نقطة التجمع: يبقى محصوراً آمناً، وعليه علامة «يحتاج مساعدة»
        $this->actingAs($this->employee)->post('/app/emergency/me/help', ['help_type' => 'mobility', 'notes' => 'لا أستطيع المشي']);
        $this->assertTrue((bool) $checkIn->fresh()->needs_assistance);
        $this->assertTrue($checkIn->fresh()->isSafe(), 'خرج من الحصر بمجرد طلبه المساعدة');

        $stats = app(\App\Modules\Emergency\Services\QrMusteringService::class)->getLiveStats($incident);
        $this->assertSame(1, $stats['safe'], 'عدّاد الآمنين لا يعدّه');
        $this->assertSame(1, $stats['needs_help']);

        // وبعد «عولج» يبقى آمناً
        $this->actingAs($this->medic)->post("/app/emergency/incidents/{$incident->id}/help/{$checkIn->id}/done");
        $this->assertTrue($checkIn->fresh()->isSafe(), 'لم تعد حالته بعد المعالجة');
        $this->assertSame(1, app(\App\Modules\Emergency\Services\QrMusteringService::class)->getLiveStats($incident)['safe']);
    }

    /** ومن لم يسجّل وصوله بعد: يعود «قيد الإخلاء» لا «بأمان» — لا يُدّعى وصول لم يحدث. */
    public function test_someone_who_never_arrived_goes_back_to_evacuating(): void
    {
        [$incident, $checkIn] = $this->triggerAndAskForHelp();
        $this->assertFalse($checkIn->fresh()->isSafe());

        $this->actingAs($this->medic)->post("/app/emergency/incidents/{$incident->id}/help/{$checkIn->id}/done");

        $this->assertSame(EvacuationCheckIn::STATUS_EVACUATING, $checkIn->fresh()->status);
        $this->assertFalse((bool) $checkIn->fresh()->needs_assistance);
    }

    /**
     * عيب كشفه الفحص: الطلب لا يصل أحداً — لا إشعار ولا بطاقة. يظهر فقط لمن شاشة الحالة مفتوحة
     * أمامه، بينما شاشة الموظف تقول له «فريق الاستجابة والمركز يريانه الآن».
     */
    public function test_the_request_actually_reaches_the_centre_and_the_responders(): void
    {
        [$incident, $checkIn] = $this->triggerAndAskForHelp();

        // إشعار للمركز
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $this->salama->id, 'type' => 'emergency.help_requested',
        ]);

        // وبطاقة في «ما ينتظرك» بزر «عولج»
        $home = $this->actingAs($this->salama)->get('/app')->assertOk();
        $home->assertSee('يحتاج مساعدة', false);
        $home->assertSee('لا أستطيع النزول من الدرج', false);
        $home->assertSee(route('emergency.incidents.help.done', [$incident, $checkIn->id]), false);

        // وتختفي البطاقة بعد «عولج»
        $this->actingAs($this->medic)->post("/app/emergency/incidents/{$incident->id}/help/{$checkIn->id}/done");
        $this->actingAs($this->salama)->get('/app')->assertOk()
            ->assertDontSee(route('emergency.incidents.help.done', [$incident, $checkIn->id]), false);
    }

    public function test_plain_employee_cannot_close_someone_elses_request(): void
    {
        [$incident, $checkIn] = $this->triggerAndAskForHelp();
        $other = $this->user('emp2', 'employee', 'HZ-06');

        $this->actingAs($other)
            ->post("/app/emergency/incidents/{$incident->id}/help/{$checkIn->id}/done")
            ->assertForbidden();
    }
}
