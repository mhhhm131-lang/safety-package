<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Models\EmergencyIncidentStep;
use App\Modules\Emergency\Services\ResponsePlanSync;
use App\Modules\Emergency\Services\TeamSync;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Store\Models\InstituteDocument;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * المرحلة ١٠-٢ — القائمة الحية والتنبيه والتجاوز (BACKEND.md قرار ٣٠، المكوّنات ج + د + هـ). البوابة:
 *   حريق HZ-06 ← ١٢ خطوة بعدّاد ← وصول عضو فريق يعلّم الخطوة ١ آلياً ← «تم» يسجّل بالثانية ومن ← خطوة متجاوزة تُنبّه صاحبها
 *   ← السجل الزمني يحوي كل ذلك.
 */
class IncidentStepsTest extends TestCase
{
    use RefreshDatabase;

    private User $salama; private User $munawib; private User $coord; private User $fani; private User $marafiq; private User $shuon; private User $employee;
    private EmergencyBuilding $building;

    protected function setUp(): void
    {
        parent::setUp();
        $root = dirname(base_path());
        if (!is_file($root.'/HZ-06-offices/response-plan.html')) $this->markTestSkipped('وثائق المعهد غير موجودة');
        $this->seed(PlacesSeeder::class);
        $this->seed(OrganizationUnitsSeeder::class);
        $this->seed(EmergencySeeder::class);
        app(ResponsePlanSync::class)->sync($root);

        $this->salama = $this->user('salama', 'system_admin');
        $this->munawib = $this->user('munawib', 'system_staff');
        $this->coord = $this->user('coord', 'safety_coordinator', 'HZ-06');
        $this->fani = $this->user('fani', 'field_worker', 'HZ-06');
        $this->marafiq = $this->user('marafiq', 'facilities_manager');   // بطاقة ٢
        $this->shuon = $this->user('shuon', 'admin_eng_manager');        // بطاقة ١ قائد الطوارئ
        $this->employee = $this->user('emp', 'employee');
        $this->building = EmergencyBuilding::main();

        $unit = OrganizationUnit::first();
        InstituteDocument::create(['key' => 'ipa-place', 'version' => 1, 'data' => json_encode(['HZ-06' => ['plans' => [], 'units' => [$unit->code => [
            'team' => [
                ['role' => 'المنسق', 'name' => 'أحمد المنسق', 'dept' => 'الموارد البشرية', 'phone' => '0500000001', 'trained' => '', 'trainer' => ''],
                ['role' => 'المسعف', 'name' => 'سعد المسعف', 'dept' => 'الموارد البشرية', 'phone' => '0500000002', 'trained' => '', 'trainer' => ''],
                ['role' => 'المنقذ', 'name' => 'خالد المنقذ', 'dept' => 'الموارد البشرية', 'phone' => '0500000003', 'trained' => '', 'trainer' => ''],
                ['role' => 'الإطفائي', 'name' => 'فهد الإطفائي', 'dept' => 'الموارد البشرية', 'phone' => '', 'trained' => '', 'trainer' => ''],
            ],
            'nom' => ['by' => 'x', 'dept' => $unit->code, 'date' => '2026-03-01'], 'appr' => ['by' => 'y', 'date' => '2026-03-05'], 'hr' => [],
        ]]]], JSON_UNESCAPED_UNICODE)]);
        app(TeamSync::class)->sync();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $username, string $role, ?string $placeCode = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'place_id' => Place::idByCode($placeCode)]);
        return $u;
    }

    private function fire(string $place = 'HZ-06', string $type = 'fire'): EmergencyIncident
    {
        $this->actingAs($this->salama)->post("/app/emergency/buildings/{$this->building->id}/trigger", [
            'incident_type' => $type, 'severity' => 'high', 'place_id' => Place::idByCode($place),
        ])->assertRedirect();
        return EmergencyIncident::orderByDesc('id')->first();
    }

    private function step(EmergencyIncident $i, string $title, string $path = 'fire'): EmergencyIncidentStep
    {
        return $i->planSteps()->where('path_key', $path)->where('title', $title)->firstOrFail();
    }

    public function test_fire_in_hz06_copies_12_steps_with_deadlines_and_notifies_owners(): void
    {
        $i = $this->fire();
        $steps = $i->planSteps;
        $this->assertCount(12, $steps);
        $this->assertSame(['medical' => 3, 'fire' => 9], $steps->groupBy('path_key')->map->count()->all());
        $this->assertTrue($steps->every(fn ($s) => $s->status === 'pending'));

        $s3 = $this->step($i, 'التحكم بالأنظمة الحرجة');
        $this->assertSame($i->triggered_at->copy()->addSeconds(5)->toDateTimeString(), $s3->due_at->toDateTimeString()); // الثانية الأولى = ٠–٥ ث
        $this->assertSame(2, $s3->role_card_no);
        $this->assertSame($i->triggered_at->copy()->addSeconds(900)->toDateTimeString(), $this->step($i, 'استقبال الدفاع المدني وإرشاده')->due_at->toDateTimeString());
        $this->assertNull($this->step($i, 'قرار إخلاء المبنى الكامل')->due_at); // شرطية: عند عدم السيطرة
        $this->assertTrue($this->step($i, 'قرار إخلاء المبنى الكامل')->is_conditional);

        // السجل الزمني
        $this->assertTrue($i->eventLogs()->where('event_type', 'plan_step')->where('message', 'like', 'قائمة خطوات الخطة: 12 خطوة من وثيقة HZ-06%')->exists());

        // (د) كل صاحب دور خطوته هو: مدير المرافق (٢) يصله «التحكم بالأنظمة الحرجة»؛ قائد الطوارئ (١) يصله «تقييم الحريق»؛ الموظف لا يصله شيء
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->marafiq->id, 'type' => 'emergency.plan_step']);
        $body = \App\Modules\Governance\Models\AppNotification::where('user_id', $this->marafiq->id)->where('type', 'emergency.plan_step')->value('message');
        $this->assertStringContainsString('التحكم بالأنظمة الحرجة', $body);
        $this->assertStringNotContainsString('استقبال الدفاع المدني', $body); // خطوة رئيس الأمن لا مدير المرافق
        $shuonBody = \App\Modules\Governance\Models\AppNotification::where('user_id', $this->shuon->id)->where('type', 'emergency.plan_step')->value('message');
        $this->assertStringContainsString('تقييم الحريق', $shuonBody);
        $this->assertDatabaseMissing('app_notifications', ['user_id' => $this->employee->id, 'type' => 'emergency.plan_step']);

        // حالة طبية: المسار الطبي وحده
        $this->actingAs($this->munawib)->post("/app/emergency/incidents/{$i->id}/cancel", ['reason' => 'اختبار']);
        $m = $this->fire('HZ-06', 'medical');
        $this->assertSame(['medical' => 3], $m->planSteps->groupBy('path_key')->map->count()->all());
    }

    public function test_team_member_arrival_marks_initial_intervention_automatically(): void
    {
        $i = $this->fire();
        Carbon::setTestNow($i->triggered_at->copy()->addSeconds(3));
        $medic = $i->checkIns()->where('person_type', 'team')->whereHas('teamMember', fn ($q) => $q->where('role_key', 'medic'))->first();
        $this->actingAs($this->coord)->post("/app/emergency/incidents/{$i->id}/check-in", ['check_in_id' => $medic->id])->assertSessionHas('success');

        foreach (['medical', 'fire'] as $path) {
            $s = $i->planSteps()->where('path_key', $path)->where('label', '١')->first();
            $this->assertSame('done', $s->status, $path);
            $this->assertSame('team_arrived', $s->auto_source);
            $this->assertSame('المسعف سعد المسعف', $s->done_by_name);
            $this->assertNull($s->done_by_id);
            $this->assertSame(3 - 5, $s->delta_sec); // بعد ٣ ث والحد ٥ ث = قبل الحد بثانيتين
        }
        $this->assertSame(10, $i->planSteps()->pending()->count());
        $this->assertTrue($i->eventLogs()->where('event_type', 'plan_step')->where('message', 'like', '%تمت الخطوة ١ «التدخل الأولي%آلياً من وصول عضو الفريق الأولي%')->exists());
    }

    public function test_done_records_second_actor_and_delta_and_skip_needs_reason(): void
    {
        $i = $this->fire();
        $s3 = $this->step($i, 'التحكم بالأنظمة الحرجة');
        Carbon::setTestNow($i->triggered_at->copy()->addSeconds(42));
        $this->actingAs($this->marafiq)->post("/app/emergency/incidents/{$i->id}/steps/{$s3->id}/done")->assertSessionHas('success');
        $s3->refresh();
        $this->assertSame('done', $s3->status);
        $this->assertSame(now()->toDateTimeString(), $s3->done_at->toDateTimeString());
        $this->assertSame($this->marafiq->id, $s3->done_by_id);
        $this->assertSame(37, $s3->delta_sec); // ٤٢ ث − ٥ ث
        $this->assertNull($s3->auto_source);
        $this->assertTrue($i->eventLogs()->where('event_type', 'plan_step')->where('severity', 'warning')->where('message', 'like', '%تمت الخطوة ٣ «التحكم بالأنظمة الحرجة» بعد 42 ث من التفعيل (المستهدف حتى 5 ث — تأخر 37 ث)%')->exists());
        // لا تُعلَّم مرتين
        $this->actingAs($this->munawib)->post("/app/emergency/incidents/{$i->id}/steps/{$s3->id}/done")->assertSessionHas('error');
        // التخطي يحتاج سبباً
        $s7 = $this->step($i, 'قرار إخلاء المبنى الكامل');
        $this->actingAs($this->munawib)->post("/app/emergency/incidents/{$i->id}/steps/{$s7->id}/skip")->assertSessionHasErrors('note');
        $this->actingAs($this->munawib)->post("/app/emergency/incidents/{$i->id}/steps/{$s7->id}/skip", ['note' => 'تمت السيطرة فلا إخلاء'])->assertSessionHas('success');
        $this->assertSame('skipped', $s7->fresh()->status);
        // خطوة من حالة أخرى لا تُقبل؛ الموظف ممنوع؛ الفني (استجابة ميدانية) يعلّم
        $this->actingAs($this->munawib)->post("/app/emergency/incidents/{$i->id}/steps/999999/done")->assertNotFound();
        $this->actingAs($this->employee)->post("/app/emergency/incidents/{$i->id}/steps/{$this->step($i, 'استدعاء الطبيب', 'medical')->id}/done")->assertForbidden();
        $this->actingAs($this->fani)->post("/app/emergency/incidents/{$i->id}/steps/{$this->step($i, 'استدعاء الطبيب', 'medical')->id}/done")->assertSessionHas('success');
    }

    public function test_overdue_step_writes_red_line_and_alerts_owner_and_leadership_once(): void
    {
        $i = $this->fire();
        Carbon::setTestNow($i->triggered_at->copy()->addSeconds(120));
        $this->artisan('emergency:check-escalation')->assertSuccessful();

        $s3 = $this->step($i, 'التحكم بالأنظمة الحرجة');            // ٠–٥ ث → متجاوزة
        $this->assertNotNull($s3->overdue_alerted_at);
        $this->assertSame('pending', $s3->status);
        $this->assertNull($this->step($i, 'قرار إخلاء المبنى الكامل')->overdue_alerted_at);   // شرطية لا تتجاوز
        $this->assertNull($this->step($i, 'استقبال الدفاع المدني وإرشاده')->overdue_alerted_at); // ٥–١٥ د لم تحن
        $this->assertTrue($i->eventLogs()->where('event_type', 'plan_step')->where('severity', 'critical')
            ->where('message', 'like', 'تجاوز: الخطوة ٣ «التحكم بالأنظمة الحرجة»%تأخر 1:55 د%صاحبها: 2 مدير المرافق والصيانة%')->exists());
        foreach ([$this->marafiq, $this->salama, $this->munawib, $this->shuon] as $u) {
            $this->assertDatabaseHas('app_notifications', ['user_id' => $u->id, 'type' => 'emergency.step_overdue']);
        }
        $this->assertDatabaseMissing('app_notifications', ['user_id' => $this->employee->id, 'type' => 'emergency.step_overdue']);
        // مرة واحدة لكل خطوة
        $before = $i->eventLogs()->where('event_type', 'plan_step')->where('severity', 'critical')->count();
        $this->artisan('emergency:check-escalation');
        $this->assertSame($before, $i->eventLogs()->where('event_type', 'plan_step')->where('severity', 'critical')->count());
        // الخطوات ذات نافذة ≤ ٦٠ ث كلها متجاوزة: ٣ طبي (١، ٢) + حريق (١–٦) = ٨؛ الطبيب ١–٣ د لم تحن بعد ١٢٠ ث
        $this->assertSame(8, $i->planSteps()->whereNotNull('overdue_alerted_at')->count());
    }

    public function test_contain_and_end_autocomplete_and_the_live_screen_shows_the_list(): void
    {
        $i = $this->fire();
        $r = $this->actingAs($this->munawib)->get("/app/emergency/incidents/{$i->id}/live");
        $r->assertOk()->assertSee('خطوات الخطة — HZ-06')->assertSee('التحكم بالأنظمة الحرجة')->assertSee('2 مدير المرافق والصيانة')->assertSee('steps/'.$this->step($i, 'استدعاء الطبيب', 'medical')->id.'/done')->assertSee('تمت <span id="steps-done">0</span> / 12', false);
        // من لا حساب له: خطوته بجانب اسمه في قائمة النداء
        $r->assertSee('سعد المسعف')->assertSee('خطوته: ١ التدخل الأولي');
        $this->actingAs($this->fani)->get("/app/emergency/incidents/{$i->id}/live")->assertOk()->assertSee('خطوات الخطة');
        $this->actingAs($this->employee)->get("/app/emergency/incidents/{$i->id}/live")->assertForbidden();
        $json = $this->actingAs($this->munawib)->getJson("/api/emergency/incidents/{$i->id}/steps")->assertOk()->json();
        $this->assertCount(12, $json['data']);
        $this->assertSame('pending', $json['data'][0]['status']);

        $this->actingAs($this->coord)->post("/app/emergency/incidents/{$i->id}/contain", ['note' => 'أُخمد'])->assertSessionHas('success');
        $eval = $this->step($i, 'تقييم الحريق');
        $this->assertSame('done', $eval->status);
        $this->assertSame('contained', $eval->auto_source);
        $this->assertSame($this->coord->id, $eval->done_by_id);

        $this->actingAs($this->salama)->post("/app/emergency/incidents/{$i->id}/end", ['final_report' => 'انتهى'])->assertRedirect();
        $restore = $this->step($i, 'التحقيق والتوثيق واستعادة التشغيل');
        $this->assertSame('done', $restore->status);
        $this->assertSame('ended', $restore->auto_source);
        // ما لم يُعلَّم يبقى معلّقاً في السجل (لا يُخترع إتمام)
        $this->assertSame(10, $i->planSteps()->pending()->count()); // ١٢ − تقييم (السيطرة) − استعادة (الإنهاء)
        $this->actingAs($this->munawib)->get("/app/emergency/incidents/{$i->id}/live")->assertOk()->assertDontSee('steps/'.$eval->id.'/done');
    }
}
