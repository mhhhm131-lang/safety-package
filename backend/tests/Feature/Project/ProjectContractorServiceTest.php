<?php

namespace Tests\Feature\Project;

use App\Core\StateMachine\Exceptions\TransitionException;
use App\Models\User;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Project\Models\Project;
use App\Modules\Project\Models\ProjectContractor;
use App\Modules\Project\Models\ProjectContractorEvent;
use App\Modules\Project\Services\ProjectContractorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Day 1 coverage: project ↔ contractor pivot, state machine, audit log.
 * Focus is the service layer since every write should go through it.
 */

/** منقول من اختبارات OHSMS بلا tenant (المرحلة ٦). ما حُذف: اختبارات عزل المستأجرين. */
class ProjectContractorServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;
    private ProjectContractorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create();
        UserProfile::create(['user_id' => $this->actor->id, 'role' => 'system_admin', 'is_active' => true]);
        $this->service = app(ProjectContractorService::class);
    }

    public function test_attach_creates_pivot_row_in_draft_state(): void
    {
        [$project, $party] = $this->makeProjectAndParty();

        $pc = $this->service->attach($project, $party, ProjectContractor::ROLE_MAIN, $this->actor->id);

        $this->assertEquals(ProjectContractor::STATUS_DRAFT, $pc->qualification_status);
        $this->assertEquals($project->id, $pc->project_id);
        $this->assertEquals($party->id, $pc->external_party_id);
        $this->assertEquals(ProjectContractor::ROLE_MAIN, $pc->role);
    }

    public function test_attach_is_idempotent_for_same_project_and_party(): void
    {
        [$project, $party] = $this->makeProjectAndParty();

        $first = $this->service->attach($project, $party);
        $second = $this->service->attach($project, $party);

        $this->assertEquals($first->id, $second->id);
        $this->assertDatabaseCount('project_contractors', 1);
    }


    public function test_happy_path_draft_to_post_approved_writes_timestamps(): void
    {
        $pc = $this->makeAttached();

        $this->service->transition($pc, ProjectContractor::STATUS_PRE_REVIEW, $this->actor->id);
        $this->service->transition($pc, ProjectContractor::STATUS_PRE_APPROVED, $this->actor->id);
        $this->service->transition($pc, ProjectContractor::STATUS_POST_REVIEW, $this->actor->id);
        $pc = $this->service->transition($pc, ProjectContractor::STATUS_POST_APPROVED, $this->actor->id);

        $this->assertEquals(ProjectContractor::STATUS_POST_APPROVED, $pc->qualification_status);
        $this->assertNotNull($pc->pre_approved_at);
        $this->assertNotNull($pc->post_approved_at);
        $this->assertTrue($pc->isWorkReady());
    }

    public function test_illegal_transition_is_blocked_by_state_machine(): void
    {
        $pc = $this->makeAttached();

        $this->expectException(TransitionException::class);
        $this->service->transition($pc, ProjectContractor::STATUS_POST_APPROVED);
    }

    public function test_suspended_can_be_reached_from_any_state(): void
    {
        $pc = $this->makeAttached();
        $this->service->transition($pc, ProjectContractor::STATUS_PRE_REVIEW);

        $pc = $this->service->transition($pc, ProjectContractor::STATUS_SUSPENDED, $this->actor->id, 'CR expired');

        $this->assertEquals(ProjectContractor::STATUS_SUSPENDED, $pc->qualification_status);
    }

    public function test_every_transition_writes_an_event(): void
    {
        $pc = $this->makeAttached();

        $this->service->transition($pc, ProjectContractor::STATUS_PRE_REVIEW, $this->actor->id);
        $this->service->transition($pc, ProjectContractor::STATUS_PRE_APPROVED, $this->actor->id, 'Docs verified');

        $events = ProjectContractorEvent::where('project_contractor_id', $pc->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $events, 'created + 2 status_changed');
        $this->assertEquals('created', $events[0]->event_type);
        $this->assertEquals('status_changed', $events[1]->event_type);
        $this->assertEquals(ProjectContractor::STATUS_DRAFT, $events[1]->from_status);
        $this->assertEquals(ProjectContractor::STATUS_PRE_REVIEW, $events[1]->to_status);
        $this->assertEquals('Docs verified', $events[2]->notes);
        $this->assertEquals($this->actor->id, $events[2]->performed_by_id);
    }

    public function test_project_contractors_relationships_resolve(): void
    {
        $pc = $this->makeAttached();
        $project = $pc->project;
        $party = $pc->externalParty;

        $this->assertCount(1, $project->contractors);
        $this->assertCount(1, $project->projectContractors);
        $this->assertCount(1, $party->projectAssignments);
        $this->assertCount(1, $party->projects);
    }

    public function test_suspend_all_for_party_cascades_across_projects(): void
    {
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();
        $party = ExternalParty::factory()->create();

        $pcA = $this->service->attach($projectA, $party);
        $pcB = $this->service->attach($projectB, $party);
        // Move one to post_approved so we verify the cascade hits an
        // already-active assignment too, not just drafts.
        $this->service->transition($pcA, ProjectContractor::STATUS_PRE_REVIEW);
        $this->service->transition($pcA, ProjectContractor::STATUS_PRE_APPROVED);
        $this->service->transition($pcA, ProjectContractor::STATUS_POST_REVIEW);
        $this->service->transition($pcA, ProjectContractor::STATUS_POST_APPROVED);

        $suspended = $this->service->suspendAllForParty($party, $this->actor->id, 'CR revoked');

        $this->assertEquals(2, $suspended);
        $this->assertEquals(ProjectContractor::STATUS_SUSPENDED, $pcA->refresh()->qualification_status);
        $this->assertEquals(ProjectContractor::STATUS_SUSPENDED, $pcB->refresh()->qualification_status);
    }

    // ----------------- helpers -----------------

    /** @return array{Project, ExternalParty} */
    private function makeProjectAndParty(): array
    {
        return [
            Project::factory()->create(),
            ExternalParty::factory()->create(),
        ];
    }

    private function makeAttached(): ProjectContractor
    {
        [$project, $party] = $this->makeProjectAndParty();
        return $this->service->attach($project, $party, ProjectContractor::ROLE_MAIN, $this->actor->id);
    }
}
