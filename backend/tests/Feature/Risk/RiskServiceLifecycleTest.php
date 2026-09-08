<?php

namespace Tests\Feature\Risk;

use App\Core\StateMachine\Exceptions\TransitionException;
use App\Models\User;
use App\Modules\Risk\Models\RiskEvent;
use App\Modules\Risk\Models\RiskPhase;
use App\Modules\Risk\Services\RiskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * دورة حياة الخطر عبر الخدمة وآلة الحالة — منقول من OHSMS بلا tenant.
 * التوقيع في المعهد: createRisk(?int $userId, array $data, string $riskType).
 */
class RiskServiceLifecycleTest extends TestCase
{
    use RefreshDatabase, RiskFixtures;

    private RiskService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RiskService::class);
        $this->user = $this->makeUser('system_admin');
    }

    public function test_create_risk_persists_with_calculated_score(): void
    {
        $risk = $this->service->createRisk(
            $this->user->id,
            ['title' => 'سقوط من ارتفاع', 'severity' => 4, 'likelihood' => 3]
        );

        $this->assertSame('draft', $risk->status);
        $this->assertSame(12, $risk->risk_score);
        $this->assertDatabaseHas('risk_events', [
            'risk_id' => $risk->id,
            'action' => 'created',
            'to_status' => 'draft',
        ]);
    }

    public function test_submit_for_approval_changes_status(): void
    {
        $risk = $this->service->createRisk(
            $this->user->id,
            ['title' => 'مخاطر كهربائية', 'severity' => 5, 'likelihood' => 4]
        );

        $updated = $this->service->submitForApproval($risk, $this->user->id);

        $this->assertSame('pending_approval', $updated->status);
        $this->assertDatabaseHas('risk_events', [
            'risk_id' => $risk->id,
            'action' => 'submitted',
            'from_status' => 'draft',
            'to_status' => 'pending_approval',
        ]);
    }

    public function test_approve_records_actor_and_timestamp(): void
    {
        $risk = $this->service->createRisk(
            $this->user->id,
            ['title' => 'انسكاب مواد', 'severity' => 3, 'likelihood' => 3]
        );
        $this->service->submitForApproval($risk, $this->user->id);

        $approved = $this->service->approve($risk->fresh(), $this->user->id, 'موافق عليه');

        $this->assertSame('approved', $approved->status);
        $this->assertSame($this->user->id, $approved->approved_by_id);
        $this->assertNotNull($approved->approved_at);
        $this->assertSame('موافق عليه', $approved->approval_notes);
    }

    public function test_reject_persists_notes(): void
    {
        $risk = $this->service->createRisk(
            $this->user->id,
            ['title' => 'خطر مهمل', 'severity' => 2, 'likelihood' => 1]
        );
        $this->service->submitForApproval($risk, $this->user->id);

        $rejected = $this->service->reject($risk->fresh(), $this->user->id, 'البيانات ناقصة');

        $this->assertSame('rejected', $rejected->status);
        $this->assertSame('البيانات ناقصة', $rejected->approval_notes);
    }

    public function test_full_lifecycle_to_active(): void
    {
        $risk = $this->service->createRisk(
            $this->user->id,
            ['title' => 'مخاطر اللحام', 'severity' => 4, 'likelihood' => 4]
        );

        $this->service->submitForApproval($risk, $this->user->id);
        $this->service->approve($risk->fresh(), $this->user->id);
        $activated = $this->service->activate($risk->fresh(), $this->user->id);

        $this->assertSame('active', $activated->status);
        $this->assertSame(4, RiskEvent::where('risk_id', $risk->id)->count());
    }

    public function test_cannot_approve_draft_risk(): void
    {
        $risk = $this->service->createRisk(
            $this->user->id,
            ['title' => 'خطر جديد', 'severity' => 2, 'likelihood' => 2]
        );

        $this->expectException(TransitionException::class);
        $this->service->approve($risk, $this->user->id);
    }

    public function test_cannot_activate_unapproved_risk(): void
    {
        $risk = $this->service->createRisk(
            $this->user->id,
            ['title' => 'خطر آخر', 'severity' => 1, 'likelihood' => 1]
        );

        $this->expectException(TransitionException::class);
        $this->service->activate($risk, $this->user->id);
    }

    public function test_rejected_risk_can_return_to_draft(): void
    {
        $risk = $this->service->createRisk(
            $this->user->id,
            ['title' => 'خطر معاد', 'severity' => 3, 'likelihood' => 2]
        );
        $this->service->submitForApproval($risk, $this->user->id);
        $this->service->reject($risk->fresh(), $this->user->id);

        $back = $this->service->changeStatus($risk->fresh(), $this->user->id, 'draft');

        $this->assertSame('draft', $back->status);
    }

    // ─── Step 3: Auto-phase creation ────────────────────────────────

    public function test_create_risk_auto_creates_three_phases(): void
    {
        $risk = $this->service->createRisk(
            $this->user->id,
            ['title' => 'خطر بثلاث مراحل', 'severity' => 3, 'likelihood' => 3]
        );

        $phases = $risk->fresh()->phases->pluck('phase')->sort()->values()->all();

        $this->assertSame(
            ['operational', 'proactive', 'response'],
            $phases,
        );
    }

    public function test_ensure_phases_is_idempotent(): void
    {
        $risk = $this->service->createRisk(
            $this->user->id,
            ['title' => 'idempotent check', 'severity' => 1, 'likelihood' => 1]
        );

        // Run ensurePhases again — it must NOT create duplicate rows.
        $this->service->ensurePhases($risk);
        $this->service->ensurePhases($risk);

        $this->assertSame(3, RiskPhase::where('risk_id', $risk->id)->count());
    }

    public function test_activate_from_reference_creates_phases_on_active_risk(): void
    {
        // Build a reference risk first.
        $reference = $this->service->createRisk(
            $this->user->id,
            ['title' => 'خطر مرجعي', 'severity' => 4, 'likelihood' => 2],
            'reference',
        );
        // Reference has its own 3 phases — clear them to simulate a bare reference.
        RiskPhase::where('risk_id', $reference->id)->delete();

        $active = $this->service->activateFromReference(
            $reference,
            $this->user->id,
            ['severity' => 4, 'likelihood' => 2]
        );

        $this->assertSame('active', $active->risk_type);
        $this->assertSame('active', $active->status);
        $this->assertSame(3, RiskPhase::where('risk_id', $active->id)->count());
    }
}
