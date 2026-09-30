<?php

namespace Tests\Feature\Emergency;

use App\Core\Inbox\InboxService;
use App\Core\Inbox\Task;
use App\Models\User;
use App\Modules\Emergency\Models\AfterActionReport;
use App\Modules\Emergency\Models\AssemblyPoint;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Models\EmergencyTeam;
use App\Modules\Emergency\Models\EmergencyTeamMember;
use App\Modules\Emergency\Models\EvacuationCheckIn;
use App\Modules\Emergency\Models\Lockdown;
use App\Modules\Emergency\Services\EmergencyMessagingService;
use App\Modules\Emergency\Services\EmergencyService;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ٢٧-أ (قرار ٦٧ «كل ما يُراد من شخص يصله بطاقة»، بكلمته «ابدأ في ٢٧» ٢٠٢٦-٠٩-٣٠): ست بطاقات للطوارئ كانت في الشاشة الحية وحدها.
 * لكل واحدة: الحالة ← البطاقة تظهر لصاحبها وحده ← ضغطة ← تختفي.
 *   ٢٤ إقرار الحالة · ٢٥ السيطرة والإنهاء · ٢٧ المفقودون وغير المحسوبين · ٢٢ من لم يردّ على الرسالة · ٢٨ الإغلاق الأمني المفتوح · ٣٠ اعتماد تقرير ما بعد الحادث.
 * (٣ و٤ «المنسق يستلم ويحوّل» خرجتا: النظام يؤدي الخطوتين وحده ويُشعر المنسق — `IncidentService::stepsToHandler` — فالبلاغ لا يقف عنده.)
 */
class CommandCardsTest extends TestCase
{
    use RefreshDatabase;

