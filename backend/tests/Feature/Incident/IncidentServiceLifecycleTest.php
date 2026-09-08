<?php

namespace Tests\Feature\Incident;

use App\Core\StateMachine\Exceptions\TransitionException;
use App\Models\User;
use App\Modules\Incident\Models\Incident;
use App\Modules\Incident\Services\IncidentClosureService;
use App\Modules\Incident\Services\IncidentService;
use App\Modules\Incident\Services\IncidentVisibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * منقول من OHSMS بلا tenant. الإغلاق في خدمة IncidentClosureService، والمرفقات IncidentAttachment (base64)،
 * وassign حلّت محلها referToField، والتوجيه الآلي يتوقف عند «وصل المركز» بلا فني معروف.
 */
class IncidentServiceLifecycleTest extends TestCase
{
    use RefreshDatabase, IncidentFixtures;

    private IncidentService $service;
    private IncidentClosureService $closure;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(IncidentService::class);
        $this->closure = app(IncidentClosureService::class);
        $this->user = $this->makeUser('employee');
    }

    /**
     * مسؤول سلامة + منسق + فني، وخطر يعيّن المنسق والفني. يعيد الفاعلين الذين تحتاجهم اختبارات الدورة.
     */
    private function buildActorsAndRisk(): array
    {
        $admin = $this->makeUser('system_admin');
        $coord = $this->makeUser('safety_coordinator');
        $field = $this->makeUser('field_worker');

        $risk = $this->makeRisk([
            'assigned_coordinator_id' => $coord->id,
            'assigned_field_team_id' => $field->id,
        ]);

        return compact('admin', 'coord', 'field', 'risk');
    }

    private function addEvidence(Incident $incident, int $userId): void
    {
        $this->service->addAttachment($incident, $userId, 'evidence', 'image/png', base64_decode(self::$png), 'evidence.png');
    }

    public function test_create_normal_incident_auto_routes_to_field_received(): void
    {
        ['risk' => $risk] = $this->buildActorsAndRisk();

        $incident = $this->service->createIncident('normal', $this->user->id, [
            'title' => 'سقوط أداة', 'description' => 'وقعت أداة', 'risk_id' => $risk->id,
        ]);

        // المنسق والفني معروفان → المسار الآلي يصل إلى «استلمه الفني»
        $this->assertSame('field_received', $incident->status);
        $this->assertNotNull($incident->received_at);
        $this->assertNotNull($incident->referred_at);
        $this->assertNotNull($incident->ref_received_at);
        $this->assertNotNull($incident->forwarded_at);
        $this->assertNotNull($incident->field_received_at);
        // بحساب: لا رمز تتبع (يتابع من حسابه)
        $this->assertSame($this->user->id, $incident->actor_id);
        $this->assertNull($incident->secret_tracking_code);
    }

    public function test_create_secret_incident_with_risk_also_auto_routes(): void
    {
        ['risk' => $risk] = $this->buildActorsAndRisk();

        $result = $this->service->createSecretIncident([
            'title' => 'بلاغ سرّي', 'description' => 'تفاصيل حساسة', 'secrecy_reason' => 'حماية المبلّغ', 'risk_id' => $risk->id,
        ]);

        $incident = $result['incident'];
        $this->assertNotEmpty($result['secret_key']);
        $this->assertSame('secret', $incident->incident_type);
        $this->assertNull($incident->actor_id);
        $this->assertSame('field_received', $incident->status);
        $this->assertNotEmpty($incident->secret_tracking_code);
    }

    public function test_resolve_requires_summary_and_attachment(): void
    {
        ['field' => $field, 'risk' => $risk] = $this->buildActorsAndRisk();
        $incident = $this->service->createIncident('normal', $this->user->id, ['title' => 't', 'description' => 'd', 'risk_id' => $risk->id]);
        $this->service->beginWork($incident, $field->id);

        // بلا مرفق وبلا ملخص → رفض
        $this->expectException(\InvalidArgumentException::class);
        $this->service->resolve($incident->fresh(), $field->id, '');
    }

    public function test_resolve_succeeds_with_summary_and_attachment(): void
    {
        ['field' => $field, 'risk' => $risk] = $this->buildActorsAndRisk();
        $incident = $this->service->createIncident('normal', $this->user->id, ['title' => 't', 'description' => 'd', 'risk_id' => $risk->id]);
        $this->service->beginWork($incident, $field->id);

        // دليل واحد يكفي للحارس
        $this->addEvidence($incident, $field->id);

        $resolved = $this->service->resolve(
            $incident->fresh(),
            $field->id,
            'تم تنفيذ الإجراء التصحيحي وإغلاق الموقع بالكامل بعد التحقق'
        );

        $this->assertSame('resolved', $resolved->status);
        $this->assertNotNull($resolved->resolution_summary);
    }

    public function test_normal_incident_close_blocked_until_reporter_approves(): void
    {
        ['admin' => $admin, 'field' => $field, 'risk' => $risk] = $this->buildActorsAndRisk();
        $incident = $this->service->createIncident('normal', $this->user->id, ['title' => 't', 'description' => 'd', 'risk_id' => $risk->id]);
        $this->service->beginWork($incident, $field->id);
        $this->addEvidence($incident, $field->id);
        $this->service->resolve($incident->fresh(), $field->id, 'تم تنفيذ الإجراء التصحيحي وإغلاق الموقع');

        // أول محاولة إغلاق → استثناء وتعليم pending_closure
        try {
            $this->closure->close($incident->fresh(), $admin->id);
            $this->fail('should have thrown');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('موافقة المُبلِّغ', $e->getMessage());
        }
        $this->assertTrue((bool) $incident->fresh()->pending_closure);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->user->id, 'type' => 'incident.closure']);

        // المبلّغ يوافق → الإغلاق ينجح
        $this->closure->approveClosure($incident->fresh(), $this->user->id);
        $closed = $this->closure->close($incident->fresh(), $admin->id);
        $this->assertSame('closed', $closed->status);
        $this->assertSame(1, $risk->fresh()->incident_count);
    }

    public function test_secret_incident_close_blocked_until_coord_verifies(): void
    {
        ['admin' => $admin, 'coord' => $coord, 'field' => $field, 'risk' => $risk] = $this->buildActorsAndRisk();
        $result = $this->service->createSecretIncident(['title' => 'بلاغ سري', 'description' => 'd', 'risk_id' => $risk->id]);
        $incident = $result['incident'];

        $this->service->beginWork($incident, $field->id);
        $this->addEvidence($incident, $field->id);
        $this->service->resolve($incident->fresh(), $field->id, 'تم تنفيذ الإجراء التصحيحي وإغلاق الموقع');

        // بلا تحقق → الإغلاق يرفض
        try {
            $this->closure->close($incident->fresh(), $admin->id);
            $this->fail('should have thrown');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('تحقق شخص غير المنفّذ', $e->getMessage());
        }

        // المنسق يتحقق (شخص غير الفني الذي عالج)
        $this->closure->verifyByCoordinator($incident->fresh(), $coord->id);
        $closed = $this->closure->close($incident->fresh(), $admin->id);
        $this->assertSame('closed', $closed->status);
    }

    public function test_coordinator_cannot_verify_their_own_resolution(): void
    {
        ['field' => $field, 'risk' => $risk] = $this->buildActorsAndRisk();
        $result = $this->service->createSecretIncident(['title' => 'بلاغ', 'description' => 'd', 'risk_id' => $risk->id]);
        $incident = $result['incident'];

        $this->service->beginWork($incident, $field->id);
        $this->addEvidence($incident, $field->id);
        $this->service->resolve($incident->fresh(), $field->id, 'تم تنفيذ الإجراء التصحيحي وإغلاق الموقع');

        // التحقق من الشخص نفسه الذي عالج → رفض
        $this->expectException(\InvalidArgumentException::class);
        $this->closure->verifyByCoordinator($incident->fresh(), $field->id);
    }

    public function test_escalate_to_coord_records_reason(): void
    {
        ['coord' => $coord, 'field' => $field, 'risk' => $risk] = $this->buildActorsAndRisk();
        $incident = $this->service->createIncident('normal', $this->user->id, ['title' => 't', 'description' => 'd', 'risk_id' => $risk->id]);
        $this->service->beginWork($incident, $field->id);

        $escalated = $this->service->escalateToCoordinator(
            $incident->fresh(), $field->id, 'يتطلب صلاحيات أعلى لإيقاف الكهرباء'
        );

        $this->assertSame('escalated_to_coord', $escalated->status);
        $this->assertSame(1, $escalated->escalation_level);
        $this->assertStringContainsString('صلاحيات', $escalated->escalation_reason);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $coord->id, 'type' => 'incident.escalated']);
    }

    public function test_role_enforcement_blocks_admin_from_field_actions(): void
    {
        ['admin' => $admin, 'risk' => $risk] = $this->buildActorsAndRisk();
        $incident = $this->service->createIncident('normal', $this->user->id, ['title' => 't', 'description' => 'd', 'risk_id' => $risk->id]);

        // مسؤول السلامة لا يبدأ المعالجة — للفني وحده
        $this->expectException(TransitionException::class);
        $this->service->beginWork($incident->fresh(), $admin->id);
    }

    public function test_role_enforcement_blocks_field_worker_from_closing(): void
    {
        // المعهد: الفني لا يغلق؛ ومن تولّى المعالجة بعد التصعيد صار المنفّذ فيعلّم «عولج» والفني السابق لا
        ['admin' => $admin, 'coord' => $coord, 'field' => $field, 'risk' => $risk] = $this->buildActorsAndRisk();
        $incident = $this->service->createIncident('normal', $this->user->id, ['title' => 't', 'description' => 'd', 'risk_id' => $risk->id]);
        $this->service->beginWork($incident, $field->id);
        $this->addEvidence($incident, $field->id);
        $this->service->resolve($incident->fresh(), $field->id, 'تم تنفيذ الإجراء التصحيحي وإغلاق الموقع');
        // طلب الإغلاق من المركز يعلّم pending_closure، ثم يوافق المبلّغ — فتبقى بوابة الدور وحدها
        try { $this->closure->close($incident->fresh(), $admin->id); } catch (\InvalidArgumentException $e) {}
        $this->closure->approveClosure($incident->fresh(), $this->user->id);

        try {
            $this->closure->close($incident->fresh(), $field->id);
            $this->fail('field worker must not close');
        } catch (TransitionException $e) {
            $this->assertStringContainsString('لا يملك الانتقال', $e->getMessage());
        }
        $this->assertSame('resolved', $incident->fresh()->status);

        // إعادة للمعالجة ثم تصعيد ثم تولّي المنسق
        $this->closure->rejectClosure($incident->fresh(), $coord->id, 'لم يُعالج فعلاً');
        $this->service->escalateToCoordinator($incident->fresh(), $field->id, 'يحتاج قراراً من المنسق');
        $taken = $this->service->resolveEscalation($incident->fresh(), $coord->id);
        $this->assertSame('in_progress', $taken->status);
        $this->assertSame($coord->id, $taken->incident_field_team_id);

        $this->expectException(TransitionException::class);
        $this->service->resolve($taken->fresh(), $field->id, 'محاولة من الفني السابق بعد التولّي يجب أن تُرفض');
    }

    public function test_out_of_scope_works_from_any_state(): void
    {
        ['admin' => $admin, 'risk' => $risk] = $this->buildActorsAndRisk();
        $incident = $this->service->createIncident('normal', $this->user->id, ['title' => 't', 'description' => 'd', 'risk_id' => $risk->id]);

        $result = $this->service->outOfScope($incident, $admin->id, 'لا يندرج ضمن نطاق العمل');
        $this->assertSame('out_of_scope', $result->status);
    }

    public function test_assign_records_event_and_metadata(): void
    {
        // خطر بلا معيَّنين وبلا مكان → يتوقف عند «وصل المركز»؛ ثم المركز يحيل إلى فني (referToField حلّت محل assign)
        ['admin' => $admin] = $this->buildActorsAndRisk();
        $risk = $this->makeRisk();
        $incident = $this->service->createIncident('normal', $this->user->id, ['title' => 't', 'description' => 'd', 'risk_id' => $risk->id]);
        $this->assertSame('received', $incident->status);
        $this->assertNull($incident->incident_field_team_id);
        $assignee = $this->makeUser('field_worker');

        $assigned = $this->service->referToField($incident, $admin->id, $assignee->id);

        $this->assertSame($assignee->id, $assigned->assigned_to_id);
        $this->assertSame($assignee->id, $assigned->incident_field_team_id);
        $this->assertSame($admin->id, $assigned->assigned_by_id);
        $this->assertNotNull($assigned->assigned_at);
        $this->assertSame('forwarded', $assigned->status);
        $this->assertDatabaseHas('incident_events', [
            'incident_id' => $incident->id,
            'action' => 'assign',
        ]);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $assignee->id, 'type' => 'incident.forwarded']);
    }

    public function test_dashboard_data_returns_counts(): void
    {
        $admin = $this->makeUser('system_admin');
        foreach (range(1, 3) as $i) $this->makeIncident(['status' => 'new']);
        foreach (range(1, 2) as $i) $this->makeIncident(['status' => 'in_progress']);
        $this->makeIncident(['status' => 'closed']);

        $data = $this->service->getDashboardData($admin->id, app(IncidentVisibilityService::class));

        $this->assertSame(6, $data['total']);
        $this->assertSame(3, $data['waiting_center']);
        $this->assertSame(2, $data['with_field']);
        $this->assertSame(1, $data['closed']);
        $this->assertSame(5, $data['open']);
    }
}
