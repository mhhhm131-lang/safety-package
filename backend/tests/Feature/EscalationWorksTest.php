<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Emergency\Models\AssemblyPoint;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyEventLog;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Services\AutoEscalationService;
use App\Modules\Emergency\Services\EmergencyService;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\Setting;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٢٢-١٠ (د): مهل التصعيد الأربع.
 *
 * التصعيد = إن تعثّرت الحالة يُنبَّه من هو أعلى تلقائياً: المنسق والمناوب ← القيادة ← الإدارة العليا
 * ← الجهات الخارجية. القواعد خمس: أربعٌ تنتظر **أرقام المستخدم** (لا إقرار · لم يصل أحد · مفقودون
 * بلا تحديث · طول المدة)، والخامسة (الخطورة الحرجة) تعمل بلا رقم.
 *
 * هذا الاختبار يثبت **أن الآلية تعمل** بأرقام اختبارية — الأرقام الحقيقية قرار المستخدم ولا تُخترع.
 */
class EscalationWorksTest extends TestCase
{
    use RefreshDatabase;

    private User $salama;
    private User $coord;
    private User $exec;
    private EmergencyBuilding $building;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlacesSeeder::class);
        $this->seed(OrganizationUnitsSeeder::class);
        $this->seed(EmergencySeeder::class);

        $this->salama = $this->user('salama', 'system_admin');
        $this->coord = $this->user('coord', 'safety_coordinator', 'HZ-06');
        $this->exec = $this->user('idara', 'top_management');

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

    private function trigger(string $severity = 'high'): EmergencyIncident
    {
        return app(EmergencyService::class)->triggerAlarm(
            $this->building, 'fire', $this->salama, $severity, false, 'اختبار التصعيد', Place::idByCode('HZ-06')
        );
    }

    /** الشاشة تحمل القواعد الأربع بلا قيم مخترعة. */
    public function test_the_screen_shows_the_four_rules_with_no_invented_numbers(): void
    {
        $html = $this->actingAs($this->salama)->get('/app/emergency/settings')->assertOk()->getContent();

        foreach (AutoEscalationService::SETTING_KEYS as $key => $label) {
            $this->assertStringContainsString($label, $html, "القاعدة «{$label}» غير معروضة");
            $this->assertStringContainsString('name="minutes['.$key.']"', $html);
        }
        $this->assertStringContainsString('لم تُقرر', $html, 'الخانات تحمل قيماً مخترعة');
    }

    /** بلا أرقام: لا تصعيد — ولا يُخترع رقم. */
    public function test_without_numbers_nothing_escalates(): void
    {
        $incident = $this->trigger();
        $this->travel(90)->minutes();

        $this->artisan('emergency:check-escalation')->assertSuccessful();

        $this->assertSame(1, (int) $incident->fresh()->escalation_level, 'صُعّدت الحالة بلا رقم من المستخدم');
    }

    /** المستخدم يدخل رقمه من الشاشة، فيعمل المؤقت ويُسجَّل التصعيد ويُنبَّه أصحاب المستوى. */
    public function test_a_number_from_the_screen_makes_the_timer_work(): void
    {
        $this->actingAs($this->salama)->post('/app/emergency/settings', [
            'minutes' => ['emergency.escalation.no_ack_min' => 2],
        ])->assertRedirect();
        $this->assertSame('2', (string) Setting::get('emergency.escalation.no_ack_min'));

        $incident = $this->trigger();
        $this->assertSame(1, (int) $incident->fresh()->escalation_level);

        // قبل المهلة: لا شيء
        $this->travel(1)->minutes();
        $this->artisan('emergency:check-escalation');
        $this->assertSame(1, (int) $incident->fresh()->escalation_level, 'صُعّدت قبل انقضاء المهلة');

        // بعدها: يرتفع إلى «المنسق والمناوب» ويُكتب في الخط الزمني
        $this->travel(5)->minutes();
        $this->artisan('emergency:check-escalation');
        $this->assertSame(AutoEscalationService::LEVEL_SUPERVISOR, (int) $incident->fresh()->escalation_level);
        $this->assertNotNull($incident->fresh()->escalated_at);

        $log = EmergencyEventLog::where('incident_id', $incident->id)
            ->where('event_type', EmergencyEventLog::TYPE_ESCALATION)->first();
        $this->assertNotNull($log, 'التصعيد لم يُسجَّل في الخط الزمني');
        $this->assertStringContainsString('لم يُقرّ أحد', $log->message);

        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->coord->id]);
    }

    /** الإقرار يوقف التصعيد — فالعدّاد للتعثّر لا للوقت وحده. */
    public function test_acknowledging_stops_the_escalation(): void
    {
        Setting::set('emergency.escalation.no_ack_min', 2, $this->salama->id);
        $incident = $this->trigger();
        app(EmergencyService::class)->acknowledge($incident, $this->salama);

        $this->travel(30)->minutes();
        $this->artisan('emergency:check-escalation');

        $this->assertSame(1, (int) $incident->fresh()->escalation_level, 'صُعّدت رغم الإقرار');
    }

    /** الخطورة الحرجة تصعد فوراً بلا رقم — وهي القاعدة الخامسة العاملة اليوم. */
    public function test_critical_severity_escalates_with_no_number(): void
    {
        $incident = $this->trigger('critical');
        $this->artisan('emergency:check-escalation');

        $this->assertSame(AutoEscalationService::LEVEL_EXECUTIVE, (int) $incident->fresh()->escalation_level);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->exec->id]);
    }

    /** المؤقت مجدول كل دقيقة — وإلا لم يعمل شيء من هذا في الواقع. */
    public function test_the_timer_is_scheduled_every_minute(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command ?? '', 'emergency:check-escalation'));

        $this->assertCount(1, $events, 'المؤقت غير مجدول');
        $this->assertSame('* * * * *', $events->first()->expression);
    }
}
