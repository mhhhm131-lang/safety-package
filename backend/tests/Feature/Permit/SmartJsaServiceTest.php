<?php

namespace Tests\Feature\Permit;

use App\Models\User;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Permit\Models\Permit;
use App\Modules\Permit\Models\PermitRequirement;
use App\Modules\Permit\Models\PermitType;
use App\Modules\Permit\Services\PermitService;
use App\Modules\Permit\Services\QualificationChecklistService;
use App\Modules\Permit\Services\RequiredPermitTypesService;
use App\Modules\Permit\Services\SmartJsaService;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskControl;
use App\Modules\Worker\Models\Trade;
use Database\Seeders\PermitTypesSeeder;
use Database\Seeders\PlacesSeeder;
use Database\Seeders\QualificationChecklistSeeder;
use Database\Seeders\RiskBookSeeder;
use Database\Seeders\RiskControlsSeeder;
use Database\Seeders\RiskRequiredPermitTypesSeeder;
use Database\Seeders\TradeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * منقول من OHSMS: `tests/Feature/Permit/SmartJsaServiceTest.php` (توليد البنود من بنود التحكم)
 * و`RiskDrivenRequirementsServiceTest` (أنواع التصاريح التي تستلزمها المخاطر).
 *
 * ما تغيّر: مصدر المخاطر هو **مخاطر المكان الفعّالة** لا «النشاط الاقتصادي» (غير منقول)؛
 * والمسار الأول (بنود تحكم نوع التصريح) هو الأساس كما في OHSMS.
 */
class SmartJsaServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;
    private SmartJsaService $jsa;
    private PermitService $permits;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            PlacesSeeder::class, RiskBookSeeder::class, RiskControlsSeeder::class, TradeSeeder::class,
            PermitTypesSeeder::class, QualificationChecklistSeeder::class, RiskRequiredPermitTypesSeeder::class,
        ]);

        $this->actor = User::create(['username' => 'salama', 'name' => 'مسؤول السلامة', 'password' => '123456']);
        UserProfile::create(['user_id' => $this->actor->id, 'role' => 'system_admin', 'is_active' => true]);

        $this->jsa     = app(SmartJsaService::class);
        $this->permits = app(PermitService::class);
    }

    private function permit(string $typeCode = 'hot_work', array $attrs = []): Permit
    {
        return $this->permits->create(PermitType::where('code', $typeCode)->firstOrFail(), array_merge([
            'title'           => "تصريح {$typeCode}",
            'place_id'        => Place::idByCode('HZ-02'),
            'requested_by_id' => $this->actor->id,
        ], $attrs));
    }

    public function test_generate_creates_requirements_from_permit_type_controls(): void
    {
        $permit = $this->permit('hot_work');

        // بنود «الأعمال الساخنة» مبذورة بـ permit_type_code=hot_work
        $expected = RiskControl::where('permit_type_code', 'hot_work')->count();
        $this->assertGreaterThan(0, $expected);
        $this->assertSame($expected, $permit->requirements()->whereNotNull('risk_control_id')->count());
    }

    public function test_generate_is_idempotent(): void
    {
        $permit = $this->permit('hot_work');
        $before = $permit->requirements()->count();

        $result = $this->jsa->generate($permit);

        $this->assertSame(0, $result['created']);
        $this->assertGreaterThan(0, $result['skipped']);
        $this->assertSame($before, $permit->requirements()->count());
    }

    public function test_mandatory_and_recommended_severity_flow_from_controls(): void
    {
        $permit = $this->permit('hot_work');

        $mandatoryControls = RiskControl::where('permit_type_code', 'hot_work')->where('is_mandatory', true)->count();
        $this->assertSame(
            $mandatoryControls,
            $permit->requirements()->where('severity', PermitRequirement::SEVERITY_MANDATORY)->count(),
        );
    }

    public function test_control_fields_flow_through_to_requirement(): void
    {
        $permit = $this->permit('confined_space');
        $control = RiskControl::where('permit_type_code', 'confined_space')
            ->whereNotNull('evidence_type')->firstOrFail();

        $req = $permit->requirements()->where('risk_control_id', $control->id)->firstOrFail();

        $this->assertSame($control->phase, $req->phase);
        $this->assertSame($control->evidence_type, $req->evidence_type);
        $this->assertSame($control->responsible_role, $req->responsible_role);
        $this->assertSame($control->description_ar, $req->description_ar);
    }

    public function test_preview_groups_by_phase_without_writing(): void
    {
        $permit = $this->permit('work_at_height');
        $permit->requirements()->delete();

        $preview = $this->jsa->preview($permit);

        $this->assertGreaterThan(0, $preview['total']);
        $this->assertSame($preview['total'],
            count($preview['preventive']) + count($preview['operational']) + count($preview['response']));
        $this->assertSame(0, $permit->requirements()->count(), 'المعاينة لا تكتب');
    }

    public function test_completion_stats_counts_mandatory_only(): void
    {
        $permit = $this->permit('hot_work');
        $mandatory = $permit->requirements()->where('severity', PermitRequirement::SEVERITY_MANDATORY)->get();
        $mandatory->take(2)->each(fn ($r) => $r->update(['status' => PermitRequirement::STATUS_COMPLETED]));

        $stats = $this->jsa->completionStats($permit);

        $this->assertSame($mandatory->count(), $stats['mandatory_total']);
        $this->assertSame(2, $stats['mandatory_done']);
    }

    public function test_grouped_requirements_orders_phases(): void
    {
        $permit = $this->permit('hot_work');
        $grouped = $this->jsa->groupedRequirements($permit);

        foreach (['preventive', 'operational', 'response', 'other'] as $key) {
            $this->assertArrayHasKey($key, $grouped);
        }
        $this->assertTrue($grouped['preventive']->isNotEmpty());
    }

    public function test_qualification_permits_use_the_checklist_catalog_not_controls(): void
    {
        $project = \App\Modules\Project\Models\Project::create([
            'name' => 'مشروع', 'status' => 'active', 'place_id' => Place::idByCode('HZ-06'),
        ]);
        $party = \App\Modules\Project\Models\ExternalParty::create([
            'name' => 'مقاول', 'party_type' => 'contractor', 'status' => 'active',
        ]);

        $permit = $this->permits->create(PermitType::where('code', 'contractor_pre_qualification')->firstOrFail(), [
            'title' => 'تأهيل', 'project_id' => $project->id, 'external_party_id' => $party->id,
            'requested_by_id' => $this->actor->id,
        ]);

        $this->assertSame(0, $permit->requirements()->whereNotNull('risk_control_id')->count());
        $this->assertGreaterThan(0,
            $permit->requirements()->where('category', PermitRequirement::CATEGORY_QUALIFICATION)->count());
    }

    public function test_qualification_checklist_is_idempotent(): void
    {
        $project = \App\Modules\Project\Models\Project::create([
            'name' => 'مشروع', 'status' => 'active', 'place_id' => Place::idByCode('HZ-06'),
        ]);
        $party = \App\Modules\Project\Models\ExternalParty::create([
            'name' => 'مقاول', 'party_type' => 'contractor', 'status' => 'active',
        ]);
        $permit = $this->permits->create(PermitType::where('code', 'contractor_pre_qualification')->firstOrFail(), [
            'title' => 'تأهيل', 'project_id' => $project->id, 'external_party_id' => $party->id,
            'requested_by_id' => $this->actor->id,
        ]);

        $before = $permit->requirements()->count();
        app(QualificationChecklistService::class)->generate($permit);

        $this->assertSame($before, $permit->requirements()->count());
    }

    public function test_worker_category_permits_skip_control_generation(): void
    {
        $party = \App\Modules\Project\Models\ExternalParty::create([
            'name' => 'مقاول', 'party_type' => 'contractor', 'status' => 'active',
        ]);
        $worker = \App\Modules\Worker\Models\Worker::create([
            'external_party_id' => $party->id, 'full_name' => 'عامل', 'national_id' => '4000000001',
            'trade_id' => Trade::value('id'), 'status' => 'work_authorized',
        ]);

        $permit = $this->permits->create(PermitType::where('code', 'worker_site_access')->firstOrFail(), [
            'title' => 'دخول', 'subject_type' => Permit::SUBJECT_WORKER, 'subject_id' => $worker->id,
            'place_id' => Place::idByCode('HZ-06'), 'requested_by_id' => $this->actor->id,
        ]);

        $this->assertSame(0, $permit->requirements()->count(), 'أهلية الفرد فحص شخصي لا بنود تحكم');
    }

    public function test_required_permit_types_for_trades_respects_condition(): void
    {
        $trade = Trade::where('is_active', true)->firstOrFail();
        $hotWork = PermitType::where('code', 'hot_work')->firstOrFail();

        \Illuminate\Support\Facades\DB::table('permit_type_trades')->insert([
            'permit_type_id' => $hotWork->id, 'trade_id' => $trade->id,
            'is_mandatory' => true, 'triggering_condition' => 'always',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->jsa->requiredPermitTypesForTrades([$trade->id], riskScore: 0);

        $this->assertCount(1, $result);
        $this->assertSame('hot_work', $result->first()['code']);
    }

    // ── أنواع التصاريح التي تستلزمها المخاطر (بدل مسار النشاط الاقتصادي في OHSMS) ──

    public function test_place_risks_suggest_required_permit_types(): void
    {
        $fire = RiskCategory::where('name', 'الحريق والانفجار')->firstOrFail();
        $placeId = Place::idByCode('HZ-02');

        Risk::create([
            'risk_type' => 'active', 'title' => 'شرر قرب مواد قابلة للاشتعال',
            'category_id' => $fire->id, 'place_id' => $placeId,
            'severity' => 4, 'likelihood' => 3, 'status' => 'active',
        ]);

        $types = app(RequiredPermitTypesService::class)->forPlace($placeId);
        $codes = $types->map(fn ($r) => $r['type']->code)->all();

        $this->assertContains('hot_work', $codes, 'فئة الحريق تستلزم تصريح أعمال ساخنة دائماً');
    }

    public function test_severity_condition_filters_suggested_types(): void
    {
        $mech = RiskCategory::where('name', 'الميكانيكية والإنشائية')->firstOrFail();
        $placeId = Place::idByCode('HZ-08');

        $risk = Risk::create([
            'risk_type' => 'active', 'title' => 'أجزاء متحركة', 'category_id' => $mech->id,
            'place_id' => $placeId, 'severity' => 2, 'likelihood' => 2, 'status' => 'active',
        ]);

        $codes = fn () => app(RequiredPermitTypesService::class)->forPlace($placeId)
            ->map(fn ($r) => $r['type']->code)->all();

        $this->assertNotContains('loto', $codes(), 'شدة ٢ لا تستوجب عزل الطاقة (الشرط ≥ ٣)');
        $this->assertContains('work_permit', $codes(), 'وتصريح العمل العام يلزم دائماً');

        $risk->update(['severity' => 4]);
        $this->assertContains('loto', $codes(), 'شدة ٤ تستوجب عزل الطاقة');
    }

    public function test_no_suggestions_when_place_has_no_active_risks(): void
    {
        $this->assertTrue(
            app(RequiredPermitTypesService::class)->forPlace(Place::idByCode('HZ-07'))->isEmpty(),
        );
    }
}
