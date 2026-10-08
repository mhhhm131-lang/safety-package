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
 * Step 7 feature test — the riskDetail endpoint that powers the reference register's
 * right-hand detail panel (moved from the book in phase 16 batch 8; same pack()) must return phase data in order and drop the
 * legacy single-phase fields.
 */
class RiskRegistryTreeDetailPhasesTest extends TestCase
{
    use RefreshDatabase, RiskFixtures;

    private function referenceRisk(): Risk
    {
        return $this->makeRisk(['risk_type' => 'reference', 'status' => 'approved']); // قرار ٧٥: السجل العام يعرض ما اعتُمد
    }

    /** خطة المعالج — الخطوة ٦: صف واحد بلا أطوار، ومحتواه مسطّح في الجذر أيضاً */
    public function test_risk_detail_returns_the_single_row_and_flat_fields(): void
    {
        $this->actingAsRole('system_admin');
        $risk = $this->referenceRisk();
        RiskPhase::create(['risk_id' => $risk->id, 'phase' => RiskPhase::PHASE_SINGLE, 'corrective_action' => 'P']);

        $response = $this->getJson(route('risk.registry.tree.riskDetail', ['type' => 'reference', 'riskId' => $risk->id]));
        $response->assertOk();

        $phases = $response->json('phases');
        $this->assertCount(1, $phases);
        $this->assertSame(['single'], array_column($phases, 'phase'));
        $this->assertSame(['الإجراءات'], array_column($phases, 'phase_label'));
        $this->assertSame('P', $response->json('corrective_action'));
        $this->assertSame([], $response->json('causes'));
    }

    public function test_phase_body_includes_causes_and_affected_groups_with_impact(): void
    {
        $this->actingAsRole('system_admin');
        $risk = $this->referenceRisk();

        $phase = RiskPhase::create([
            'risk_id' => $risk->id,
            'phase'   => RiskPhase::PHASE_SINGLE,
            'preventive_action' => 'تدريب',
            'corrective_action' => 'فحص',
            'responsible_org_unit_text' => 'قسم السلامة',
            'responsible_user_text'     => 'مشرف السلامة',
        ]);
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

        $response = $this->getJson(route('risk.registry.tree.riskDetail', ['type' => 'reference', 'riskId' => $risk->id]));
        $proactive = collect($response->json('phases'))->firstWhere('phase', 'single');

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
        $risk = $this->referenceRisk();
        // Do NOT create phase rows — legacy scenario.

        $response = $this->getJson(route('risk.registry.tree.riskDetail', ['type' => 'reference', 'riskId' => $risk->id]));

        $response->assertOk();
        $response->assertJsonPath('phases', []);
    }

    /** الخطوة ٦: الصف الواحد مسطّح في الجذر (الأسباب والإجراءان والمتأثرون) إلى جانب `phases`؛ ولا خانات OHSMS القديمة للمالك */
    public function test_detail_exposes_the_single_row_flat_and_no_legacy_owner_fields(): void
    {
        $this->actingAsRole('system_admin');
        $risk = $this->referenceRisk();

        $response = $this->getJson(route('risk.registry.tree.riskDetail', ['type' => 'reference', 'riskId' => $risk->id]));
        $json = $response->json();

        foreach (['corrective_action', 'preventive_action', 'residual_assessment', 'causes', 'affected_groups', 'phases'] as $k) $this->assertArrayHasKey($k, $json);
        $this->assertArrayNotHasKey('owner_department', $json);
        $this->assertArrayNotHasKey('owner_person', $json);
    }
}
