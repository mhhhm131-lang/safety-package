<?php

namespace Tests\Feature\Worker;

use App\Core\Services\NotificationService;
use App\Models\User;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Worker\Models\Trade;
use App\Modules\Worker\Models\Worker;
use App\Modules\Worker\Services\WorkerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;


/** منقول من اختبارات OHSMS بلا tenant (المرحلة ٦). ما حُذف: اختبارات عزل المستأجرين. */
class WorkerServiceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private WorkerService $service;
    private User $user;
    private ExternalParty $party;
    private Trade $trade;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WorkerService(new NotificationService());
        $this->user = User::factory()->create();
        $this->party = ExternalParty::factory()->create();
        $this->trade = Trade::factory()->create();
    }

    private function makeWorker(array $overrides = []): Worker
    {
        return $this->service->create($this->user->id, array_merge([
            'external_party_id' => $this->party->id,
            'full_name' => 'محمد عبدالله',
            'national_id' => '1234567890',
            'trade_id' => $this->trade->id,
        ], $overrides));
    }

    public function test_create_starts_in_draft(): void
    {
        $worker = $this->makeWorker();

        $this->assertSame('draft', $worker->status);
        $this->assertSame($this->user->id, $worker->created_by_id);
    }

    public function test_full_happy_path_to_work_authorized(): void
    {
        $worker = $this->makeWorker();

        $w = $this->service->transition($worker, $this->user->id, 'submitted');
        $w = $this->service->transition($w, $this->user->id, 'induction');
        $w = $this->service->transition($w, $this->user->id, 'training');
        $w = $this->service->transition($w, $this->user->id, 'approved');
        $w = $this->service->transition($w, $this->user->id, 'work_authorized');

        $this->assertSame('work_authorized', $w->status);
        $this->assertTrue($w->is_authorized);
        $this->assertFalse($w->is_blocked);

        // Verify each transition recorded an event
        $this->assertSame(5, $w->statusEvents()->count());
    }

    public function test_invalid_transition_throws(): void
    {
        $worker = $this->makeWorker();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Transition from 'draft' to 'approved' is not allowed.");
        $this->service->transition($worker, $this->user->id, 'approved');
    }

    public function test_block_requires_reason(): void
    {
        $worker = $this->makeWorker();
        $w = $this->service->transition($worker, $this->user->id, 'submitted');
        $w = $this->service->transition($w, $this->user->id, 'induction');
        $w = $this->service->transition($w, $this->user->id, 'training');
        $w = $this->service->transition($w, $this->user->id, 'approved');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('blocked_reason');
        $this->service->transition($w, $this->user->id, 'blocked');
    }

    public function test_block_with_reason_persists_reason(): void
    {
        $worker = $this->makeWorker();
        $w = $this->service->transition($worker, $this->user->id, 'submitted');
        $w = $this->service->transition($w, $this->user->id, 'induction');
        $w = $this->service->transition($w, $this->user->id, 'training');
        $w = $this->service->transition($w, $this->user->id, 'approved');
        $w = $this->service->transition($w, $this->user->id, 'blocked', 'مخالفة سلامة');

        $this->assertSame('blocked', $w->status);
        $this->assertSame('مخالفة سلامة', $w->blocked_reason);
        $this->assertTrue($w->is_blocked);
    }

    public function test_blocked_can_be_reapproved(): void
    {
        $worker = $this->makeWorker();
        $w = $this->service->transition($worker, $this->user->id, 'submitted');
        $w = $this->service->transition($w, $this->user->id, 'induction');
        $w = $this->service->transition($w, $this->user->id, 'training');
        $w = $this->service->transition($w, $this->user->id, 'approved');
        $w = $this->service->transition($w, $this->user->id, 'blocked', 'سبب');

        $reapproved = $this->service->transition($w, $this->user->id, 'approved');
        $this->assertSame('approved', $reapproved->status);
    }

    public function test_role_authorized_can_only_be_blocked_or_suspended(): void
    {
        $worker = $this->makeWorker();
        $w = $this->service->transition($worker, $this->user->id, 'submitted');
        $w = $this->service->transition($w, $this->user->id, 'induction');
        $w = $this->service->transition($w, $this->user->id, 'training');
        $w = $this->service->transition($w, $this->user->id, 'approved');
        $w = $this->service->transition($w, $this->user->id, 'work_authorized');
        $w = $this->service->transition($w, $this->user->id, 'role_authorized');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->transition($w, $this->user->id, 'work_authorized');
    }

    public function test_approval_queue_returns_only_submitted(): void
    {
        $a = $this->makeWorker(['national_id' => '111']);
        $this->service->transition($a, $this->user->id, 'submitted');

        $b = $this->makeWorker(['national_id' => '222']);
        $this->service->transition($b, $this->user->id, 'submitted');

        $this->makeWorker(['national_id' => '333']); // remains draft

        $queue = $this->service->getApprovalQueue();
        $this->assertCount(2, $queue);
    }

    public function test_pin_set_and_check(): void
    {
        $worker = $this->makeWorker();
        $worker->setPin('1234');
        $worker->save();

        $this->assertTrue($worker->fresh()->checkPin('1234'));
        $this->assertFalse($worker->fresh()->checkPin('0000'));
    }
}
