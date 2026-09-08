<?php

namespace Tests\Feature\Risk;

use App\Models\User;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskPhase;
use App\Modules\Risk\Models\RiskSubCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * شجرة السجلين (reference + active) — منقول من OHSMS بلا tenant.
 * يغطي: الفئات، الفئات الفرعية، المخاطر بالفئة الفرعية، تفاصيل الخطر، وقيد المسار.
 */
class RegistryTreeApiTest extends TestCase
{
    use RefreshDatabase, RiskFixtures;

    private User $user;
    private RiskCategory $category;
    private RiskSubCategory $subCategory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->actingAsRole('system_admin');
        $this->category = $this->makeCategory();
        $this->subCategory = $this->makeSubCategory($this->category, 'فئة فرعية اختبار');
    }

    private function makeTypedRisk(string $type, array $extra = []): Risk
    {
        return $this->makeRisk(array_merge([
            'risk_type'       => $type,
            'category_id'     => $this->category->id,
            'sub_category_id' => $this->subCategory->id,
        ], $extra));
    }

    // ------------------------------------------------------------------
    // registryCategories
    // ------------------------------------------------------------------

    public function test_categories_returns_only_categories_with_reference_risks(): void
    {
        $this->makeTypedRisk('reference');
        // active risk in same category — should not affect reference result
        $this->makeTypedRisk('active');

        $r = $this->getJson(route('risk.registry.tree.categories', ['type' => 'reference']));

        $r->assertOk();
        $r->assertJsonCount(1);
        $r->assertJsonPath('0.id', $this->category->id);
    }

    public function test_categories_returns_only_categories_with_active_risks(): void
    {
        $this->makeTypedRisk('active');

        $anotherCategory = $this->makeCategory();
        // reference risk — should NOT appear in active categories
        $this->makeTypedRisk('reference', ['category_id' => $anotherCategory->id]);

        $r = $this->getJson(route('risk.registry.tree.categories', ['type' => 'active']));

        $r->assertOk();
        $ids = array_column($r->json(), 'id');
        $this->assertContains($this->category->id, $ids);
        $this->assertNotContains($anotherCategory->id, $ids);
    }

    // ------------------------------------------------------------------
    // registrySubCategories
    // ------------------------------------------------------------------

    public function test_sub_categories_filtered_by_category_and_type(): void
    {
        $this->makeTypedRisk('reference');

        $r = $this->getJson(route('risk.registry.tree.subCategories', [
            'type'       => 'reference',
            'categoryId' => $this->category->id,
        ]));

        $r->assertOk();
        $r->assertJsonCount(1);
        $r->assertJsonPath('0.id', $this->subCategory->id);
    }

    public function test_sub_categories_returns_empty_for_wrong_type(): void
    {
        $this->makeTypedRisk('reference');

        $r = $this->getJson(route('risk.registry.tree.subCategories', [
            'type'       => 'active',   // no active risks exist
            'categoryId' => $this->category->id,
        ]));

        $r->assertOk()->assertJsonCount(0);
    }

    // ------------------------------------------------------------------
    // registryRisksBySubCategory
    // ------------------------------------------------------------------

    public function test_risks_by_sub_category_returns_correct_risks(): void
    {
        $risk = $this->makeTypedRisk('reference', ['title' => 'خطر اختبار']);

        $r = $this->getJson(route('risk.registry.tree.risksBySubCategory', [
            'type'      => 'reference',
            'subCatId'  => $this->subCategory->id,
        ]));

        $r->assertOk()->assertJsonCount(1);
        $r->assertJsonPath('0.id', $risk->id);
        $r->assertJsonPath('0.title', 'خطر اختبار');
    }

    // ------------------------------------------------------------------
    // registryRiskDetail
    // ------------------------------------------------------------------

    public function test_detail_returns_risk_with_phases_in_order(): void
    {
        $risk = $this->makeTypedRisk('reference');
        RiskPhase::create(['risk_id' => $risk->id, 'phase' => RiskPhase::PHASE_RESPONSE]);
        RiskPhase::create(['risk_id' => $risk->id, 'phase' => RiskPhase::PHASE_PROACTIVE]);
        RiskPhase::create(['risk_id' => $risk->id, 'phase' => RiskPhase::PHASE_OPERATIONAL]);

        $r = $this->getJson(route('risk.registry.tree.riskDetail', [
            'type'   => 'reference',
            'riskId' => $risk->id,
        ]));

        $r->assertOk();
        $r->assertJsonPath('id', $risk->id);
        $phases = $r->json('phases');
        $this->assertCount(3, $phases);
        $this->assertSame(['proactive', 'operational', 'response'], array_column($phases, 'phase'));
    }

    public function test_detail_returns_status_and_scope_type_for_active_risk(): void
    {
        $risk = $this->makeTypedRisk('active', ['status' => 'active', 'scope_type' => 'general']);

        $r = $this->getJson(route('risk.registry.tree.riskDetail', [
            'type'   => 'active',
            'riskId' => $risk->id,
        ]));

        $r->assertOk();
        $r->assertJsonPath('status', 'active');
        $r->assertJsonPath('scope_type', 'general');
        $this->assertNotNull($r->json('status_label'));
    }

    public function test_detail_returns_404_for_wrong_type(): void
    {
        $risk = $this->makeTypedRisk('reference');

        // Requesting as 'active' even though the risk is 'reference'
        $r = $this->getJson(route('risk.registry.tree.riskDetail', [
            'type'   => 'active',
            'riskId' => $risk->id,
        ]));

        $r->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Route constraint — invalid type must 404 (not 500)
    // ------------------------------------------------------------------

    public function test_invalid_type_segment_returns_404(): void
    {
        $r = $this->getJson('/app/risk/registry/tree/master/categories');

        $r->assertNotFound();
    }
}
