<?php

namespace Tests\Feature\Worker;

use App\Models\User;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Worker\Models\Trade;
use App\Modules\Worker\Models\Worker;
use App\Modules\Worker\Models\WorkerDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;


/** منقول من اختبارات OHSMS بلا tenant (المرحلة ٦). ما حُذف: اختبارات عزل المستأجرين. */
class WorkerControllerTest extends TestCase
{
    use RefreshDatabase;


    protected function setUp(): void
    {
        parent::setUp();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        UserProfile::create(['user_id' => $user->id, 'role' => $role, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    private function makeTrade(): Trade
    {
        return Trade::factory()->create();
    }

    private function makeExternalParty(): ExternalParty
    {
        return ExternalParty::factory()->create();
    }

    private function makeWorker(int $userId, array $overrides = []): Worker
    {
        return Worker::factory()->create(array_merge([
            'created_by_id' => $userId,
            'status' => 'draft',
        ], $overrides));
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'محمد أحمد العلي',
            'national_id' => fake()->unique()->numerify('##########'),
            'phone' => '0501234567',
            'trade_id' => $this->makeTrade()->id,
            'external_party_id' => $this->makeExternalParty()->id,
            'medical_expiry' => now()->addMonths(6)->toDateString(),
            'joined_date' => now()->subMonths(1)->toDateString(),
        ], $overrides);
    }

    // ===========================================================
    // Auth & RBAC for index
    // ===========================================================

    public function test_unauthenticated_redirected_from_index(): void
    {
        $this->get(route('workers.index'))->assertRedirect();
    }

    public function test_field_worker_forbidden_from_index(): void
    {
        $this->actingAsRole('field_worker');
        $this->get(route('workers.index'))->assertForbidden();
    }

    public function test_safety_coordinator_can_view_index(): void
    {
        $this->actingAsRole('safety_coordinator');
        $this->get(route('workers.index'))->assertOk();
    }

    public function test_contractor_supervisor_can_view_index(): void
    {
        $this->actingAsRole('contractor_supervisor');
        $this->get(route('workers.index'))->assertOk();
    }

    // ===========================================================
    // RBAC for create / approval queue
    // ===========================================================

    public function test_safety_committee_forbidden_from_create(): void
    {
        // safety_committee is not in worker.create
        $this->actingAsRole('safety_committee');
        $this->get(route('workers.create'))->assertForbidden();
    }

    public function test_contractor_supervisor_can_create(): void
    {
        $this->actingAsRole('contractor_supervisor');
        $this->get(route('workers.create'))->assertOk();
    }

    public function test_contractor_supervisor_forbidden_from_approval_queue(): void
    {
        // approval queue requires worker.approve, contractor_supervisor lacks it
        $this->actingAsRole('contractor_supervisor');
        $this->get(route('workers.approval-queue'))->assertForbidden();
    }

    public function test_safety_coordinator_can_view_approval_queue(): void
    {
        $this->actingAsRole('safety_coordinator');
        $this->get(route('workers.approval-queue'))->assertOk();
    }

    // ===========================================================
    // Store / validation
    // ===========================================================

    public function test_store_requires_full_name_national_id_trade(): void
    {
        $this->actingAsRole('safety_coordinator');
        $this->post(route('workers.store'), [])
            ->assertSessionHasErrors(['full_name', 'national_id', 'trade_id']);
    }

    public function test_store_rejects_duplicate_national_id(): void
    {
        $coordinator = $this->actingAsRole('safety_coordinator');
        $existing = $this->makeWorker($coordinator->id, ['national_id' => '1234567890']);

        $this->post(route('workers.store'), $this->validPayload([
            'national_id' => '1234567890',
        ]))->assertSessionHasErrors('national_id');
    }

    public function test_store_creates_worker_in_draft_status(): void
    {
        $coordinator = $this->actingAsRole('safety_coordinator');
        $this->post(route('workers.store'), $this->validPayload([
            'national_id' => '9999999999',
            'full_name' => 'علي محمد',
        ]))->assertRedirect(); // المعهد: إلى صفحة العامل

        $worker = Worker::where('national_id', '9999999999')->first();
        $this->assertNotNull($worker);
        $this->assertSame('draft', $worker->status);
        $this->assertSame($coordinator->id, $worker->created_by_id);
    }

    // ===========================================================
    // Show / Edit / Update / Tenant isolation
    // ===========================================================

    public function test_show_renders_worker_detail(): void
    {
        $user = $this->actingAsRole('safety_coordinator');
        $worker = $this->makeWorker($user->id);

        $this->get(route('workers.show', $worker))->assertOk();
    }


    public function test_update_persists_changes(): void
    {
        $user = $this->actingAsRole('safety_coordinator');
        $worker = $this->makeWorker($user->id, ['full_name' => 'قديم']);

        $this->put(route('workers.update', $worker), $this->validPayload([
            'full_name' => 'محدّث',
            'national_id' => $worker->national_id, // keep same
            'trade_id' => $worker->trade_id ?? $this->makeTrade()->id,
        ]))->assertRedirect(route('workers.show', $worker));

        $this->assertSame('محدّث', $worker->fresh()->full_name);
    }

    // ===========================================================
    // Status transitions
    // ===========================================================

    public function test_transition_validates_allowed_status(): void
    {
        $user = $this->actingAsRole('safety_coordinator');
        $worker = $this->makeWorker($user->id, ['status' => 'draft']);

        // draft → approved is not allowed (must go through submitted/induction/training)
        $response = $this->post(route('workers.transition', $worker), [
            'status' => 'approved',
        ]);
        $response->assertSessionHas('error');
        $this->assertSame('draft', $worker->fresh()->status);
    }

    public function test_transition_advances_through_lifecycle(): void
    {
        $user = $this->actingAsRole('safety_coordinator');
        $worker = $this->makeWorker($user->id, ['status' => 'draft']);

        $this->post(route('workers.transition', $worker), ['status' => 'submitted']);
        $this->assertSame('submitted', $worker->fresh()->status);

        $this->post(route('workers.transition', $worker), ['status' => 'induction']);
        $this->post(route('workers.transition', $worker), ['status' => 'training']);
        $this->post(route('workers.transition', $worker), ['status' => 'approved']);
        $this->assertSame('approved', $worker->fresh()->status);

        // approved → work_authorized
        $this->post(route('workers.transition', $worker), ['status' => 'work_authorized']);
        $this->assertSame('work_authorized', $worker->fresh()->status);
    }

    public function test_block_requires_reason_note(): void
    {
        $user = $this->actingAsRole('safety_coordinator');
        $worker = $this->makeWorker($user->id, ['status' => 'approved']);

        // block without note → service throws
        $response = $this->post(route('workers.transition', $worker), [
            'status' => 'blocked',
        ]);
        $response->assertSessionHas('error');
        $this->assertSame('approved', $worker->fresh()->status);

        // block with note → succeeds
        $this->post(route('workers.transition', $worker), [
            'status' => 'blocked',
            'note' => 'لم يحضر التدريب الإلزامي',
        ]);
        $worker->refresh();
        $this->assertSame('blocked', $worker->status);
        $this->assertSame('لم يحضر التدريب الإلزامي', $worker->blocked_reason);
    }

    public function test_field_worker_forbidden_from_transition(): void
    {
        $coordinator = $this->actingAsRole('safety_coordinator');
        $worker = $this->makeWorker($coordinator->id);

        $this->actingAsRole('field_worker');
        $this->post(route('workers.transition', $worker), ['status' => 'submitted'])
            ->assertForbidden();
    }

    // ===========================================================
    // Documents
    // ===========================================================

    public function test_documents_view_renders(): void
    {
        $user = $this->actingAsRole('safety_coordinator');
        $worker = $this->makeWorker($user->id);

        $this->get(route('workers.documents', $worker))->assertOk();
    }

    public function test_add_document_creates_worker_document(): void
    {
        $user = $this->actingAsRole('safety_coordinator');
        $worker = $this->makeWorker($user->id);

        $this->post(route('workers.documents.store', $worker), [
            'name' => 'شهادة طبية',
            'document_type' => 'medical_certificate',
            'expiry_date' => now()->addYear()->toDateString(),
        ])->assertRedirect();

        $this->assertDatabaseHas('worker_documents', [
            'worker_id' => $worker->id,
            'name' => 'شهادة طبية',
            'document_type' => 'medical_certificate',
            'uploaded_by_id' => $user->id,
        ]);
    }

    public function test_add_document_requires_name_and_type(): void
    {
        $user = $this->actingAsRole('safety_coordinator');
        $worker = $this->makeWorker($user->id);

        $this->post(route('workers.documents.store', $worker), [])
            ->assertSessionHasErrors(['name', 'document_type']);
    }
}