    private User $salama;
    private User $munawib;
    private User $commander;
    private User $medic;
    private User $employee;
    private User $mudir;
    private EmergencyBuilding $building;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
        $this->salama = $this->user('salama', 'system_admin');
        $this->munawib = $this->user('munawib', 'system_staff');
        $this->commander = $this->user('shuon', 'admin_eng_manager'); // بطاقة الدور ١: قائد فريق الطوارئ
        $this->medic = $this->user('medic', 'medic', 'HZ-06');
        $this->employee = $this->user('emp', 'employee', 'HZ-06');
        $this->mudir = $this->user('mudir', 'department_manager', 'HZ-06'); // يملك emergency.respond و trigger وليس صاحب البطاقات
        $this->building = EmergencyBuilding::main();
        AssemblyPoint::create(['building_id' => $this->building->id, 'code' => 'A1', 'name' => 'الساحة الأمامية', 'is_primary' => true, 'capacity' => 300]);
        $team = EmergencyTeam::create(['building_id' => $this->building->id, 'place_id' => Place::idByCode('HZ-06'),
            'name' => 'الفريق الأولي — المكاتب', 'team_type' => 'initial', 'shift' => 'all', 'is_active' => true, 'source' => 'manual']);
        EmergencyTeamMember::create(['team_id' => $team->id, 'user_id' => $this->medic->id, 'name' => $this->medic->name, 'role' => 'member', 'role_key' => 'medic', 'is_available' => true]);
    }

    private function user(string $username, string $role, ?string $placeCode = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'place_id' => Place::idByCode($placeCode)]);
        return $u;
    }

    private function trigger(string $type = 'fire'): EmergencyIncident
    {
        return app(EmergencyService::class)->triggerAlarm($this->building, $type, $this->salama, 'high', false, 'اختبار ٢٧-أ', Place::idByCode('HZ-06'));
    }

    private function card(User $u, string $prefix): ?Task
    {
        return app(InboxService::class)->forUser($u->fresh())->first(fn (Task $t) => str_starts_with($t->key, $prefix));
    }

    /** البطاقة لأصحابها وحدهم */
    private function assertOwners(string $prefix, array $owners, array $others): void
    {
        foreach ($owners as $u) $this->assertNotNull($this->card($u, $prefix), "{$u->username}: بطاقة «{$prefix}» غائبة عن صاحبها");
        foreach ($others as $u) $this->assertNull($this->card($u, $prefix), "{$u->username}: بطاقة «{$prefix}» تظهر لغير صاحبها");
    }

    private function assertGone(string $prefix): void
    {
        foreach ([$this->salama, $this->munawib, $this->commander, $this->medic, $this->employee, $this->mudir] as $u) {
            $this->assertNull($this->card($u, $prefix), "{$u->username}: بطاقة «{$prefix}» باقية بعد الفعل");
        }
    }

    /** ٢٤: حالة مفتوحة لم يستلمها أحد ← المركز والفريق الأولي للمكان (من له حساب)؛ أول من يضغط يُقرّ فتختفي عن الجميع */
    public function test_unacknowledged_case_asks_the_center_and_the_places_team_until_one_acknowledges(): void
    {
        $inc = $this->trigger();
        $this->assertOwners('eack:', [$this->salama, $this->munawib, $this->medic], [$this->employee, $this->mudir, $this->commander]);
        $t = $this->card($this->medic, 'eack:');
        $this->assertSame('eack:'.$inc->id, $t->key);
        $this->assertStringContainsString($inc->incident_code, $t->question);
        $this->assertStringContainsString('لم يستلمها أحد', $t->question);
        $this->assertSame('POST', $t->primaryMethod());
        $this->assertTrue($t->isOverdue);

        $this->actingAs($this->medic)->post($t->primary['url'])->assertRedirect();
        $this->assertSame($this->medic->id, $inc->fresh()->acknowledged_by_id);
        $this->assertGone('eack:');
    }

    /** ٢٥: حالة نشطة ← قائد فريق الطوارئ (بطاقة الدور ١) والمركز: «سُيطر عليها؟»؛ بعد السيطرة «أنهِها» من شاشة الحالة؛ بعد الإنهاء تختفي */
    public function test_open_case_asks_the_commander_and_the_center_to_contain_then_end(): void
    {
        $inc = $this->trigger();
        $this->assertOwners('ectl:', [$this->commander, $this->salama, $this->munawib], [$this->medic, $this->employee, $this->mudir]);
        $t = $this->card($this->commander, 'ectl:');
        $this->assertSame('ectl:'.$inc->id, $t->key);
        $this->assertStringContainsString('سُيطر عليها؟', $t->question);
        $this->assertSame(route('emergency.incidents.contain', $inc), $t->primary['url']);
        $this->assertSame('POST', $t->primaryMethod());

        $this->actingAs($this->commander)->post($t->primary['url'])->assertRedirect();
        $this->assertSame('contained', $inc->fresh()->status);
        $t = $this->card($this->commander, 'ectl:');
        $this->assertNotNull($t, 'بعد السيطرة تبقى البطاقة بسؤال الإنهاء');
        $this->assertStringContainsString('أنهِها', $t->question);
        $this->assertSame('GET', $t->primaryMethod()); // الإنهاء من شاشة الحالة: فيها تنبيه من لم يصل وطلبات المساعدة (٢٢-١٥)
        $this->assertStringStartsWith(route('emergency.incidents.live', $inc), $t->primary['url']);
        $this->actingAs($this->commander)->get($t->primary['url'])->assertOk();

        $this->actingAs($this->commander)->post(route('emergency.incidents.end', $inc), ['final_report' => 'انتهى'])->assertRedirect();
        $this->assertGone('ectl:');
    }

    /** ٢٧: في الحصر من لم يسجّل وصوله أو عُلّم مفقوداً ← المركز: تحقّق؛ حين يُحسب الجميع تختفي */
    public function test_unaccounted_people_ask_the_center_until_everyone_is_accounted_for(): void
    {
        $inc = $this->trigger();
        $rows = EvacuationCheckIn::where('incident_id', $inc->id);
        $this->assertGreaterThan(0, (clone $rows)->where('status', 'evacuating')->count(), 'لا حصر عند التفعيل — الاختبار لا يقيس');
        $this->assertOwners('emuster:', [$this->salama, $this->munawib], [$this->commander, $this->medic, $this->employee, $this->mudir]);
        $n = (clone $rows)->where('status', 'evacuating')->count();
        $t = $this->card($this->munawib, 'emuster:');
        $this->assertStringContainsString('لم يسجّل وصوله: '.$n, $t->question);
        // العدد هو عدد شاشة الحالة نفسه
        $this->assertSame($n, app(\App\Modules\Emergency\Services\QrMusteringService::class)->getLiveStats($inc)['evacuating']);

        // مفقود يُذكر بعدده
        $one = (clone $rows)->where('status', 'evacuating')->first();
        $this->actingAs($this->munawib)->post(route('emergency.incidents.markMissing', $inc), ['check_in_id' => $one->id])->assertRedirect();
        $this->assertStringContainsString('مفقود: 1', $this->card($this->munawib, 'emuster:')->question);
        $this->actingAs($this->munawib)->get($this->card($this->munawib, 'emuster:')->primary['url'])->assertOk();

        // الجميع بأمان ← تختفي
        EvacuationCheckIn::where('incident_id', $inc->id)->update(['status' => 'safe']);
        $this->assertGone('emuster:');
    }

    /** ٢٢: رسالة جماعية لحالة مفتوحة لم يردّ عليها بعضهم ← المركز: ذكّرهم؛ بعد التذكير تختفي (من بقي يظهر في الحصر) */
    public function test_unanswered_mass_message_asks_the_center_to_remind_once(): void
    {
        $inc = $this->trigger();
        $msg = app(EmergencyMessagingService::class)->sendMassMessage(['incident_id' => $inc->id, 'title' => 'أمر إخلاء', 'message' => 'اخرجوا', 'channels' => ['app']], $this->salama);
        $this->assertOwners('emsgfu:', [$this->salama, $this->munawib], [$this->commander, $this->medic, $this->employee, $this->mudir]);
        $t = $this->card($this->munawib, 'emsgfu:');
        $this->assertSame('emsgfu:'.$msg->id, $t->key);
        $this->assertStringContainsString('لم يردّ', $t->question);
        $this->assertSame(route('emergency.messages.follow-up', $msg), $t->primary['url']);
        $this->assertSame('POST', $t->primaryMethod());

        $this->actingAs($this->munawib)->post($t->primary['url'])->assertRedirect();
        $this->assertGone('emsgfu:');
    }

    /** ٢٨: إغلاق أمني سارٍ ← المركز: ارفعه (من لوحة المبنى، لأن الرفع يُنهي حالته)؛ وحالته لا تأخذ بطاقة سيطرة ثانية */
    public function test_active_lockdown_asks_the_center_to_lift_it_and_replaces_the_control_card(): void
    {
        $this->actingAs($this->salama)->post("/app/emergency/buildings/{$this->building->id}/lockdown", ['level' => 'full', 'reason' => 'شخص مشبوه'])->assertRedirect();
        $lock = Lockdown::firstOrFail();
        $this->assertOwners('elock:', [$this->salama, $this->munawib], [$this->commander, $this->medic, $this->employee, $this->mudir]);
        $t = $this->card($this->munawib, 'elock:');
        $this->assertSame('elock:'.$lock->id, $t->key);
        $this->assertStringContainsString('إغلاق أمني', $t->question);
        $this->assertSame('GET', $t->primaryMethod());
        $this->actingAs($this->munawib)->get($t->primary['url'])->assertOk();
        $this->assertNull($this->card($this->munawib, 'ectl:'.$lock->incident_id), 'حالة الإغلاق أخذت بطاقة سيطرة مع بطاقة الرفع');

        $this->actingAs($this->munawib)->post(route('emergency.lockdowns.lift', $lock), ['reason' => 'زال الخطر'])->assertRedirect();
        $this->assertGone('elock:');
    }

    /** ٣٠: تقرير ما بعد الحادث رُفع للمراجعة ← المركز: اعتمده؛ ثم «انشره»؛ ثم تختفي */
    public function test_report_under_review_asks_the_center_to_approve_then_publish(): void
    {
        $inc = $this->trigger();
        app(EmergencyService::class)->endIncident($inc->fresh(), $this->salama, 'انتهى');
        $this->actingAs($this->munawib)->post(route('emergency.incidents.aar', $inc))->assertRedirect();
        $report = AfterActionReport::firstOrFail();
        $this->assertNull($this->card($this->salama, 'aarreview:'), 'مسودة لم تُرفع للمراجعة لا تنتظر اعتماداً');

        $this->actingAs($this->munawib)->post(route('emergency.aar.submit', $report))->assertRedirect();
        $this->assertOwners('aarreview:', [$this->salama, $this->munawib], [$this->commander, $this->medic, $this->employee, $this->mudir]);
        $t = $this->card($this->salama, 'aarreview:');
        $this->assertSame('aarreview:'.$report->id, $t->key);
        $this->assertStringContainsString($inc->incident_code, $t->question);
        $this->assertSame('POST', $t->primaryMethod());
        $this->assertSame(route('emergency.aar.show', $report), $t->secondary['url']);

        $this->actingAs($this->salama)->post($t->primary['url'])->assertRedirect();
        $this->assertSame('approved', $report->fresh()->status);
        $this->assertGone('aarreview:');
        $t = $this->card($this->salama, 'aarpublish:');
        $this->assertNotNull($t, 'تقرير معتمد لم يُنشر لا بطاقة له');
        $this->actingAs($this->salama)->post($t->primary['url'])->assertRedirect();
        $this->assertSame('published', $report->fresh()->status);
        $this->assertGone('aarpublish:');
    }

    /** البطاقات في الصفحة الأولى تحت «الطوارئ»، وعددها في عدّاد «ما ينتظرك» */
    public function test_cards_render_on_the_home_page_under_emergency(): void
    {
        $inc = $this->trigger();
        $html = $this->actingAs($this->munawib)->get('/app')->assertOk()->getContent();
        $this->assertSame(1, preg_match('~<section data-module="الطوارئ".*?</section>~s', $html, $m));
        foreach (['eack:', 'ectl:', 'emuster:'] as $p) $this->assertStringContainsString('data-task="'.$p.$inc->id.'"', $m[0]);
        $this->assertStringContainsString('action="'.route('emergency.incidents.acknowledge', $inc).'"', $m[0]);
    }
}
