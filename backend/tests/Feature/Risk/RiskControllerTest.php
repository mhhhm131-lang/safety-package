<?php

namespace Tests\Feature\Risk;

use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskSubCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * منقول من OHSMS بلا tenant. الفروق المقصودة في المعهد:
 * العنوان يُشتق من الفئة الفرعية (لا يُرسل)، والتحديث يعود إلى سجل الإدارة لا إلى التفاصيل.
 */
class RiskControllerTest extends TestCase
{
    use RefreshDatabase, RiskFixtures;

    private ?RiskCategory $category = null;
    private ?RiskSubCategory $subCategory = null;

    private function category(): RiskCategory
    {
        return $this->category ??= $this->makeCategory('مخاطر الحريق');
    }

    private function subCategory(): RiskSubCategory
    {
        return $this->subCategory ??= $this->makeSubCategory($this->category(), 'سقوط من ارتفاع في موقع البناء');
    }

    private function makeActiveRisk(int $userId, array $overrides = []): Risk
    {
        return $this->makeRisk(array_merge([
            'created_by_id' => $userId,
            'risk_type' => 'active',
            'status' => 'draft',
        ], $overrides));
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'category_id' => $this->category()->id,
            'sub_category_id' => $this->subCategory()->id,
            'severity' => 4,
            'likelihood' => 3,
            'phases' => [
                'proactive' => [
                    'corrective_action' => 'تركيب سلة أمان وحبل حياة',
                    'preventive_action' => 'تدريب العمال على معدات الحماية',
                ],
            ],
        ], $overrides);
    }

    // ===========================================================
    // Auth & RBAC for index
    // ===========================================================

    public function test_unauthenticated_user_redirected_from_index(): void
    {
        $this->get(route('risk.index'))->assertRedirect();
    }

    public function test_field_worker_forbidden_from_index(): void
    {
        $this->actingAsRole('field_worker');
        $this->get(route('risk.index'))->assertForbidden();
    }

    public function test_safety_committee_can_view_index(): void
    {
        $this->actingAsRole('safety_committee');
        $this->get(route('risk.index'))->assertOk();
    }

    public function test_system_admin_can_view_index(): void
    {
        $this->actingAsRole('system_admin');
        $this->get(route('risk.index'))->assertOk();
    }

    // ===========================================================
    // RBAC for create
    // ===========================================================

    public function test_safety_committee_forbidden_from_create_form(): void
    {
        // committee has list but NOT create permission
        $this->actingAsRole('safety_committee');
        $this->get(route('risk.create'))->assertForbidden();
    }

    public function test_safety_coordinator_can_access_create_form(): void
    {
        $this->actingAsRole('safety_coordinator');
        $this->get(route('risk.create'))->assertOk();
    }

    public function test_top_management_forbidden_from_create(): void
    {
        // top_management has approve but NOT create
        $this->actingAsRole('top_management');
        $this->get(route('risk.create'))->assertForbidden();
    }

    // ===========================================================
    // Store / validation
    // ===========================================================

    public function test_store_requires_title_description_category_severity_likelihood(): void
    {
        // المعهد: العنوان والوصف يُشتقان من الفئة الفرعية، فالمطلوب الفئة والخطورة والاحتمالية.
        $this->actingAsRole('safety_coordinator');
        $this->post(route('risk.store'), [])
            ->assertSessionHasErrors(['category_id', 'severity', 'likelihood']);
    }

    public function test_store_severity_must_be_in_1_to_5_range(): void
    {
        $this->actingAsRole('safety_coordinator');
        $this->post(route('risk.store'), $this->validPayload(['severity' => 6]))
            ->assertSessionHasErrors('severity');
        $this->post(route('risk.store'), $this->validPayload(['severity' => 0]))
            ->assertSessionHasErrors('severity');
    }

    public function test_store_creates_risk_with_calculated_score(): void
    {
        $user = $this->actingAsRole('safety_coordinator');
        $this->post(route('risk.store'), $this->validPayload([
            'severity' => 4,
            'likelihood' => 3,
        ]))->assertRedirect(route('risk.active.index'));

        $risk = Risk::latest('id')->first();
        $this->assertNotNull($risk);
        $this->assertSame($user->id, $risk->created_by_id);
        $this->assertSame('draft', $risk->status);
        $this->assertSame(12, $risk->risk_score); // 4 * 3
        $this->assertSame('سقوط من ارتفاع في موقع البناء', $risk->title); // مشتق من الفئة الفرعية
    }

    public function test_store_creates_risk_with_three_empty_phases(): void
    {
        // Causes + actions are phase-level — the basic /risk/create form
        // creates the risk scaffold, and the three phase rows are
        // auto-created so the coordinator can fill them from the detail page.
        $this->actingAsRole('safety_coordinator');
        $this->post(route('risk.store'), $this->validPayload(['phases' => []]))->assertRedirect();

        $risk = Risk::latest('id')->first();
        $this->assertSame(3, $risk->phases()->count());
    }

    // ===========================================================
    // Show / Edit / Update / Destroy
    // ===========================================================

    public function test_show_renders_risk_detail(): void
    {
        $user = $this->actingAsRole('safety_committee');
        $risk = $this->makeActiveRisk($user->id);

        $this->get(route('risk.show', $risk))->assertOk();
    }

    public function test_update_persists_changes(): void
    {
        $user = $this->actingAsRole('safety_coordinator');
        $risk = $this->makeActiveRisk($user->id, ['title' => 'قديم', 'severity' => 2, 'likelihood' => 2]);
        $newSub = $this->makeSubCategory($this->category(), 'محدّث');

        // المعهد: العنوان يُشتق من الفئة الفرعية المرسلة، والعودة إلى سجل الإدارة.
        $this->post(route('risk.update', $risk), $this->validPayload([
            'category_id' => $this->category()->id,
            'sub_category_id' => $newSub->id,
            'severity' => 5,
            'likelihood' => 4,
        ]))->assertRedirect(route('risk.active.index'));

        $risk->refresh();
        $this->assertSame('محدّث', $risk->title);
        $this->assertSame(5, $risk->severity);
        $this->assertSame(20, $risk->risk_score);
    }

    public function test_destroy_works_only_on_draft(): void
    {
        $user = $this->actingAsRole('safety_coordinator');
        $draft = $this->makeActiveRisk($user->id, ['status' => 'draft']);
        $approved = $this->makeActiveRisk($user->id, ['status' => 'approved']);

        // draft → deleted
        $this->post(route('risk.destroy', $draft))->assertRedirect(route('risk.index'));
        $this->assertDatabaseMissing('risks', ['id' => $draft->id]);

        // approved → not deleted
        $this->post(route('risk.destroy', $approved))->assertRedirect(route('risk.index'));
        $this->assertDatabaseHas('risks', ['id' => $approved->id]);
    }

    // ===========================================================
    // Approval flow
    // ===========================================================

    public function test_safety_coordinator_forbidden_from_approval_queue(): void
    {
        // approval queue requires risk.approve which excludes safety_coordinator
        $this->actingAsRole('safety_coordinator');
        $this->get(route('risk.approval.queue'))->assertForbidden();
    }

    public function test_safety_committee_can_view_approval_queue(): void
    {
        $this->actingAsRole('safety_committee');
        $this->get(route('risk.approval.queue'))->assertOk();
    }

    public function test_approve_transitions_pending_risk_to_approved(): void
    {
        $coordinator = $this->actingAsRole('safety_coordinator');
        $risk = $this->makeActiveRisk($coordinator->id, ['status' => 'pending_approval']);

        // switch to a role that can approve
        $this->actingAsRole('safety_committee');
        $this->post(route('risk.approve', $risk), ['notes' => 'موافقة']);

        $risk->refresh();
        $this->assertSame('approved', $risk->status);
    }

    public function test_reject_transitions_pending_risk_to_rejected(): void
    {
        $creator = $this->actingAsRole('safety_coordinator');
        $risk = $this->makeActiveRisk($creator->id, ['status' => 'pending_approval']);

        $this->actingAsRole('safety_committee');
        $this->post(route('risk.reject', $risk), ['notes' => 'تقييم غير كافٍ']);

        $risk->refresh();
        $this->assertSame('rejected', $risk->status);
    }

    public function test_request_modification_requires_notes(): void
    {
        $creator = $this->actingAsRole('safety_coordinator');
        $risk = $this->makeActiveRisk($creator->id, ['status' => 'pending_approval']);

        $this->actingAsRole('safety_committee');
        $this->post(route('risk.requestModification', $risk), [])
            ->assertSessionHasErrors('notes');
    }

    public function test_request_modification_returns_to_draft(): void
    {
        $creator = $this->actingAsRole('safety_coordinator');
        $risk = $this->makeActiveRisk($creator->id, ['status' => 'pending_approval']);

        $this->actingAsRole('safety_committee');
        $this->post(route('risk.requestModification', $risk), [
            'notes' => 'يرجى إضافة المزيد من الإجراءات الوقائية',
        ]);

        $risk->refresh();
        $this->assertSame('draft', $risk->status);
    }

    public function test_field_worker_forbidden_from_approve(): void
    {
        $coordinator = $this->actingAsRole('safety_coordinator');
        $risk = $this->makeActiveRisk($coordinator->id, ['status' => 'pending_approval']);

        $this->actingAsRole('field_worker');
        $this->post(route('risk.approve', $risk), [])->assertForbidden();
    }

    // ===========================================================
    // CSV Export
    // ===========================================================

    public function test_export_returns_csv_for_authorized_role(): void
    {
        $user = $this->actingAsRole('system_admin');
        $this->makeActiveRisk($user->id, ['title' => 'risk-export-test', 'status' => 'active']);

        $response = $this->get(route('risk.export'));
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }
}
