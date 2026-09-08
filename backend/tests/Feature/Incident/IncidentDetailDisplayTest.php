<?php

namespace Tests\Feature\Incident;

use App\Modules\Incident\Models\Incident;
use App\Modules\Risk\Models\RiskPhase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صفحة تفاصيل البلاغ تعرض الحقول الموروثة من الخطر المرتبط: المنسق، الفني،
 * الإجراءان التصحيحي والوقائي، ومسار الخطر.
 */
class IncidentDetailDisplayTest extends TestCase
{
    use RefreshDatabase, IncidentFixtures;

    public function test_detail_view_shows_risk_inherited_fields(): void
    {
        $unit = $this->makeUnit('test-prod', 'قسم الإنتاج التجريبي');
        $coordinator = $this->makeUser('safety_coordinator', null, 'منسق التجربة');
        $fieldTeam = $this->makeUser('field_worker', null, 'فريق التجربة');

        $risk = $this->makeRisk([
            'organization_unit_id' => $unit->id,
            'assigned_coordinator_id' => $coordinator->id,
            'assigned_field_team_id' => $fieldTeam->id,
            'title' => 'انكسار قطعة من الآلة',
            'code' => 'TEST-RISK-001',
        ]);

        // الإجراءات على المرحلة الاستباقية — المراقب يسحبها منها عند الإنشاء
        RiskPhase::create([
            'risk_id'           => $risk->id,
            'phase'             => RiskPhase::PHASE_PROACTIVE,
            'corrective_action' => 'إيقاف الآلة فوراً وعزل المنطقة',
            'preventive_action' => 'صيانة دورية شهرية وفحص الواقيات',
        ]);
        RiskPhase::create(['risk_id' => $risk->id, 'phase' => RiskPhase::PHASE_OPERATIONAL]);
        RiskPhase::create(['risk_id' => $risk->id, 'phase' => RiskPhase::PHASE_RESPONSE]);

        $incident = Incident::create([
            'risk_id' => $risk->id,
            'title' => 'حادث الاختبار',
            'description' => 'وصف حادث الاختبار',
            'incident_type' => 'normal',
        ]);

        $this->actingAsRole('system_admin');

        $response = $this->get(route('incidents.show', $incident));
        $response->assertOk()
            ->assertSee('الخطر المرتبط', false)
            ->assertSee('TEST-RISK-001', false)
            ->assertSee('انكسار قطعة من الآلة', false)
            ->assertSee('قسم الإنتاج التجريبي', false)
            ->assertSee('المنسق:', false)
            ->assertSee('منسق التجربة', false)
            ->assertSee('الفني:', false)
            ->assertSee('فريق التجربة', false)
            ->assertSee('إيقاف الآلة فوراً وعزل المنطقة', false)
            ->assertSee('صيانة دورية شهرية وفحص الواقيات', false);
    }

    public function test_admin_can_update_incident_actions(): void
    {
        $risk = $this->makeRisk();
        RiskPhase::create([
            'risk_id'           => $risk->id,
            'phase'             => RiskPhase::PHASE_PROACTIVE,
            'corrective_action' => 'الأصلي',
            'preventive_action' => 'الأصلي',
        ]);
        RiskPhase::create(['risk_id' => $risk->id, 'phase' => RiskPhase::PHASE_OPERATIONAL]);
        RiskPhase::create(['risk_id' => $risk->id, 'phase' => RiskPhase::PHASE_RESPONSE]);

        $incident = $this->makeIncident(['risk_id' => $risk->id]);

        $this->actingAsRole('system_admin');

        $this->put(route('incidents.updateActions', $incident), [
            'corrective_action' => 'مخصَّص لهذا البلاغ',
            'preventive_action' => 'مخصَّص أيضاً',
        ])->assertRedirect(route('incidents.show', $incident));

        $this->assertDatabaseHas('incidents', [
            'id' => $incident->id,
            'corrective_action' => 'مخصَّص لهذا البلاغ',
            'preventive_action' => 'مخصَّص أيضاً',
        ]);

        // المرحلة الاستباقية للخطر لا تُمس — تعديل إجراءات البلاغ لا يتسرب إلى مصدره
        $this->assertDatabaseHas('risk_phases', [
            'risk_id'           => $risk->id,
            'phase'             => 'proactive',
            'corrective_action' => 'الأصلي',
            'preventive_action' => 'الأصلي',
        ]);
    }
}
