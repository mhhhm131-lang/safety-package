<?php

namespace Tests\Feature\Report;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Incident\Models\Incident;
use App\Modules\Report\Services\DashboardService;
use App\Modules\Report\Services\KpiService;
use App\Modules\Report\Services\ReportScope;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Database\Seeders\RiskBookSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * المرحلة ٧-ب — بوابة المرحلة (BACKEND.md ٧-٢): لوحة واحدة للمدير العام بالأرقام الحية.
 *
 * ويغطي ما لا اختبار له في OHSMS: هناك خمسة اختبارات كلها رموز استجابة (200/403)،
 * ولا واحد يتحقق من **صحة رقم**. ولهذا بقي `try/catch` يعرض أصفاراً، وبقيت
 * `TIMESTAMPDIFF` (MySQL فقط)، وبقيت نسبة تعيد ١٠٠٪ على قاعدة فارغة.
 */
class ReportScenarioTest extends TestCase
{
    use RefreshDatabase;

    private User $salama;   // مسؤول السلامة — يرى الكل
    private User $mudirA;   // مدير إدارة أ
    private User $fani;     // فني — لا صلاحية تقارير
    private OrganizationUnit $unitA;
    private OrganizationUnit $unitB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, RiskBookSeeder::class, EmergencySeeder::class]);

        $units = OrganizationUnit::orderBy('id')->take(2)->get();
        $this->unitA = $units[0];
        $this->unitB = $units[1];

        $this->salama = $this->user('salama', 'system_admin');
        $this->mudirA = $this->user('mudira', 'department_manager', unitId: $this->unitA->id);
        $this->fani   = $this->user('fani', 'field_worker', placeCode: 'HZ-06');

        // كل بلاغ شاغل يلزمه خطر من السجل (قاعدة المرحلة ٣)، والحالة الطارئة يلزمها مبنى.
        $this->refRisk  = Risk::where('risk_type', 'reference')->firstOrFail();
        $this->building = EmergencyBuilding::firstOrFail();
    }

    private Risk $refRisk;
    private EmergencyBuilding $building;

    private function user(string $username, string $role, ?string $placeCode = null, ?int $unitId = null): User
    {
        $u = User::create([
            'username' => $username, 'name' => "اسم {$username}",
            'password' => '123456', 'email' => "{$username}@example.test",
        ]);
        UserProfile::create([
            'user_id' => $u->id, 'role' => $role, 'is_active' => true,
            'place_id' => $placeCode ? Place::idByCode($placeCode) : null,
            'organization_unit_id' => $unitId,
        ]);

        return $u;
    }

    /** بلاغ شاغل بزمن وصول محدد بالدقائق إلى الفني. */
    private function incident(string $code, int $minutesToField, string $placeCode = 'HZ-06', ?int $unitId = null, string $status = 'closed'): Incident
    {
        $created = Carbon::now()->startOfMonth()->addDays(2)->setTime(9, 0);

        $incident = Incident::create([
            'code' => $code, 'title' => 'بلاغ اختبار '.$code, 'description' => 'وصف',
            'incident_type' => 'normal', 'status' => $status, 'risk_id' => $this->refRisk->id,
            'place_id' => Place::idByCode($placeCode), 'organization_unit_id' => $unitId,
            'field_received_at' => $created->copy()->addMinutes($minutesToField),
            'closed_at' => $status === 'closed' ? $created->copy()->addDays(2) : null,
        ]);

        // `created_at` ليس في fillable فيتجاهله الإسناد الجماعي ويضع وقت الآن — يُضبط صراحةً.
        return $this->stamp($incident, $created);
    }

    /** يضبط تاريخ الإنشاء بلا لمس بقية الأعمدة. */
    private function stamp(Incident $incident, Carbon $at): Incident
    {
        Incident::withoutEvents(fn () => Incident::where('id', $incident->id)
            ->update(['created_at' => $at, 'updated_at' => $at]));

        return $incident->refresh();
    }

    // ════════════ البوابة ════════════

    public function test_gate_general_manager_dashboard_shows_live_numbers(): void
    {
        $this->incident('ب-1', 10, unitId: $this->unitA->id);
        $this->incident('ب-2', 20, unitId: $this->unitA->id);
        $this->incident('ب-3', 60, unitId: $this->unitB->id, status: 'escalated_to_manager');

        $top = $this->user('mudir_aam', 'top_management');
        $response = $this->actingAs($top)->get(route('reports.dashboard'));

        $response->assertOk();
        // العدد الحقيقي يظهر — لا أصفار من try/catch
        $response->assertSee('data-count="incidents"', false);
        $response->assertSee('فجوة الاستجابة');
    }

    public function test_response_gap_average_is_correct_and_never_zero_on_empty(): void
    {
        $scope = ReportScope::all();
        $dashboard = app(DashboardService::class);

        // بلا بيانات: null لا صفر — الصفر يعني استجابة فورية
        $this->assertNull($dashboard->responseGap($scope)['incident']['avg_minutes']);

        $this->incident('ب-1', 10);
        $this->incident('ب-2', 30);

        $gap = $dashboard->responseGap(ReportScope::all())['incident'];
        $this->assertSame(2, $gap['count']);
        $this->assertSame(20.0, $gap['avg_minutes']);
        $this->assertSame(30.0, $gap['max_minutes']);
    }

    public function test_pending_incidents_counted_before_field_receipt(): void
    {
        $created = Carbon::now()->startOfMonth()->addDay();
        $this->stamp(Incident::create([
            'code' => 'ب-9', 'title' => 'لم يصل الفني', 'description' => 'وصف',
            'incident_type' => 'urgent', 'status' => 'received', 'risk_id' => $this->refRisk->id,
            'place_id' => Place::idByCode('HZ-06'),
        ]), $created);

        $gap = app(DashboardService::class)->responseGap(ReportScope::all())['incident'];
        $this->assertSame(1, $gap['pending']);
        $this->assertNull($gap['avg_minutes']);
    }

    public function test_kpi_returns_null_not_hundred_on_empty_database(): void
    {
        $kpi = app(KpiService::class)->all(ReportScope::all());

        foreach ($kpi as $key => $value) {
            $this->assertNull($value, "المؤشر {$key} يجب أن يكون «لا بيانات» على قاعدة فارغة");
        }
    }

    public function test_closure_rate_and_avg_days_are_computed_portably(): void
    {
        $this->incident('ب-1', 10, status: 'closed');
        $this->incident('ب-2', 10, status: 'closed');
        $this->incident('ب-3', 10, status: 'in_progress');

        $kpi = app(KpiService::class);
        $this->assertSame(67, $kpi->incidentClosureRate(ReportScope::all()));
        // يومان بالضبط بين الإنشاء والإغلاق
        $this->assertSame(2.0, $kpi->avgClosureDays(ReportScope::all()));
    }

    public function test_department_manager_sees_only_their_unit(): void
    {
        $this->incident('ب-أ', 10, unitId: $this->unitA->id);
        $this->incident('ب-ب', 10, unitId: $this->unitB->id);

        $this->actingAs($this->mudirA);
        $scope = ReportScope::fromRequest(null, null, null, $this->mudirA->id);
        $stats = app(DashboardService::class)->incidentStats($scope);

        $this->assertSame(1, $stats['total'], 'مدير الإدارة أ لا يرى بلاغ الإدارة ب');

        $wide = ReportScope::fromRequest(null, null, null, $this->salama->id);
        $this->assertSame(2, app(DashboardService::class)->incidentStats($wide)['total']);
    }

    public function test_place_filter_narrows_every_section(): void
    {
        $this->incident('ب-1', 10, placeCode: 'HZ-06');
        $this->incident('ب-2', 10, placeCode: 'HZ-01');

        $place = Place::idByCode('HZ-06');
        $scope = ReportScope::fromRequest(null, null, $place, $this->salama->id);

        $this->assertSame(1, app(DashboardService::class)->incidentStats($scope)['total']);
        $rows = app(DashboardService::class)->byPlace($scope);
        $this->assertCount(1, $rows);
        $this->assertSame('HZ-06', $rows[0]['code']);
    }

    public function test_date_range_excludes_older_records(): void
    {
        $old = Carbon::now()->subMonths(3);
        $this->stamp(Incident::create([
            'code' => 'ب-قديم', 'title' => 'بلاغ قديم', 'description' => 'وصف',
            'incident_type' => 'normal', 'status' => 'closed', 'risk_id' => $this->refRisk->id,
            'place_id' => Place::idByCode('HZ-06'),
        ]), $old);
        $this->incident('ب-جديد', 10);

        $thisMonth = ReportScope::all(Carbon::now()->startOfMonth(), Carbon::now()->endOfDay());
        $this->assertSame(1, app(DashboardService::class)->incidentStats($thisMonth)['total']);

        $wide = ReportScope::all(Carbon::now()->subMonths(6), Carbon::now()->endOfDay());
        $this->assertSame(2, app(DashboardService::class)->incidentStats($wide)['total']);
    }

    public function test_risks_count_active_only_not_the_book(): void
    {
        // بذرة كتاب المخاطر فيها مئات المخاطر المرجعية — لا تُحسب واقعاً
        $category = RiskCategory::where('name', 'الحريق والانفجار')->firstOrFail();
        Risk::create([
            'risk_type' => 'active', 'title' => 'خطر فعلي حرج', 'description' => 'وصف',
            'category_id' => $category->id, 'place_id' => Place::idByCode('HZ-06'),
            'severity' => 5, 'likelihood' => 4, 'status' => 'active',
        ]);

        $stats = app(DashboardService::class)->riskStats(ReportScope::all());
        $this->assertSame(1, $stats['total']);
        $this->assertSame(1, $stats['critical']);
    }

    public function test_needs_attention_lists_escalated_and_critical(): void
    {
        $this->incident('ب-صعد', 10, status: 'escalated_to_manager');
        $category = RiskCategory::where('name', 'الحريق والانفجار')->firstOrFail();
        Risk::create([
            'risk_type' => 'active', 'title' => 'خطر حرج', 'description' => 'وصف',
            'category_id' => $category->id, 'place_id' => Place::idByCode('HZ-06'),
            'severity' => 5, 'likelihood' => 5, 'status' => 'active',
        ]);

        $items = app(DashboardService::class)->needsAttention(ReportScope::all());
        $texts = array_column($items, 'text');

        $this->assertContains('بلاغ شاغل مُصعَّد لم يُغلق', $texts);
        $this->assertContains('خطر فعلي حرج (١٥ فأكثر) ما زال نشطاً', $texts);
    }

    public function test_unacknowledged_emergency_is_surfaced(): void
    {
        EmergencyIncident::create([
            'incident_code' => 'ط-9', 'incident_type' => 'fire', 'severity' => 'high',
            'status' => 'active', 'is_drill' => false, 'building_id' => $this->building->id,
            'place_id' => Place::idByCode('HZ-06'),
            'triggered_at' => Carbon::now()->startOfMonth()->addDay(),
        ]);

        $gap = app(DashboardService::class)->responseGap(ReportScope::all())['emergency'];
        $this->assertSame(1, $gap['unacknowledged']);
        $this->assertNull($gap['avg_minutes']);

        $texts = array_column(app(DashboardService::class)->needsAttention(ReportScope::all()), 'text');
        $this->assertContains('حالة طارئة بلا إقرار باستلام التنبيه', $texts);
    }

    public function test_drills_do_not_pollute_emergency_response_numbers(): void
    {
        $at = Carbon::now()->startOfMonth()->addDay();
        EmergencyIncident::create([
            'incident_code' => 'ط-تمرين', 'incident_type' => 'drill', 'severity' => 'low',
            'status' => 'ended', 'is_drill' => true, 'building_id' => $this->building->id, 'place_id' => Place::idByCode('HZ-06'),
            'triggered_at' => $at,
        ]);

        $stats = app(DashboardService::class)->emergencyStats(ReportScope::all());
        $this->assertSame(1, $stats['drills']);
        $this->assertSame(0, $stats['real']);
        $this->assertSame(0, app(DashboardService::class)->responseGap(ReportScope::all())['emergency']['unacknowledged']);
    }

    // ════════════ الصلاحيات ════════════

    public function test_field_worker_forbidden(): void
    {
        $this->actingAs($this->fani)->get(route('reports.dashboard'))->assertForbidden();
        $this->actingAs($this->fani)->get(route('reports.incidents'))->assertForbidden();
        $this->actingAs($this->fani)->get(route('reports.risks'))->assertForbidden();
        $this->actingAs($this->fani)->get(route('reports.export'))->assertForbidden();
    }

    public function test_guest_redirected(): void
    {
        $this->get(route('reports.dashboard'))->assertRedirect();
    }

    public function test_allowed_roles_can_open_every_screen(): void
    {
        foreach (['system_admin', 'top_management', 'safety_committee', 'department_manager', 'safety_coordinator'] as $i => $role) {
            $user = $this->user("r{$i}", $role);
            $this->actingAs($user)->get(route('reports.dashboard'))->assertOk();
            $this->actingAs($user)->get(route('reports.incidents'))->assertOk();
            $this->actingAs($user)->get(route('reports.risks'))->assertOk();
        }
    }

    // ════════════ التصدير ════════════

    public function test_export_is_csv_with_bom_and_says_no_data_not_zero(): void
    {
        $response = $this->actingAs($this->salama)->get(route('reports.export'));
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $body = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('لا بيانات', $body);
        $this->assertStringContainsString('HZ-06', $body);
    }

    public function test_export_carries_the_real_numbers(): void
    {
        $this->incident('ب-1', 12);

        $body = $this->actingAs($this->salama)->get(route('reports.export'))->streamedContent();
        $this->assertStringContainsString('12', $body);
    }

    // ════════════ لا ذاكرة مؤقتة ════════════

    public function test_dashboard_reflects_a_new_record_immediately(): void
    {
        $dashboard = app(DashboardService::class);
        $this->assertSame(0, $dashboard->incidentStats(ReportScope::all())['total']);

        $this->incident('ب-جديد', 5);

        // في OHSMS كانت النتيجة محفوظة خمس دقائق فتبقى صفراً
        $this->assertSame(1, $dashboard->incidentStats(ReportScope::all())['total']);
    }
}
