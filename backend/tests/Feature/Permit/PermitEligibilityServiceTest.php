<?php

namespace Tests\Feature\Permit;

use App\Models\User;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Permit\Models\Permit;
use App\Modules\Permit\Models\PermitRequirement;
use App\Modules\Permit\Models\PermitType;
use App\Modules\Permit\Services\PermitEligibilityService;
use App\Modules\Permit\Services\PermitService;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Project\Models\Project;
use App\Modules\Project\Models\ProjectContractor;
use App\Modules\Worker\Models\Worker;
use Database\Seeders\PermitConflictRulesSeeder;
use Database\Seeders\PermitTypesSeeder;
use Database\Seeders\PlacesSeeder;
use Database\Seeders\QualificationChecklistSeeder;
use Database\Seeders\RiskBookSeeder;
use Database\Seeders\RiskControlsSeeder;
use Database\Seeders\TradeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * منقول من OHSMS: `tests/Feature/Permit/PermitEligibilityServiceTest.php`.
 * ما تغيّر: منطقة العمل صارت المكان؛ فحص العامل صار من WorkerGapRiskService (مصدر واحد للثغرات)؛
 * وأُسقطت فحوص المستأجرين.
 */
class PermitEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;
    private PermitEligibilityService $eligibility;
    private PermitService $permits;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            PlacesSeeder::class, RiskBookSeeder::class, RiskControlsSeeder::class, TradeSeeder::class,
            PermitTypesSeeder::class, PermitConflictRulesSeeder::class, QualificationChecklistSeeder::class,
        ]);

        $this->actor = User::create(['username' => 'salama', 'name' => 'مسؤول السلامة', 'password' => '123456']);
        UserProfile::create(['user_id' => $this->actor->id, 'role' => 'system_admin', 'is_active' => true]);

        $this->eligibility = app(PermitEligibilityService::class);
        $this->permits     = app(PermitService::class);
    }

    private function scope(): array
    {
        return [
            Project::create(['name' => 'مشروع', 'status' => 'active', 'place_id' => Place::idByCode('HZ-06')]),
            ExternalParty::create(['name' => 'مقاول', 'party_type' => 'contractor', 'status' => 'active']),
        ];
    }

    public function test_can_issue_blocks_when_required_context_missing(): void
    {
        $result = $this->eligibility->canIssue('hot_work', []);

        $this->assertFalse($result->eligible);
        $this->assertNotEmpty($result->blockers);
        $this->assertTrue(collect($result->blockers)->contains(fn ($b) => str_contains($b, 'المكان')));
    }

    public function test_can_issue_blocks_work_permit_when_contractor_not_post_approved(): void
    {
        [$project, $party] = $this->scope();
        ProjectContractor::create([
            'project_id' => $project->id, 'external_party_id' => $party->id,
            'role' => 'main', 'qualification_status' => 'draft',
        ]);

        $result = $this->eligibility->canIssue('work_permit', [
            'project_id' => $project->id, 'external_party_id' => $party->id,
            'place_id' => Place::idByCode('HZ-06'),
        ]);

        $this->assertFalse($result->eligible);
        $this->assertTrue(collect($result->blockers)->contains(fn ($b) => str_contains($b, 'تأهيل ما بعد التعاقد')));
    }

    public function test_can_issue_allows_work_permit_once_contractor_is_post_approved(): void
    {
        [$project, $party] = $this->scope();
        ProjectContractor::create([
            'project_id' => $project->id, 'external_party_id' => $party->id,
            'role' => 'main', 'qualification_status' => 'post_approved',
        ]);

        $result = $this->eligibility->canIssue('work_permit', [
            'project_id' => $project->id, 'external_party_id' => $party->id,
            'place_id' => Place::idByCode('HZ-06'),
        ]);

        $this->assertTrue($result->eligible, $result->blockers ? implode(' | ', $result->blockers) : '');
    }

    public function test_can_issue_allows_qualification_even_without_approved_contractor(): void
    {
        [$project, $party] = $this->scope();
        ProjectContractor::create([
            'project_id' => $project->id, 'external_party_id' => $party->id,
            'role' => 'main', 'qualification_status' => 'draft',
        ]);

        $result = $this->eligibility->canIssue('contractor_pre_qualification', [
            'project_id' => $project->id, 'external_party_id' => $party->id,
        ]);

        $this->assertTrue($result->eligible, 'تصريح التأهيل هو وسيلة الاعتماد نفسها');
    }

    public function test_can_issue_blocks_when_contractor_is_blocked(): void
    {
        [$project, $party] = $this->scope();
        $party->update(['status' => 'blocked']);

        $result = $this->eligibility->canIssue('contractor_pre_qualification', [
            'project_id' => $project->id, 'external_party_id' => $party->id,
        ]);

        $this->assertFalse($result->eligible);
        $this->assertTrue(collect($result->blockers)->contains(fn ($b) => str_contains($b, 'محظور')));
    }

    public function test_can_issue_blocks_when_place_capacity_exceeded(): void
    {
        $place = Place::where('code', 'HZ-06')->first();
        $place->update(['max_workers' => 3]);

        $result = $this->eligibility->canIssue('work_permit', [
            'place_id' => $place->id, 'workers_count' => 9,
        ]);

        $this->assertFalse($result->eligible);
        $this->assertTrue(collect($result->blockers)->contains(fn ($b) => str_contains($b, 'حد العمال')));
    }

    public function test_can_issue_warns_when_duplicate_active_permit_exists(): void
    {
        $placeId = Place::idByCode('HZ-02');
        $existing = $this->permits->create(PermitType::where('code', 'hot_work')->firstOrFail(), [
            'title' => 'أعمال ساخنة قائمة', 'place_id' => $placeId, 'requested_by_id' => $this->actor->id,
        ]);
        $existing->update(['status' => Permit::STATUS_ACTIVE]);

        $result = $this->eligibility->canIssue('hot_work', ['place_id' => $placeId]);

        $this->assertTrue(collect($result->warnings)->contains(fn ($w) => str_contains($w, 'تصريح نشط')));
    }

    public function test_blockers_lists_unmet_mandatory_requirements(): void
    {
        $permit = $this->permits->create(PermitType::where('code', 'work_permit')->firstOrFail(), [
            'title' => 'عمل عام', 'place_id' => Place::idByCode('HZ-06'), 'requested_by_id' => $this->actor->id,
        ]);
        $permit->requirements()->delete();
        $this->permits->addRequirement($permit, 'document', 'document:insurance_liability');

        $blockers = $this->eligibility->blockers($permit->refresh());

        $this->assertCount(1, $blockers);
        $this->assertStringContainsString('وثيقة التأمين', $blockers[0]);
    }

    public function test_blockers_ignores_waived_requirements(): void
    {
        $permit = $this->permits->create(PermitType::where('code', 'work_permit')->firstOrFail(), [
            'title' => 'عمل عام', 'place_id' => Place::idByCode('HZ-06'), 'requested_by_id' => $this->actor->id,
        ]);
        $permit->requirements()->update(['status' => PermitRequirement::STATUS_WAIVED]);

        $this->assertEmpty($this->eligibility->blockers($permit->refresh()));
    }

    public function test_blockers_flags_expired_permit(): void
    {
        $permit = $this->permits->create(PermitType::where('code', 'work_permit')->firstOrFail(), [
            'title' => 'عمل منتهٍ', 'place_id' => Place::idByCode('HZ-06'),
            'expires_at' => now()->subDay(), 'requested_by_id' => $this->actor->id,
        ]);
        $permit->requirements()->update(['status' => PermitRequirement::STATUS_COMPLETED]);

        $blockers = $this->eligibility->blockers($permit->refresh());

        $this->assertTrue(collect($blockers)->contains(fn ($b) => str_contains($b, 'تاريخ الانتهاء')));
    }

    public function test_blockers_includes_conflict_in_same_place(): void
    {
        $placeId = Place::idByCode('HZ-02');

        $hazmat = $this->permits->create(PermitType::where('code', 'hazmat_transport')->firstOrFail(), [
            'title' => 'نقل مواد', 'place_id' => $placeId,
            'starts_at' => now()->subHour(), 'expires_at' => now()->addHours(6),
            'requested_by_id' => $this->actor->id,
        ]);
        $hazmat->update(['status' => Permit::STATUS_ACTIVE]);

        $hotWork = $this->permits->create(PermitType::where('code', 'hot_work')->firstOrFail(), [
            'title' => 'لحام', 'place_id' => $placeId,
            'starts_at' => now()->subHour(), 'expires_at' => now()->addHours(6),
            'requested_by_id' => $this->actor->id,
        ]);
        $hotWork->requirements()->update(['status' => PermitRequirement::STATUS_COMPLETED]);

        $blockers = $this->eligibility->blockers($hotWork->refresh());

        $this->assertTrue(collect($blockers)->contains(fn ($b) => str_contains($b, 'تعارض مع التصريح')));
    }

    public function test_blockers_reports_high_severity_worker_gaps(): void
    {
        $party = ExternalParty::create(['name' => 'مقاول', 'party_type' => 'contractor', 'status' => 'active']);
        $worker = Worker::create([
            'external_party_id' => $party->id, 'full_name' => 'عامل بلا فحص طبي',
            'national_id' => '3000000001', 'trade_id' => \App\Modules\Worker\Models\Trade::value('id'),
            'status' => 'work_authorized',
        ]);

        $permit = $this->permits->create(PermitType::where('code', 'work_permit')->firstOrFail(), [
            'title' => 'عمل بعامل', 'place_id' => Place::idByCode('HZ-06'),
            'external_party_id' => $party->id, 'requested_by_id' => $this->actor->id,
        ]);
        $permit->requirements()->update(['status' => PermitRequirement::STATUS_COMPLETED]);
        $permit->workers()->create(['worker_id' => $worker->id, 'qualification_status' => 'not_qualified']);

        $blockers = $this->eligibility->blockers($permit->refresh());

        $this->assertTrue(collect($blockers)->contains(fn ($b) => str_contains($b, 'الفحص الطبي غير مسجَّل')));
    }
}
