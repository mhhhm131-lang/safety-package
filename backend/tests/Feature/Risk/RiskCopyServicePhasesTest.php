<?php

namespace Tests\Feature\Risk;

use App\Models\User;
use App\Modules\Risk\Models\AffectedGroup;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCause;
use App\Modules\Risk\Models\RiskPhase;
use App\Modules\Risk\Models\RiskPhaseAffectedGroupDetail;
use App\Modules\Risk\Services\RiskCopyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 4 focused tests — RiskCopyService must carry the three phases
 * (and all their per-phase causes/groups/details) whenever it clones
 * a risk. المعهد واحد: المفاتيح الخارجية للمسؤولين تُحفظ عند النسخ.
 */
class RiskCopyServicePhasesTest extends TestCase
{
    use RefreshDatabase, RiskFixtures;

    private RiskCopyService $copy;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->copy = new RiskCopyService();
        $this->user = $this->makeUser('system_admin');
    }

    public function test_master_to_reference_copies_all_three_phases(): void
    {
        $master = $this->buildPopulatedMaster();

        $reference = $this->copy->masterToReference($master, $this->user->id);

        $phases = $reference->fresh()->phases;
        $this->assertCount(3, $phases);
        $this->assertEqualsCanonicalizing(
            ['proactive', 'operational', 'response'],
            $phases->pluck('phase')->all()
        );
    }

    public function test_copy_preserves_per_phase_content(): void
    {
        $master = $this->buildPopulatedMaster();

        $reference = $this->copy->masterToReference($master, $this->user->id);

        $refProactive = $reference->fresh()->phases->firstWhere('phase', RiskPhase::PHASE_PROACTIVE);
        $this->assertSame('تدريب العمال قبل العمل', $refProactive->preventive_action);
        $this->assertSame('فحص المعدات', $refProactive->corrective_action);

        $refResponse = $reference->phases->firstWhere('phase', RiskPhase::PHASE_RESPONSE);
        $this->assertSame('إسعاف أولي + تحقيق', $refResponse->corrective_action);
    }

    public function test_copy_carries_phase_causes_and_affected_groups(): void
    {
        $master = $this->buildPopulatedMaster();

        $reference = $this->copy->masterToReference($master, $this->user->id);
        $proactive = $reference->fresh()->phases->firstWhere('phase', RiskPhase::PHASE_PROACTIVE);

        $this->assertCount(2, $proactive->causes);
        $this->assertEqualsCanonicalizing(
            ['عدم تدريب', 'حبال غير مفحوصة'],
            $proactive->causes->pluck('name')->all()
        );
        $this->assertCount(1, $proactive->affectedGroups);
    }

    public function test_copy_carries_rich_affected_group_details_per_phase(): void
    {
        $master = $this->buildPopulatedMaster();

        $reference = $this->copy->masterToReference($master, $this->user->id);
        $response = $reference->fresh()->phases->firstWhere('phase', RiskPhase::PHASE_RESPONSE);

        $detail = $response->affectedGroupDetails->first();
        $this->assertNotNull($detail);
        $this->assertSame('critical', $detail->impact);
        $this->assertSame('خسارة دخل + صدمة', $detail->impact_description);
    }

    public function test_same_tenant_copy_keeps_fks(): void
    {
        // المعهد واحد (لا tenant): المفاتيح الخارجية للمسؤولين تبقى عند النسخ من السجل العام إلى سجل الإدارة.
        $unit = $this->orgUnit('hr');
        $responsible = $this->makeUser('safety_coordinator', null, 'مسؤول السلامة');

        $reference = $this->makeRisk(['risk_type' => 'reference']);
        RiskPhase::create([
            'risk_id' => $reference->id,
            'phase'   => RiskPhase::PHASE_PROACTIVE,
            'responsible_org_unit_id' => $unit->id,
            'responsible_user_id'     => $responsible->id,
        ]);
        RiskPhase::create(['risk_id' => $reference->id, 'phase' => RiskPhase::PHASE_OPERATIONAL]);
        RiskPhase::create(['risk_id' => $reference->id, 'phase' => RiskPhase::PHASE_RESPONSE]);

        $active = $this->copy->referenceToActive($reference, $this->user->id, [
            'scope_type' => 'org_unit', 'organization_unit_id' => $unit->id,
        ]);
        $this->assertSame('active', $active->risk_type);
        $this->assertSame($reference->id, $active->parent_reference_id);

        $copiedProactive = $active->fresh()->phases->firstWhere('phase', RiskPhase::PHASE_PROACTIVE);
        $this->assertSame($unit->id, $copiedProactive->responsible_org_unit_id);
        $this->assertSame($responsible->id, $copiedProactive->responsible_user_id);
    }

    public function test_copying_risk_without_phases_still_produces_three(): void
    {
        // Simulates legacy pre-migration master risks — no phase rows on source.
        $master = $this->makeRisk(['risk_type' => 'master']);
        $this->assertSame(0, RiskPhase::where('risk_id', $master->id)->count());

        $reference = $this->copy->masterToReference($master, $this->user->id);

        $this->assertSame(3, RiskPhase::where('risk_id', $reference->id)->count());
    }

    /**
     * Builds a master risk with all three phases populated — used by the
     * happy-path assertions above.
     */
    private function buildPopulatedMaster(): Risk
    {
        $master = $this->makeRisk([
            'risk_type'   => 'master',
            'title'       => 'سقوط من مبنى عالٍ',
            'severity'    => 5,
            'likelihood'  => 3,
        ]);

        $causeTraining = RiskCause::create(['name' => 'عدم تدريب']);
        $causeRopes    = RiskCause::create(['name' => 'حبال غير مفحوصة']);
        $group         = AffectedGroup::create(['name' => 'العامل']);
        $familyGroup   = AffectedGroup::create(['name' => 'الأسرة']);

        $proactive = RiskPhase::create([
            'risk_id'           => $master->id,
            'phase'             => RiskPhase::PHASE_PROACTIVE,
            'preventive_action' => 'تدريب العمال قبل العمل',
            'corrective_action' => 'فحص المعدات',
            'responsible_org_unit_text' => 'قسم التدريب',
            'responsible_user_text'     => 'مدرب السلامة',
        ]);
        $proactive->causes()->sync([$causeTraining->id, $causeRopes->id]);
        $proactive->affectedGroups()->sync([$group->id]);

        RiskPhase::create([
            'risk_id'           => $master->id,
            'phase'             => RiskPhase::PHASE_OPERATIONAL,
            'preventive_action' => 'إيقاف العمل عند الرياح',
            'corrective_action' => 'رفع العامل بأمان',
        ]);

        $response = RiskPhase::create([
            'risk_id'           => $master->id,
            'phase'             => RiskPhase::PHASE_RESPONSE,
            'corrective_action' => 'إسعاف أولي + تحقيق',
        ]);
        $response->affectedGroups()->sync([$familyGroup->id]);
        RiskPhaseAffectedGroupDetail::create([
            'risk_phase_id'      => $response->id,
            'affected_group_id'  => $familyGroup->id,
            'impact'             => 'critical',
            'impact_description' => 'خسارة دخل + صدمة',
        ]);

        return $master;
    }
}
