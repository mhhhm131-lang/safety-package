<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Emergency\Models\AarCorrectiveAction;
use App\Modules\Emergency\Models\AfterActionReport;
use App\Modules\Emergency\Models\AssemblyPoint;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Services\EmergencyService;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٢٢-٧ (د): تقرير ما بعد الحادث بزر واحد.
 *
 * العيب من جولة ٢٢-١: أربع عشرة وظيفة كاملة (إنشاء من الحالة، تحرير، رفع للمراجعة، اعتماد، نشر،
 * إجراءات تصحيحية) **بلا أي شاشة** — لا زر في صفحة تقرير الحالة ولا مدخل في القائمة. والتقرير
 * بعد الحادث مطلب نظاميّ، وما يُكتب فيه هو ما يمنع تكرار الحادث.
 *
 * البوابة: تقرير يُبنى بزر واحد من سجل الحالة ويُعتمد، وإجراء تصحيحي يصل صاحبه في بطاقاته.
 */
class AfterActionReportTest extends TestCase
{
    use RefreshDatabase;

    private User $salama;
    private User $marafiq;
    private User $employee;
    private EmergencyBuilding $building;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlacesSeeder::class);
        $this->seed(OrganizationUnitsSeeder::class);
        $this->seed(EmergencySeeder::class);

        $this->salama = $this->user('salama', 'system_admin');
        $this->marafiq = $this->user('marafiq', 'facilities_manager');
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

    /** حالة كاملة من التفعيل إلى الإنهاء — التقرير يُبنى من سجلها. */
    private function endedIncident(): EmergencyIncident
    {
        $svc = app(EmergencyService::class);
        $incident = $svc->triggerAlarm($this->building, 'fire', $this->salama, 'high', false,
            'اختبار تقرير ما بعد الحادث', Place::idByCode('HZ-06'));
        $svc->acknowledge($incident, $this->salama);
        $svc->contain($incident, $this->salama, 'سُيطر عليه');
        return $svc->endIncident($incident, $this->salama, 'انتهى الخطر');
    }

    public function test_the_report_page_carries_the_build_button(): void
    {
        $incident = $this->endedIncident();

        $this->actingAs($this->salama)->get("/app/emergency/incidents/{$incident->id}/report")
            ->assertOk()
            ->assertSee('تقرير ما بعد الحادث', false);
    }

    public function test_one_button_builds_the_report_from_the_incident_record(): void
    {
        $incident = $this->endedIncident();

        $this->actingAs($this->salama)
            ->post("/app/emergency/incidents/{$incident->id}/aar")
            ->assertRedirect();

        $report = AfterActionReport::where('incident_id', $incident->id)->first();
        $this->assertNotNull($report, 'لم يُبنَ التقرير');
        $this->assertSame(AfterActionReport::STATUS_DRAFT, $report->status);
        $this->assertSame($this->salama->id, $report->prepared_by_id);
        // مبنيّ من سجل الحالة لا من فراغ
        $this->assertNotNull($report->incident_start_at);
        $this->assertNotNull($report->incident_end_at);
        $this->assertNotNull($report->resolution_time_minutes);
        $this->assertStringContainsString($incident->incident_code, $report->title);
    }

    public function test_the_same_incident_does_not_get_two_reports(): void
    {
        $incident = $this->endedIncident();
        $this->actingAs($this->salama)->post("/app/emergency/incidents/{$incident->id}/aar");
        $this->actingAs($this->salama)->post("/app/emergency/incidents/{$incident->id}/aar");

        $this->assertSame(1, AfterActionReport::where('incident_id', $incident->id)->count());
    }

    public function test_report_is_written_approved_and_published(): void
    {
        $incident = $this->endedIncident();
        $this->actingAs($this->salama)->post("/app/emergency/incidents/{$incident->id}/aar");
        $report = AfterActionReport::where('incident_id', $incident->id)->firstOrFail();

        $this->actingAs($this->salama)->get("/app/emergency/aar/{$report->id}")
            ->assertOk()
            ->assertSee('ما الذي سار جيداً', false)
            ->assertSee('ما الذي لم يسر جيداً', false);

        $this->actingAs($this->salama)->post("/app/emergency/aar/{$report->id}", [
            'what_went_well' => 'وصل الفريق في دقيقتين',
            'what_went_wrong' => 'لم يُقرّ المناوب باستلام الحالة',
            'lessons_learned' => 'الإقرار من الجوال',
        ])->assertRedirect();
        $this->assertSame('وصل الفريق في دقيقتين', $report->fresh()->what_went_well);

        $this->actingAs($this->salama)->post("/app/emergency/aar/{$report->id}/submit")->assertRedirect();
        $this->assertSame(AfterActionReport::STATUS_UNDER_REVIEW, $report->fresh()->status);

        $this->actingAs($this->salama)->post("/app/emergency/aar/{$report->id}/approve")->assertRedirect();
        $this->assertSame(AfterActionReport::STATUS_APPROVED, $report->fresh()->status);
        $this->assertSame($this->salama->id, $report->fresh()->approved_by_id);

        $this->actingAs($this->salama)->post("/app/emergency/aar/{$report->id}/publish")->assertRedirect();
        $this->assertSame(AfterActionReport::STATUS_PUBLISHED, $report->fresh()->status);
    }

    public function test_a_corrective_action_reaches_the_person_it_was_given_to(): void
    {
        $incident = $this->endedIncident();
        $this->actingAs($this->salama)->post("/app/emergency/incidents/{$incident->id}/aar");
        $report = AfterActionReport::where('incident_id', $incident->id)->firstOrFail();

        $this->actingAs($this->salama)->post("/app/emergency/aar/{$report->id}/actions", [
            'title' => 'استبدال طفاية الدور الثاني',
            'assigned_to_id' => $this->marafiq->id,
            'due_date' => now()->addDays(7)->toDateString(),
            'priority' => 'high',
        ])->assertRedirect();

        $action = AarCorrectiveAction::where('report_id', $report->id)->first();
        $this->assertNotNull($action, 'لم يُسجَّل الإجراء التصحيحي');
        $this->assertSame($this->marafiq->id, $action->assigned_to_id);

        // يظهر بطاقةً في «ما ينتظرك» بزر «أنجزته»
        $home = $this->actingAs($this->marafiq)->get('/app')->assertOk();
        $home->assertSee('استبدال طفاية الدور الثاني', false);
        $home->assertSee(route('emergency.aar.actions.done', $action->id), false);

        $this->actingAs($this->marafiq)->post("/app/emergency/aar/actions/{$action->id}/done")->assertRedirect();
        $this->assertNotNull($action->fresh()->completed_date, 'لم يُغلق الإجراء');

        // تختفي البطاقة؛ ويبقى سطر الإشعار في جرس الإشعارات — وهو سجل لا مهمة
        $this->actingAs($this->marafiq)->get('/app')->assertOk()
            ->assertDontSee(route('emergency.aar.actions.done', $action->id), false);
    }

    public function test_only_the_centre_writes_the_report(): void
    {
        $incident = $this->endedIncident();
        $this->actingAs($this->employee)->post("/app/emergency/incidents/{$incident->id}/aar")->assertForbidden();
    }

    public function test_reports_have_a_list_reachable_from_the_menu(): void
    {
        $incident = $this->endedIncident();
        $this->actingAs($this->salama)->post("/app/emergency/incidents/{$incident->id}/aar");

        $this->actingAs($this->salama)->get('/app/emergency/aar')
            ->assertOk()
            ->assertSee($incident->incident_code, false);
    }
}
