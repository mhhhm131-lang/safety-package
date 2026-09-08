<?php

namespace Tests\Feature\Risk;

use App\Models\User;
use App\Modules\Risk\Models\AffectedGroup;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCause;
use App\Modules\Risk\Services\RiskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 9 feature test — activating a reference risk must produce an
 * active risk that (a) inherits all three phases with their content,
 * and (b) respects scope + user-supplied phase overrides.
 */
class RiskActivateWithPhasesTest extends TestCase
{
    use RefreshDatabase, RiskFixtures;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->actingAsRole('system_admin');
    }

    private function seedReferenceRisk(): Risk
    {
        $category = $this->makeCategory();
        $risk = $this->makeRisk([
            'risk_type'   => 'reference',
            'category_id' => $category->id,
            'severity'    => 3,
            'likelihood'  => 3,
        ]);

        app(RiskService::class)->ensurePhases($risk);

        // Populate the proactive phase on the reference — must propagate to active.
        $cause = RiskCause::create(['name' => 'سبب مرجعي']);
        $group = AffectedGroup::create(['name' => 'العامل']);

        $proactive = $risk->phases()->where('phase', 'proactive')->first();
        $proactive->update([
            'preventive_action'         => 'وقاية مرجعية',
            'corrective_action'         => 'تصحيح مرجعي',
            'responsible_org_unit_text' => 'قسم من المرجعي',
        ]);
        $proactive->causes()->sync([$cause->id]);
        $proactive->affectedGroups()->sync([$group->id]);

        return $risk->fresh(['phases.causes', 'phases.affectedGroups']);
    }

    public function test_activate_form_pre_fills_reference_phase_content(): void
    {
        $risk = $this->seedReferenceRisk();

        $response = $this->get(route('risk.activate.form', $risk));

        $response->assertOk();
        $response->assertSee('وقاية مرجعية', false);
        $response->assertSee('تصحيح مرجعي', false);
        $response->assertSee('قسم من المرجعي', false);
    }

    public function test_activate_copies_phases_from_reference(): void
    {
        $risk = $this->seedReferenceRisk();

        $response = $this->post(route('risk.activate', $risk), [
            'scope_type' => 'general',
            'severity'   => 3,
            'likelihood' => 3,
            // No 'phases' key — rely on the RiskCopyService copy path.
        ]);
        $response->assertRedirect(route('risk.active.index'));

        $active = Risk::where('risk_type', 'active')->where('parent_reference_id', $risk->id)->firstOrFail();
        $this->assertSame('active', $active->status);

        $activeProactive = $active->phases()->where('phase', 'proactive')
            ->with('causes', 'affectedGroups')->first();
        $this->assertSame('وقاية مرجعية', $activeProactive->preventive_action);
        $this->assertSame('تصحيح مرجعي', $activeProactive->corrective_action);
        $this->assertCount(1, $activeProactive->causes);
        $this->assertCount(1, $activeProactive->affectedGroups);
    }

    public function test_activate_applies_user_phase_overrides(): void
    {
        $risk = $this->seedReferenceRisk();

        $response = $this->post(route('risk.activate', $risk), [
            'scope_type' => 'general',
            'severity'   => 5,
            'likelihood' => 4,
            'phases' => [
                'proactive' => [
                    // Override the copied phase content.
                    'preventive_action' => 'وقاية خاصة بهذا التفعيل',
                    'corrective_action' => 'تصحيح خاص',
                ],
                'operational' => [],
                'response'    => ['corrective_action' => 'إجراء استجابة جديد'],
            ],
        ]);
        $response->assertRedirect(route('risk.active.index'));

        $active = Risk::where('risk_type', 'active')->where('parent_reference_id', $risk->id)->firstOrFail();
        $this->assertSame(20, $active->risk_score);

        $proactive = $active->phases()->where('phase', 'proactive')->first();
        $this->assertSame('وقاية خاصة بهذا التفعيل', $proactive->preventive_action);
        $this->assertSame('تصحيح خاص', $proactive->corrective_action);

        $responsePhase = $active->phases()->where('phase', 'response')->first();
        $this->assertSame('إجراء استجابة جديد', $responsePhase->corrective_action);
    }

    public function test_activate_respects_scope_and_assignments(): void
    {
        $risk = $this->seedReferenceRisk();
        $unit = $this->orgUnit('hr');
        $coordinator = $this->makeUser('safety_coordinator');

        $response = $this->post(route('risk.activate', $risk), [
            'scope_type'              => 'org_unit',
            'organization_unit_id'    => $unit->id,
            'severity'                => 4,
            'likelihood'              => 2,
            'assigned_coordinator_id' => $coordinator->id,
            'target_closure_date'     => '2027-01-01',
            'notes'                   => 'تفعيل للموارد البشرية',
        ]);
        $response->assertRedirect(route('risk.active.index'));

        $active = Risk::where('risk_type', 'active')->where('parent_reference_id', $risk->id)->firstOrFail();
        $this->assertSame($unit->id, $active->organization_unit_id);
        $this->assertSame($coordinator->id, $active->assigned_coordinator_id);
        $this->assertSame('تفعيل للموارد البشرية', $active->notes);
    }
}
