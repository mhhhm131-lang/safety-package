<?php

namespace Tests\Feature\Risk;

use App\Models\User;
use App\Modules\Risk\Models\AffectedGroup;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskSubCategory;
use App\Modules\Risk\Services\RiskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 8 feature test — reference-register create/update must persist
 * phase data with FK pickers (OrgUnit/User) plus free-text fallback.
 * المعهد: عنوان الخطر المرجعي يُشتق من الفئة الفرعية المختارة.
 */
class RiskReferenceWithPhasesTest extends TestCase
{
    use RefreshDatabase, RiskFixtures;

    private User $admin;
    private RiskCategory $category;
    private RiskSubCategory $subCategory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->actingAsRole('system_admin');
        $this->category = $this->makeCategory('مخاطر الحريق');
        $this->subCategory = $this->makeSubCategory($this->category, 'الأعمال الساخنة');
    }

    private function basePayload(): array
    {
        return ['category_id' => $this->category->id, 'sub_category_id' => $this->subCategory->id];
    }

    public function test_reference_store_persists_fk_responsibility(): void
    {
        $unit = $this->orgUnit('hr');
        $responsibleUser = $this->makeUser('safety_coordinator', null, 'مسؤول السلامة');

        $response = $this->post(route('risk.reference.store'), $this->basePayload() + [
            'severity'    => 3,
            'likelihood'  => 4,
            'phases' => [
                'proactive' => [
                    'preventive_action' => 'تدريب دوري',
                    'responsible_org_unit_id' => $unit->id,
                    'responsible_user_id'     => $responsibleUser->id,
                    'responsible_user_text'   => 'مسؤول احتياطي',
                ],
                'operational' => [],
                'response'    => ['corrective_action' => 'استدعاء الطوارئ'],
            ],
        ]);
        $response->assertRedirect(route('risk.reference.index'));

        $risk = Risk::where('risk_type', 'reference')->latest('id')->firstOrFail();
        $this->assertSame('reference', $risk->risk_type);
        $this->assertSame('الأعمال الساخنة', $risk->title);
        $this->assertSame(12, $risk->risk_score);

        $proactive = $risk->phases()->where('phase', 'proactive')->first();
        $this->assertSame($unit->id, $proactive->responsible_org_unit_id);
        $this->assertSame($responsibleUser->id, $proactive->responsible_user_id);
        $this->assertSame('مسؤول احتياطي', $proactive->responsible_user_text);

        // FK-preferred accessor: returns the linked unit's name (not the text fallback).
        $this->assertSame($unit->name, $proactive->fresh(['responsibleOrgUnit'])->responsible_org_unit_display);
        // User accessor: returns the linked user's name.
        $this->assertSame('مسؤول السلامة', $proactive->fresh(['responsibleUser'])->responsible_user_display);

        $responsePhase = $risk->phases()->where('phase', 'response')->first();
        $this->assertSame('استدعاء الطوارئ', $responsePhase->corrective_action);
    }

    public function test_reference_store_falls_back_to_free_text_when_no_fk(): void
    {
        $response = $this->post(route('risk.reference.store'), $this->basePayload() + [
            'severity'    => 2,
            'likelihood'  => 2,
            'phases' => [
                'proactive' => [
                    'responsible_org_unit_text' => 'قسم لم يُنشأ بعد',
                    'responsible_user_text'     => 'شخص من خارج النظام',
                ],
                'operational' => [],
                'response'    => [],
            ],
        ]);
        $response->assertRedirect(route('risk.reference.index'));

        $risk = Risk::where('risk_type', 'reference')->latest('id')->firstOrFail();
        $proactive = $risk->phases()->where('phase', 'proactive')->first();
        $this->assertNull($proactive->responsible_org_unit_id);
        $this->assertNull($proactive->responsible_user_id);
        $this->assertSame('قسم لم يُنشأ بعد', $proactive->responsible_org_unit_text);
        $this->assertSame('شخص من خارج النظام', $proactive->responsible_user_text);
    }

    public function test_reference_edit_ensures_phases_for_legacy_risk(): void
    {
        // Legacy reference risk created before phase migration — no phase rows.
        $risk = $this->makeRisk([
            'risk_type'   => 'reference',
            'category_id' => $this->category->id,
        ]);
        $this->assertSame(0, $risk->phases()->count());

        $response = $this->get(route('risk.reference.edit', $risk));
        $response->assertOk();

        $this->assertSame(3, $risk->phases()->count());
    }

    public function test_reference_update_replaces_phase_pivots(): void
    {
        $risk = $this->makeRisk([
            'risk_type'   => 'reference',
            'category_id' => $this->category->id,
        ]);
        $this->riskService()->ensurePhases($risk);

        // Seed the proactive phase with two existing affected-group links.
        $groupA = AffectedGroup::create(['name' => 'مجموعة أ']);
        $groupB = AffectedGroup::create(['name' => 'مجموعة ب']);
        $proactive = $risk->phases()->where('phase', 'proactive')->first();
        $proactive->affectedGroups()->sync([$groupA->id, $groupB->id]);

        // Submit an update that keeps only group A.
        $response = $this->post(route('risk.reference.update', $risk), [
            'category_id' => $risk->category_id,
            'severity'    => $risk->severity,
            'likelihood'  => $risk->likelihood,
            'phases' => [
                'proactive' => [
                    'affected_group_ids' => [$groupA->id],
                    'affected_impact'    => [$groupA->id => 'critical'],
                ],
                'operational' => [],
                'response'    => [],
            ],
        ]);
        $response->assertRedirect(route('risk.reference.index'));

        $proactive->refresh();
        $this->assertSame([$groupA->id], $proactive->affectedGroups->pluck('id')->all());
        $detail = $proactive->affectedGroupDetails->firstWhere('affected_group_id', $groupA->id);
        $this->assertSame('critical', $detail->impact);
        // العنوان الأصلي يبقى عندما لا تُرسل فئة فرعية.
        $this->assertSame('خطر اختبار', $risk->fresh()->title);
    }

    private function riskService(): RiskService
    {
        return app(RiskService::class);
    }
}
