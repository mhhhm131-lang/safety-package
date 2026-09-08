<?php

namespace Tests\Feature\Risk;

use App\Modules\Risk\Models\AffectedGroup;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCause;
use App\Modules\Risk\Models\RiskPhase;
use App\Modules\Risk\Models\RiskPhaseAffectedGroupDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 7 feature test — the riskDetail endpoint that powers the book's
 * right-hand detail panel must return phase data in order and drop the
 * legacy single-phase fields.
 */
class RiskBookTreeDetailPhasesTest extends TestCase
{
    use RefreshDatabase, RiskFixtures;

    private function masterRisk(): Risk
    {
        return $this->makeRisk(['risk_type' => 'master']);
    }

    public function test_risk_detail_returns_phases_in_fixed_order(): void
    {
        $this->actingAsRole('system_admin');
        $risk = $this->masterRisk();

        // Insert in an intentionally scrambled order to verify the endpoint
        // still returns proactive → operational → response.
        RiskPhase::create(['risk_id' => $risk->id, 'phase' => RiskPhase::PHASE_RESPONSE, 'corrective_action' => 'R']);
        RiskPhase::create(['risk_id' => $risk->id, 'phase' => RiskPhase::PHASE_PROACTIVE, 'corrective_action' => 'P']);
        RiskPhase::create(['risk_id' => $risk->id, 'phase' => RiskPhase::PHASE_OPERATIONAL, 'corrective_action' => 'O']);

        $response = $this->getJson(route('risk.book.tree.riskDetail', $risk));
        $response->assertOk();

        $phases = $response->json('phases');
        $this->assertCount(3, $phases);
        $this->assertSame(['proactive', 'operational', 'response'], array_column($phases, 'phase'));
        $this->assertSame(['استباقي', 'تشغيلي', 'استجابة'], array_column($phases, 'phase_label'));
    }

    public function test_phase_body_includes_causes_and_affected_groups_with_impact(): void
    {
        $this->actingAsRole('system_admin');
        $risk = $this->masterRisk();

        $phase = RiskPhase::create([
            'risk_id' => $risk->id,
            'phase'   => RiskPhase::PHASE_PROACTIVE,
            'preventive_action' => 'تدريب',
            'corrective_action' => 'فحص',
            'responsible_org_unit_text' => 'قسم السلامة',
            'responsible_user_text'     => 'مشرف السلامة',
        ]);
        RiskPhase::create(['risk_id' => $risk->id, 'phase' => RiskPhase::PHASE_OPERATIONAL]);
        RiskPhase::create(['risk_id' => $risk->id, 'phase' => RiskPhase::PHASE_RESPONSE]);

        $cause = RiskCause::create(['name' => 'إهمال']);
        $phase->causes()->sync([$cause->id]);

        $group = AffectedGroup::create(['name' => 'العامل']);
        $phase->affectedGroups()->sync([$group->id]);
        RiskPhaseAffectedGroupDetail::create([
            'risk_phase_id'     => $phase->id,
            'affected_group_id' => $group->id,
            'impact'            => 'high',
            'rep_scope'         => 'local',
        ]);

        $response = $this->getJson(route('risk.book.tree.riskDetail', $risk));
        $proactive = collect($response->json('phases'))->firstWhere('phase', 'proactive');

        $this->assertSame('تدريب', $proactive['preventive_action']);
        $this->assertSame('فحص', $proactive['corrective_action']);
        $this->assertSame('قسم السلامة', $proactive['responsible_org_unit']);
        $this->assertSame('مشرف السلامة', $proactive['responsible_user']);
        $this->assertSame([['id' => $cause->id, 'name' => 'إهمال']], $proactive['causes']);
        $this->assertSame($group->id, $proactive['affected_groups'][0]['id']);
        $this->assertSame('high', $proactive['affected_groups'][0]['impact']);
        $this->assertSame('local', $proactive['affected_groups'][0]['rep_scope']);
    }

    public function test_legacy_risk_without_phases_returns_empty_phases_array(): void
    {
        $this->actingAsRole('system_admin');
        $risk = $this->masterRisk();
        // Do NOT create phase rows — legacy scenario.

        $response = $this->getJson(route('risk.book.tree.riskDetail', $risk));

        $response->assertOk();
        $response->assertJsonPath('phases', []);
    }

    public function test_detail_no_longer_exposes_legacy_single_phase_fields_at_root(): void
    {
        // The legacy columns never existed in the institute schema — the
        // endpoint must expose `phases` and NOT the old root-level fields.
        $this->actingAsRole('system_admin');
        $risk = $this->masterRisk();

        $response = $this->getJson(route('risk.book.tree.riskDetail', $risk));
        $json = $response->json();

        $this->assertArrayNotHasKey('corrective_action', $json);
        $this->assertArrayNotHasKey('preventive_action', $json);
        $this->assertArrayNotHasKey('owner_department', $json);
        $this->assertArrayNotHasKey('owner_person', $json);
        $this->assertArrayNotHasKey('causes', $json);
        $this->assertArrayNotHasKey('affected_groups', $json);
        $this->assertArrayHasKey('phases', $json);
    }
}
