<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Emergency\Models\AssemblyPoint;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Models\PanicAlert;
use App\Modules\Emergency\Services\EmergencyMessagingService;
use App\Modules\Emergency\Services\EmergencyService;
use App\Modules\Emergency\Services\PanicAlertService;
use App\Modules\Governance\Models\AppNotification;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * إصلاحات قبول المرحلة «د» (٢٢-١٢…٢٢-١٥) — كشفها فحص تجربة المستخدم بتصفّح المحلي الممتلئ.
 *
 * ٢٢-١٢: روابط الإشعارات تصل صاحبها. كان الموظف يضغط إشعار الحالة فيجد «ليس لديك صلاحية» (٤٠٣)،
 * وإشعار الرسالة الجماعية يفتح مساراً لا صفحة له، و«تحديث تنبيهك» يُرفض لصاحب الاستغاثة.
 */
class EmergencyAcceptanceFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $salama;
    private User $employee;
    private EmergencyBuilding $building;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlacesSeeder::class);
        $this->seed(OrganizationUnitsSeeder::class);
        $this->seed(EmergencySeeder::class);

        $this->salama = $this->user('salama', 'system_admin');
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

    private function trigger(): EmergencyIncident
    {
        return app(EmergencyService::class)->triggerAlarm(
            $this->building, 'fire', $this->salama, 'high', false, 'اختبار إصلاحات القبول', Place::idByCode('HZ-06')
        );
    }

    /** آخر إشعار لصاحبه من نوعٍ ما — والرابط الذي يضغطه. */
    private function lastUrl(User $u, string $type): string
    {
        $n = AppNotification::where('user_id', $u->id)->where('type', 'like', $type.'%')->latest('id')->first();
        $this->assertNotNull($n, "لا إشعار {$type} لـ {$u->username}");
        return $n->url;
    }

    public function test_emergency_notification_opens_the_employees_own_screen_and_the_center_keeps_live(): void
    {
        $incident = $this->trigger();

        $empUrl = $this->lastUrl($this->employee, 'emergency');
        $this->actingAs($this->employee)->get($empUrl)->assertOk()->assertSee('أنا بخير — سجّل وصولي');
        $this->assertStringEndsWith('/app/emergency/me', $empUrl);

        $salamaUrl = $this->lastUrl($this->salama, 'emergency');
        $this->assertStringEndsWith('/app/emergency/incidents/'.$incident->id.'/live', $salamaUrl);
        $this->actingAs($this->salama)->get($salamaUrl)->assertOk();
    }

    public function test_mass_message_notification_opens_a_real_page_for_everyone(): void
    {
        $incident = $this->trigger();
        app(EmergencyMessagingService::class)->sendMassMessage([
            'incident_id' => $incident->id, 'title' => 'أمر إخلاء', 'message' => 'اخرجوا إلى نقطة التجمع', 'channels' => ['app'],
        ], $this->salama);

        $this->actingAs($this->employee)->get($this->lastUrl($this->employee, 'emergency.message'))->assertOk()->assertSee('أنا بخير — سجّل وصولي');
        $this->actingAs($this->salama)->get($this->lastUrl($this->salama, 'emergency.message'))->assertOk();
    }

    public function test_panic_update_opens_the_sos_page_for_the_employee_who_raised_it(): void
    {
        $this->actingAs($this->employee)->post(route('emergency.sos.trigger'), ['alert_type' => 'medical'])->assertRedirect();
        $alert = PanicAlert::where('user_id', $this->employee->id)->firstOrFail();
        app(PanicAlertService::class)->acknowledge($alert, $this->salama);

        $url = $this->lastUrl($this->employee, 'emergency.panic');
        $this->actingAs($this->employee)->get($url)->assertOk()->assertSee('استغاثتك');
    }

    // ── ٢٢-١٣: ما يُرى أولاً صادق ──

    /** بطاقة الصفحة الأولى: حالة منتهية بلا إقرار لا «تحتاج إقراراً الآن»، ومؤشر التقرير يبقى يعدّها. */
    public function test_unacknowledged_tile_counts_open_cases_only_and_the_report_keeps_history(): void
    {
        $incident = $this->trigger();
        $home = fn () => $this->actingAs($this->salama)->get(route('app.home'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-tile="unack".*?text-danger">1<.*?يحتاج إقراراً الآن/su', $home());

        app(EmergencyService::class)->endIncident($incident->fresh(), $this->salama, 'انتهى');
        $this->assertMatchesRegularExpression('/data-tile="unack"[^>]*>.*?"n ">0<.*?لا شيء معلّق/su', $home());

        $report = $this->actingAs($this->salama)->get(route('reports.dashboard'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-kpi="emergency_unack">1</u', $report);
    }

    /** رسالة وتذكيرها = بطاقة واحدة؛ الرد يُحتسب على الاثنتين؛ وبعد انتهاء الحالة لا بطاقة. */
    public function test_message_and_its_reminder_are_one_card_that_disappears_when_the_case_ends(): void
    {
        $incident = $this->trigger();
        $svc = app(EmergencyMessagingService::class);
        $other = $this->user('emp2', 'employee', 'HZ-06');
        $msg = $svc->sendMassMessage(['incident_id' => $incident->id, 'title' => 'أمر إخلاء', 'message' => 'اخرجوا', 'channels' => ['app']], $this->salama);
        $reminder = $svc->sendFollowUp($msg);

        $cards = fn (User $u) => app(\App\Core\Inbox\InboxService::class)->forUser($u)
            ->filter(fn ($t) => str_starts_with($t->key, 'emsg:'))->count();
        $this->assertSame(1, $cards($this->employee), 'الأصل والتذكير بطاقة واحدة');

        $this->actingAs($this->employee)->post(route('api.emergency.messages.quick', [$reminder, 'safe']))->assertRedirect();
        $this->assertSame(0, $cards($this->employee));
        $this->assertSame(1, $msg->fresh()->responded_count, 'الرد يُحتسب في عدّاد الأصل');
        $this->assertSame(1, $reminder->fresh()->responded_count, 'وفي عدّاد التذكير');

        $this->assertSame(1, $cards($other));
        app(EmergencyService::class)->endIncident($incident->fresh(), $this->salama, 'انتهى');
        $this->assertSame(0, $cards($other), 'حالة انتهت لا تنتظر رداً');
    }

    // ── ٢٢-١٤: ضغطة واحدة للموظف ──

    private function tasks(User $u, string $prefix): int
    {
        return app(\App\Core\Inbox\InboxService::class)->forUser($u)->filter(fn ($t) => str_starts_with($t->key, $prefix))->count();
    }

    /** «أنا بخير» في الرسالة يرفع عدّاد الآمنين (الرقم لا الكلمة)، و«أحتاج مساعدة» طلبٌ يراه المركز. */
    public function test_message_reply_is_the_same_act_as_the_screen_safe_counts_and_help_reaches_the_center(): void
    {
        $other = $this->user('emp2', 'employee', 'HZ-06'); // قبل التفعيل: الحصر يُنشأ لمن هو مفعَّل عندها
        $incident = $this->trigger();
        $msg = app(EmergencyMessagingService::class)->sendMassMessage(['incident_id' => $incident->id, 'title' => 'أمر إخلاء', 'message' => 'اخرجوا', 'channels' => ['app']], $this->salama);
        $safe = fn () => \App\Modules\Emergency\Models\EvacuationCheckIn::where('incident_id', $incident->id)->where('status', 'safe')->count();
        $this->assertSame(0, $safe());
        $this->assertSame(0, $this->tasks($this->salama, 'ehelp:'));

        $this->actingAs($this->employee)->post(route('api.emergency.messages.quick', [$msg, 'safe']))->assertRedirect();
        $this->assertSame(1, $safe(), '«أنا بخير» من الرسالة يُحتسب في الحصر');

        $this->actingAs($other)->post(route('api.emergency.messages.quick', [$msg, 'need_help']))->assertRedirect();
        $this->assertSame(1, $this->tasks($this->salama, 'ehelp:'), 'طلب المساعدة من الرسالة يظهر للمركز');
    }

    /** الاستطلاع كل دقيقة يحمل «حالتي» فيظهر الشريط بلا إعادة تحميل. */
    public function test_inbox_poll_carries_my_open_case_for_the_banner(): void
    {
        $poll = fn () => $this->actingAs($this->employee)->getJson(route('app.inbox.count'))->assertOk()->json('emergency');
        $this->assertNull($poll());
        $this->trigger();
        $e = $poll();
        $this->assertNotNull($e, 'حالة مفتوحة تخصّه ← الاستطلاع يحملها');
        $this->assertStringEndsWith('/app/emergency/me', $e['url']);
    }

    // ── ٢٢-١٥: الإنهاء والتقرير و«وصلتُ» ──

    /** نافذة «انتهاء الخطر» تعدّ من لم يصل وطلبات المساعدة، وزرها «أنهِ رغم ذلك» — تنبيه لا منع. */
    public function test_end_dialog_counts_who_has_not_arrived_and_open_help_requests(): void
    {
        $incident = $this->trigger();
        $this->actingAs($this->employee)->post(route('emergency.me.help'), ['help_type' => 'medical'])->assertRedirect();

        $html = $this->actingAs($this->salama)->get(route('emergency.incidents.live', $incident))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/لم يسجّل وصوله بعد: <b>1</u', $html, 'سلامة نفسه لم يصل؛ الموظف طلب مساعدة');
        $this->assertMatchesRegularExpression('/طلب مساعدة مفتوح: <b>1</u', $html);
        $this->assertStringContainsString('أنهِ رغم ذلك', $html);

        // تنبيه لا منع: الإنهاء يمضي
        $this->actingAs($this->salama)->post(route('emergency.incidents.end', $incident))->assertRedirect();
        $this->assertSame('ended', $incident->fresh()->status);
    }

    /** حالة حقيقية انتهت بلا تقرير = بطاقة للمركز تُبني التقرير بزر؛ والتمرين خارجها. */
    public function test_ended_case_without_report_is_a_card_until_the_button_builds_it(): void
    {
        $incident = $this->trigger();
        app(EmergencyService::class)->endIncident($incident->fresh(), $this->salama, 'انتهى');
        $drill = app(EmergencyService::class)->triggerAlarm($this->building, 'fire', $this->salama, 'low', true, 'تمرين', Place::idByCode('HZ-06'));
        app(EmergencyService::class)->endIncident($drill->fresh(), $this->salama, 'انتهى التمرين');

        $this->assertSame(1, $this->tasks($this->salama, 'aarmissing:'), 'الحقيقية وحدها');
        $this->assertSame(0, $this->tasks($this->employee, 'aarmissing:'), 'للمركز لا للموظف');

        $this->actingAs($this->salama)->post(route('emergency.incidents.aar', $incident))->assertRedirect();
        $this->assertSame(0, $this->tasks($this->salama, 'aarmissing:'));
    }

    /** حالتان مفتوحتان وعضو فريق الأقدم: «وصلتُ» يُسجَّل في حالته، وشاشته تعرضها بزرها. */
    public function test_arrived_goes_to_the_case_of_his_team_not_the_latest_open_one(): void
    {
        $medic = $this->user('medic', 'medic', 'HZ-06');
        $team = \App\Modules\Emergency\Models\EmergencyTeam::create([
            'building_id' => $this->building->id, 'place_id' => Place::idByCode('HZ-06'),
            'name' => 'الفريق الأولي — المكاتب', 'team_type' => 'initial', 'shift' => 'all', 'is_active' => true, 'source' => 'manual',
        ]);
        \App\Modules\Emergency\Models\EmergencyTeamMember::create(['team_id' => $team->id, 'user_id' => $medic->id,
            'name' => $medic->name, 'role' => 'member', 'role_key' => 'medic', 'is_available' => true]);

        $mine = $this->trigger(); // المكاتب — فريقه
        $later = app(EmergencyService::class)->triggerAlarm($this->building, 'fire', $this->salama, 'high', false, 'حالة ثانية', Place::idByCode('HZ-05'));
        $this->assertGreaterThan($mine->id, $later->id);

        $this->actingAs($medic)->get(route('emergency.me'))->assertOk()->assertSee('وصلتُ إلى الموقع', false);
        $this->actingAs($medic)->post(route('emergency.me.arrived'))->assertRedirect(route('emergency.me'));

        $arrived = fn (EmergencyIncident $i) => \App\Modules\Emergency\Models\EmergencyEventLog::where('incident_id', $i->id)
            ->where('event_type', \App\Modules\Emergency\Models\EmergencyEventLog::TYPE_TEAM_ARRIVED)->count();
        $this->assertSame(1, $arrived($mine), 'في حالة فريقه');
        $this->assertSame(0, $arrived($later));
    }
}
