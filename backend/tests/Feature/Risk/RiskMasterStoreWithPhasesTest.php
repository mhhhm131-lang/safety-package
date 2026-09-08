<?php

namespace Tests\Feature\Risk;

use App\Modules\Risk\Models\AffectedGroup;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskPhase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 5 feature test — POSTing the master-create form with phase data
 * must (a) create a master risk, (b) populate all three phase rows with
 * their own actions/responsibility/causes/affected-groups, and (c) stay
 * closed to roles without risk.create.
 * المعهد: كتاب المخاطر يملكه مسؤول السلامة (system_admin) لا superuser.
 */
class RiskMasterStoreWithPhasesTest extends TestCase
{
    use RefreshDatabase, RiskFixtures;

    private function masterCategory(): RiskCategory
    {
        return $this->makeCategory();
    }

    private function masterAffectedGroup(string $name): AffectedGroup
    {
        return AffectedGroup::create(['name' => $name]);
    }

    public function test_post_creates_master_risk_with_three_populated_phases(): void
    {
        $this->actingAsRole('system_admin');
        $category = $this->masterCategory();
        $worker = $this->masterAffectedGroup('العامل');
        $family = $this->masterAffectedGroup('الأسرة');

        $payload = [
            'title'       => 'سقوط من مبنى عالٍ أثناء تنظيف الزجاج',
            'description' => 'خطر سقوط عامل التنظيف من ارتفاع.',
            'category_id' => $category->id,
            'severity'    => 5,
            'likelihood'  => 3,
            'phases'      => [
                'proactive' => [
                    'preventive_action'         => 'تدريب العمال على الارتفاعات',
                    'corrective_action'         => 'فحص الحبال دورياً',
                    'responsible_org_unit_text' => 'قسم التدريب',
                    'responsible_user_text'     => 'مدرب السلامة',
                    'cause_names'               => ['عدم تدريب', 'حبال غير مفحوصة'],
                    'affected_group_ids'        => [$worker->id],
                    'affected_impact'           => [$worker->id => 'high'],
                    'affected_rep_scope'        => [$worker->id => 'local'],
                ],
                'operational' => [
                    'preventive_action' => 'إيقاف العمل عند الرياح',
                    'corrective_action' => 'رفع العامل بأمان',
                    'responsible_org_unit_text' => 'قسم السلامة',
                ],
                'response' => [
                    'corrective_action'     => 'إسعاف أولي + تحقيق',
                    'responsible_user_text' => 'قائد الطوارئ',
                    'affected_group_ids'    => [$family->id],
                    'affected_impact'       => [$family->id => 'critical'],
                ],
            ],
        ];

        $response = $this->post(route('risk.master.store'), $payload);
        $response->assertRedirect(route('risk.master.index'));

        $risk = Risk::where('title', $payload['title'])->firstOrFail();
        $this->assertSame('master', $risk->risk_type);
        $this->assertSame(15, $risk->risk_score);
        $this->assertCount(3, $risk->phases);

        $proactive = $risk->phases->firstWhere('phase', RiskPhase::PHASE_PROACTIVE);
        $this->assertSame('تدريب العمال على الارتفاعات', $proactive->preventive_action);
        $this->assertSame('قسم التدريب', $proactive->responsible_org_unit_text);
        $this->assertSame('مدرب السلامة', $proactive->responsible_user_text);
        $this->assertEqualsCanonicalizing(
            ['عدم تدريب', 'حبال غير مفحوصة'],
            $proactive->causes->pluck('name')->all()
        );
        $this->assertCount(1, $proactive->affectedGroups);
        $proactiveDetail = $proactive->affectedGroupDetails->first();
        $this->assertSame('high', $proactiveDetail->impact);
        $this->assertSame('local', $proactiveDetail->rep_scope);

        $response = $risk->phases->firstWhere('phase', RiskPhase::PHASE_RESPONSE);
        $this->assertSame('إسعاف أولي + تحقيق', $response->corrective_action);
        $this->assertSame('قائد الطوارئ', $response->responsible_user_text);
        $this->assertCount(1, $response->affectedGroups);
        $this->assertSame('critical', $response->affectedGroupDetails->first()->impact);
    }

    public function test_post_with_no_phase_input_still_creates_three_empty_phases(): void
    {
        $this->actingAsRole('system_admin');
        $category = $this->masterCategory();

        $response = $this->post(route('risk.master.store'), [
            'title'       => 'خطر بسيط بلا مراحل مُعبّأة',
            'description' => 'الخطر موجود لكن المحتوى يُملأ لاحقاً.',
            'category_id' => $category->id,
            'severity'    => 2,
            'likelihood'  => 2,
        ]);
        $response->assertRedirect(route('risk.master.index'));

        $risk = Risk::where('title', 'خطر بسيط بلا مراحل مُعبّأة')->firstOrFail();
        $this->assertCount(3, $risk->phases);
        foreach ($risk->phases as $phase) {
            $this->assertNull($phase->preventive_action);
            $this->assertNull($phase->corrective_action);
        }
    }

    public function test_get_create_view_renders_without_errors(): void
    {
        $this->actingAsRole('system_admin');

        // Seed a master category so the select has an option to render.
        $this->masterCategory();

        $response = $this->get(route('risk.master.create'));

        $response->assertOk();
        $response->assertSeeText('إضافة خطر في كتاب المخاطر');
        // The three phase tabs must be present.
        $response->assertSeeText('استباقي');
        $response->assertSeeText('تشغيلي');
        $response->assertSeeText('استجابة');
    }

    public function test_non_superuser_cannot_post_master_create(): void
    {
        // المعهد: الكتاب يُعدّله من يحمل risk.create فقط — مدير الإدارة والإدارة العليا يُرفضان.
        $category = $this->masterCategory();

        foreach (['department_manager', 'top_management'] as $role) {
            $this->actingAsRole($role, $role === 'department_manager' ? 'it' : null);

            $response = $this->post(route('risk.master.store'), [
                'title'       => 'محاولة من غير المالك',
                'description' => 'يجب أن تُرفض.',
                'category_id' => $category->id,
                'severity'    => 1,
                'likelihood'  => 1,
            ]);

            $response->assertForbidden();
        }
        $this->assertDatabaseMissing('risks', ['title' => 'محاولة من غير المالك']);
    }
}
