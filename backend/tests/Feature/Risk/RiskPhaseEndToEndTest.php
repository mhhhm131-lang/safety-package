<?php

namespace Tests\Feature\Risk;

use App\Modules\Risk\Models\AffectedGroup;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskPhase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Step 12 end-to-end verification — walks the full lifecycle of a
 * phase-aware risk across every layer: master book → bulk copy →
 * institute reference register → activation. If this test passes, the
 * phase structure is coherent across the system.
 * (مرحلة «بلاغ الشاغل يرث الإجراءات» من OHSMS أُسقطت: وحدة البلاغات لم تُنقل بعد.)
 */
class RiskPhaseEndToEndTest extends TestCase
{
    use RefreshDatabase, RiskFixtures;

    public function test_master_to_reference_to_active_to_incident_inherits_phases_correctly(): void
    {
        // ─── SETUP — مسؤول السلامة يملك الكتاب ────────────────────────
        $safety = $this->actingAsRole('system_admin', null, 'مسؤول السلامة');

        // ─── STAGE 1 — Safety officer creates a master risk with 3 phases ──
        $category = $this->makeCategory();
        $worker   = AffectedGroup::create(['name' => 'العامل']);
        $family   = AffectedGroup::create(['name' => 'أسرة العامل']);

        $this->post(route('risk.master.store'), [
            'title'       => 'سقوط من ارتفاع',
            'description' => 'خطر شامل مع 3 مراحل',
            'category_id' => $category->id,
            'severity'    => 5,
            'likelihood'  => 3,
            'phases' => [
                'proactive' => [
                    'preventive_action' => 'تدريب + فحص معدات',
                    'corrective_action' => 'شهادات + checklist',
                    'responsible_org_unit_text' => 'قسم التدريب (اقتراح)',
                    'responsible_user_text'     => 'مدرب السلامة (اقتراح)',
                    'cause_names'               => ['عدم تدريب', 'حبال غير مفحوصة'],
                    'affected_group_ids'        => [$worker->id],
                    'affected_impact'           => [$worker->id => 'high'],
                ],
                'operational' => [
                    'preventive_action' => 'إيقاف العمل وقت الرياح',
                    'corrective_action' => 'رفع العامل بأمان',
                ],
                'response' => [
                    'preventive_action' => 'خطة طوارئ جاهزة',
                    'corrective_action' => 'إسعاف أولي + تحقيق',
                    'affected_group_ids' => [$family->id],
                    'affected_impact'    => [$family->id => 'critical'],
                ],
            ],
        ])->assertRedirect(route('risk.master.index'));

        $master = Risk::where('risk_type', 'master')->firstOrFail();
        $this->assertSame(3, $master->phases()->count());

        // ─── STAGE 2 — Bulk copy from the book into the reference register ───
        $this->postJson(route('risk.bulkCopyFromMaster'), [
            'risk_ids' => [$master->id],
        ])->assertOk()->assertJsonPath('copied', 1);

        $reference = Risk::where('parent_reference_id', $master->id)->firstOrFail();
        $this->assertSame('reference', $reference->risk_type);
        $this->assertSame(3, $reference->phases()->count());

        // Phase content propagated.
        $refProactive = $reference->phases()->where('phase', 'proactive')->with('causes')->first();
        $this->assertSame('تدريب + فحص معدات', $refProactive->preventive_action);
        $this->assertCount(2, $refProactive->causes);

        // ─── STAGE 3 — Edit reference: link responsible unit + user ───
        $safetyUnit  = $this->orgUnit('hr');
        $coordinator = $this->makeUser('safety_coordinator', null, 'منسق السلامة');

        $this->post(route('risk.reference.update', $reference), [
            'category_id' => $reference->category_id,
            'sub_category_id' => $reference->sub_category_id,
            'severity'    => $reference->severity,
            'likelihood'  => $reference->likelihood,
            'phases' => [
                'proactive' => [
                    'preventive_action' => $refProactive->preventive_action,
                    'corrective_action' => $refProactive->corrective_action,
                    'responsible_org_unit_id' => $safetyUnit->id,
                    'responsible_user_id'     => $coordinator->id,
                    'cause_names'             => ['عدم تدريب', 'حبال غير مفحوصة'],
                    'affected_group_ids'      => [$worker->id],
                    'affected_impact'         => [$worker->id => 'high'],
                ],
                'operational' => ['preventive_action' => 'إيقاف العمل وقت الرياح'],
                'response'    => ['corrective_action' => 'إسعاف أولي + تحقيق'],
            ],
        ])->assertRedirect(route('risk.reference.index'));

        $refProactive->refresh();
        $this->assertSame($safetyUnit->id, $refProactive->responsible_org_unit_id);
        $this->assertSame($coordinator->id, $refProactive->responsible_user_id);
        // العنوان يبقى (لا فئة فرعية تُشتق منها).
        $this->assertSame('سقوط من ارتفاع', $reference->fresh()->title);

        // ─── STAGE 4 — Activate the reference → active risk ────
        $this->post(route('risk.activate', $reference), [
            'scope_type' => 'general',
            'severity'   => 5,
            'likelihood' => 4,
            'assigned_coordinator_id' => $coordinator->id,
        ])->assertRedirect(route('risk.active.index'));

        $active = Risk::where('risk_type', 'active')
            ->where('parent_reference_id', $reference->id)
            ->firstOrFail();
        $this->assertSame('active', $active->status);
        $this->assertSame(20, $active->risk_score);
        $this->assertSame($coordinator->id, $active->assigned_coordinator_id);

        // Active risk inherits all 3 phases with their content AND the
        // responsibility FKs (one institute, so they carry over).
        $activeProactive = $active->phases()->where('phase', 'proactive')->first();
        $this->assertSame('تدريب + فحص معدات', $activeProactive->preventive_action);
        $this->assertSame('شهادات + checklist', $activeProactive->corrective_action);
        $this->assertSame($safetyUnit->id, $activeProactive->responsible_org_unit_id);
        $this->assertSame($coordinator->id, $activeProactive->responsible_user_id);

        // ─── STAGE 5 — Full data-integrity sweep ──────────────────────
        // Nothing should be sitting on the legacy single-phase columns.
        $this->assertFalse(Schema::hasColumn('risks', 'corrective_action'));
        $this->assertFalse(Schema::hasColumn('risks', 'preventive_action'));
        $this->assertFalse(Schema::hasColumn('risks', 'owner_department'));
        $this->assertFalse(Schema::hasColumn('risks', 'owner_person'));
        $this->assertFalse(Schema::hasTable('risk_risk_causes'));
        $this->assertFalse(Schema::hasTable('risk_affected_groups'));
        $this->assertFalse(Schema::hasTable('risk_affected_group_details'));

        // Phase tables exist and are populated end-to-end.
        $this->assertTrue(Schema::hasTable('risk_phases'));
        $this->assertTrue(Schema::hasTable('risk_phase_causes'));
        $this->assertTrue(Schema::hasTable('risk_phase_affected_groups'));
        $this->assertTrue(Schema::hasTable('risk_phase_affected_group_details'));

        // Count check: master (3) + reference (3) + active (3) = 9 phase rows minimum.
        $this->assertGreaterThanOrEqual(9, RiskPhase::count());
    }
}
