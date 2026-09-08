<?php

namespace Tests\Feature\Incident;

use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Incident\Models\Incident;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskPhase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * إنشاء بلاغ بـ risk_id يملأ الحقول الموروثة آلياً (الوحدة، المكان، المنسق، الفني، الإجراءان)
 * من صف الخطر — يفرضه IncidentObserver لا خدمة ولا متحكم.
 */
class IncidentRiskInheritanceTest extends TestCase
{
    use RefreshDatabase, IncidentFixtures;

    private OrganizationUnit $unit;
    private User $coordinator;
    private User $fieldTeam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->unit = $this->makeUnit('maint', 'قسم الصيانة');
        $this->coordinator = $this->makeUser('safety_coordinator');
        $this->fieldTeam = $this->makeUser('field_worker');
    }

    private function makeRoutedRisk(): Risk
    {
        $risk = $this->makeRisk([
            'organization_unit_id' => $this->unit->id,
            'place_id' => $this->placeId('HZ-06'),
            'assigned_coordinator_id' => $this->coordinator->id,
            'assigned_field_team_id' => $this->fieldTeam->id,
        ]);

        // الإجراءات على المرحلة الاستباقية — المراقب يسحبها منها عند الإنشاء
        RiskPhase::create([
            'risk_id'           => $risk->id,
            'phase'             => RiskPhase::PHASE_PROACTIVE,
            'corrective_action' => 'إيقاف + عزل + إبلاغ',
            'preventive_action' => 'تدريب دوري + صيانة وقائية',
        ]);
        RiskPhase::create(['risk_id' => $risk->id, 'phase' => RiskPhase::PHASE_OPERATIONAL]);
        RiskPhase::create(['risk_id' => $risk->id, 'phase' => RiskPhase::PHASE_RESPONSE]);

        return $risk;
    }

    public function test_creating_incident_with_risk_inherits_all_routing_fields(): void
    {
        $risk = $this->makeRoutedRisk();

        $incident = Incident::create([
            'title' => 'اختبار التوريث',
            'description' => 'مسار الفحص',
            'incident_type' => 'normal',
            'risk_id' => $risk->id,
        ]);

        $this->assertSame($this->unit->id, $incident->organization_unit_id);
        $this->assertSame($this->placeId('HZ-06'), $incident->place_id); // إضافة المعهد: المكان يُورَّث أيضاً
        $this->assertSame($this->coordinator->id, $incident->incident_coordinator_id);
        $this->assertSame($this->fieldTeam->id, $incident->incident_field_team_id);
        $this->assertSame('إيقاف + عزل + إبلاغ', $incident->corrective_action);
        $this->assertSame('تدريب دوري + صيانة وقائية', $incident->preventive_action);
    }

    public function test_creating_incident_without_risk_id_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Incident creation requires risk_id');

        Incident::create([
            'title' => 'بلاغ بلا خطر',
            'description' => '...',
            'incident_type' => 'normal',
        ]);
    }

    public function test_secret_incident_is_allowed_without_risk_id(): void
    {
        $incident = Incident::create([
            'title' => 'بلاغ سري بلا خطر',
            'description' => '...',
            'incident_type' => 'secret',
            'secret_key' => 'abc123',
            'secret_tracking_code' => 'TRACK-1',
        ]);

        $this->assertSame('new', $incident->status);
        $this->assertNull($incident->risk_id);
    }

    public function test_invalid_risk_id_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not point to an existing Risk row');

        Incident::create([
            'title' => 'اختبار',
            'description' => '...',
            'incident_type' => 'normal',
            'risk_id' => 99999,
        ]);
    }

    public function test_manual_override_is_preserved_over_risk_inheritance(): void
    {
        $risk = $this->makeRoutedRisk();
        $otherCoordinator = $this->makeUser('safety_coordinator');

        $incident = Incident::create([
            'title' => 'override',
            'description' => '...',
            'incident_type' => 'normal',
            'risk_id' => $risk->id,
            'incident_coordinator_id' => $otherCoordinator->id,
            'corrective_action' => 'إجراء مخصص',
        ]);

        // التحديد اليدوي يغلب التوريث
        $this->assertSame($otherCoordinator->id, $incident->incident_coordinator_id);
        $this->assertSame('إجراء مخصص', $incident->corrective_action);
        // وما لم يُحدَّد يُورَّث من الخطر
        $this->assertSame($this->fieldTeam->id, $incident->incident_field_team_id);
        $this->assertSame('تدريب دوري + صيانة وقائية', $incident->preventive_action);
    }

    public function test_risk_relation_loads_linked_risk(): void
    {
        $risk = $this->makeRoutedRisk();
        $incident = Incident::create([
            'title' => 'relation',
            'description' => '...',
            'incident_type' => 'normal',
            'risk_id' => $risk->id,
        ]);

        $this->assertNotNull($incident->risk);
        $this->assertSame($risk->id, $incident->risk->id);
    }
}
