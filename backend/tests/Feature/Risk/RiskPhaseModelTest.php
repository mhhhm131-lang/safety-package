<?php

namespace Tests\Feature\Risk;

use App\Modules\Risk\Models\AffectedGroup;
use App\Modules\Risk\Models\RiskCause;
use App\Modules\Risk\Models\RiskPhase;
use App\Modules\Risk\Models\RiskPhaseAffectedGroupDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Focused tests for the RiskPhase model — Step 2 of the phase-structure
 * migration. Verifies the three-phases-per-risk contract, the FK-plus-free-text
 * responsibility pattern, and that pivots attach correctly.
 */
class RiskPhaseModelTest extends TestCase
{
    use RefreshDatabase, RiskFixtures;

    public function test_risk_can_have_three_phases(): void
    {
        $risk = $this->makeRisk();

        foreach (RiskPhase::PHASES as $phase) {
            RiskPhase::create([
                'risk_id' => $risk->id,
                'phase'   => $phase,
                'preventive_action' => "Prevent {$phase}",
                'corrective_action' => "Correct {$phase}",
            ]);
        }

        $this->assertCount(3, $risk->fresh()->phases);
    }

    public function test_unique_constraint_blocks_duplicate_phase(): void
    {
        $risk = $this->makeRisk();

        RiskPhase::create(['risk_id' => $risk->id, 'phase' => RiskPhase::PHASE_PROACTIVE]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        RiskPhase::create(['risk_id' => $risk->id, 'phase' => RiskPhase::PHASE_PROACTIVE]);
    }

    public function test_phase_label_returns_arabic(): void
    {
        $phase = new RiskPhase(['phase' => RiskPhase::PHASE_PROACTIVE]);

        $this->assertSame('استباقي', $phase->phase_label);
    }

    public function test_responsible_display_prefers_fk_over_text(): void
    {
        $risk = $this->makeRisk();
        $unit = $this->orgUnit('hr');
        $user = $this->makeUser('safety_coordinator', null, 'مدير السلامة');

        $phase = RiskPhase::create([
            'risk_id' => $risk->id,
            'phase'   => RiskPhase::PHASE_OPERATIONAL,
            'responsible_org_unit_id'   => $unit->id,
            'responsible_org_unit_text' => 'نص احتياطي — لن يُستخدم',
            'responsible_user_id'       => $user->id,
            'responsible_user_text'     => 'نص احتياطي — لن يُستخدم',
        ])->fresh(['responsibleOrgUnit', 'responsibleUser']);

        $this->assertSame($unit->name, $phase->responsible_org_unit_display);
        $this->assertSame('مدير السلامة', $phase->responsible_user_display);
    }

    public function test_responsible_display_falls_back_to_free_text(): void
    {
        $risk = $this->makeRisk();

        $phase = RiskPhase::create([
            'risk_id' => $risk->id,
            'phase'   => RiskPhase::PHASE_RESPONSE,
            'responsible_org_unit_text' => 'قسم الطوارئ (نص حر)',
            'responsible_user_text'     => 'قائد الطوارئ (نص حر)',
        ]);

        $this->assertSame('قسم الطوارئ (نص حر)', $phase->responsible_org_unit_display);
        $this->assertSame('قائد الطوارئ (نص حر)', $phase->responsible_user_display);
    }

    public function test_phase_attaches_causes_via_pivot(): void
    {
        $risk = $this->makeRisk();
        $phase = RiskPhase::create(['risk_id' => $risk->id, 'phase' => RiskPhase::PHASE_PROACTIVE]);

        $cause1 = RiskCause::create(['name' => 'عدم تدريب']);
        $cause2 = RiskCause::create(['name' => 'معدات غير مفحوصة']);

        $phase->causes()->sync([$cause1->id, $cause2->id]);

        $this->assertCount(2, $phase->fresh()->causes);
        $this->assertDatabaseHas('risk_phase_causes', [
            'risk_phase_id' => $phase->id,
            'risk_cause_id' => $cause1->id,
        ]);
    }

    public function test_phase_attaches_affected_groups_with_per_phase_details(): void
    {
        $risk = $this->makeRisk();
        $phase = RiskPhase::create(['risk_id' => $risk->id, 'phase' => RiskPhase::PHASE_RESPONSE]);

        $group = AffectedGroup::create(['name' => 'أسرة العامل']);

        $phase->affectedGroups()->sync([$group->id]);

        $detail = RiskPhaseAffectedGroupDetail::create([
            'risk_phase_id'      => $phase->id,
            'affected_group_id'  => $group->id,
            'impact'             => 'high',
            'impact_description' => 'صدمة نفسية وخسارة دخل',
        ]);

        $this->assertSame('high', $detail->impact);
        $this->assertSame('warning', $detail->impact_color);
        $this->assertCount(1, $phase->fresh()->affectedGroupDetails);
    }
}
