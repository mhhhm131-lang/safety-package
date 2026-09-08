<?php

namespace Tests\Feature\Risk;

use App\Modules\Risk\Models\AffectedGroup;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskPhase;
use App\Modules\Risk\Services\RiskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 6 feature test — master-edit view pre-fills phase data and the
 * update endpoint persists per-phase changes independently.
 * المعهد: كتاب المخاطر يملكه مسؤول السلامة (system_admin) لا superuser.
 */
class RiskMasterEditWithPhasesTest extends TestCase
{
    use RefreshDatabase, RiskFixtures;

    private function makeMasterRisk(): Risk
    {
        $category = $this->makeCategory();

        $risk = app(RiskService::class)->createRisk(auth()->id(), [
            'title'       => 'سقوط من ارتفاع',
            'description' => 'خطر يحتاج تعديل مراحل',
            'category_id' => $category->id,
            'severity'    => 4,
            'likelihood'  => 3,
        ], 'master');

        return $risk->fresh();
    }

    public function test_get_edit_view_pre_fills_phase_fields(): void
    {
        $this->actingAsRole('system_admin');
        $risk = $this->makeMasterRisk();

        // Pre-populate one phase with data to verify it renders back into the form.
        $proactive = $risk->phases()->where('phase', RiskPhase::PHASE_PROACTIVE)->first();
        $proactive->update([
            'preventive_action' => 'التدريب المسبق',
            'responsible_org_unit_text' => 'قسم التدريب',
        ]);

        $response = $this->get(route('risk.master.edit', $risk));

        $response->assertOk();
        $response->assertSeeText('تعديل الخطر الرئيسي');
        // Pre-filled values should appear in the rendered HTML.
        $response->assertSee('التدريب المسبق', false);
        $response->assertSee('قسم التدريب', false);
    }

    public function test_update_replaces_phase_content(): void
    {
        $this->actingAsRole('system_admin');
        $risk = $this->makeMasterRisk();

        $worker = AffectedGroup::create(['name' => 'العامل']);

        $payload = [
            'title'       => $risk->title,
            'description' => $risk->description,
            'category_id' => $risk->category_id,
            'severity'    => 5,
            'likelihood'  => 4,
            'phases' => [
                'proactive' => [
                    'preventive_action' => 'فحص دوري للمعدات',
                    'corrective_action' => 'استبدال المعدات التالفة',
                    'responsible_org_unit_text' => 'قسم الصيانة',
                    'responsible_user_text'     => 'مهندس الصيانة',
                    'cause_names'               => ['إهمال الصيانة'],
                    'affected_group_ids'        => [$worker->id],
                    'affected_impact'           => [$worker->id => 'high'],
                ],
                'operational' => [
                    'corrective_action' => 'إيقاف العمل فوراً',
                ],
                'response' => [
                    'corrective_action' => 'تحقيق في السبب',
                ],
            ],
        ];

        $response = $this->post(route('risk.master.update', $risk), $payload);
        $response->assertRedirect(route('risk.master.index'));

        $risk->refresh();
        $this->assertSame(20, $risk->risk_score);

        $proactive = $risk->phases()->where('phase', 'proactive')->with('causes', 'affectedGroups', 'affectedGroupDetails')->first();
        $this->assertSame('فحص دوري للمعدات', $proactive->preventive_action);
        $this->assertSame('قسم الصيانة', $proactive->responsible_org_unit_text);
        $this->assertSame(['إهمال الصيانة'], $proactive->causes->pluck('name')->all());
        $this->assertCount(1, $proactive->affectedGroups);
        $this->assertSame('high', $proactive->affectedGroupDetails->first()->impact);

        $operational = $risk->phases()->where('phase', 'operational')->first();
        $this->assertSame('إيقاف العمل فوراً', $operational->corrective_action);

        $response = $risk->phases()->where('phase', 'response')->first();
        $this->assertSame('تحقيق في السبب', $response->corrective_action);
    }

    public function test_update_on_legacy_risk_without_phases_creates_them(): void
    {
        $this->actingAsRole('system_admin');

        $category = $this->makeCategory();
        // Legacy risk: bypass the service so ensurePhases is NOT called at creation.
        $risk = $this->makeRisk([
            'risk_type'   => 'master',
            'category_id' => $category->id,
            'title'       => 'خطر قديم بلا مراحل',
        ]);
        $this->assertSame(0, $risk->phases()->count());

        $response = $this->get(route('risk.master.edit', $risk));
        $response->assertOk();

        // Opening the edit view should have ensured the three phases exist.
        $this->assertSame(3, $risk->phases()->count());
    }
}
