<?php

namespace Tests\Feature\Incident;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Incident\Models\Incident;
use App\Modules\Incident\Services\IncidentVisibilityService;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٢١-٦: من يرى بلاغ الشاغل — البلاغ يراه من يعنيه (قرارا ٥٤ و٥٥).
 * مدير الفرع يرى بلاغات أماكن فرعه؛ الفني يرى بلاغات الأماكن التي يغطيها (لا مكان حسابه وحده)؛
 * وفي المكاتب الإدارية، حيث الإدارات كلها في مكان واحد، الرؤية بالإدارة لا بالمكان — فلا يرى فني ولا منسق مكانه المكاتب بلاغ إدارة أخرى.
 */
class VisibilityScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
    }

    private function user(string $username, string $role, array $profile = [], array $coverage = []): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        $p = UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true] + $profile);
        if ($coverage) $p->coverage()->sync(array_map(fn ($c) => Place::idByCode($c), $coverage));
        return $u;
    }

    private function incident(string $placeCode, ?string $unitCode = null): Incident
    {
        return Incident::create(['title' => "بلاغ في $placeCode", 'description' => 'x', 'incident_type' => 'normal', 'status' => 'new',
            'place_id' => Place::idByCode($placeCode), 'organization_unit_id' => $unitCode ? OrganizationUnit::where('code', $unitCode)->value('id') : null]);
    }

    private function sees(User $u, Incident $i): bool
    {
        $v = app(IncidentVisibilityService::class);
        $one = $v->canView($i, $u->id);
        $this->assertSame($one, $v->getVisibleIncidents($u->id)->whereKey($i->id)->exists(), 'القائمة والفتح مختلفان لـ'.$u->username);
        return $one;
    }

    public function test_branch_manager_sees_reports_of_his_branch_places_only(): void
    {
        $main = EmergencyBuilding::main();
        $other = EmergencyBuilding::create(['code' => 'DMM-1', 'name' => 'مبنى فرع الدمام', 'branch' => 'الدمام']);
        $riyadh = $this->user('far3.r', 'branch_manager', ['building_id' => $main->id]);
        $dammam = $this->user('far3.d', 'branch_manager', ['building_id' => $other->id]);
        $i = $this->incident('HZ-02', 'adm-eng');
        $this->assertTrue($this->sees($riyadh, $i), 'مدير فرع الرياض لا يرى بلاغاً في مبنى فرعه');
        $this->assertFalse($this->sees($dammam, $i), 'مدير فرع الدمام يرى بلاغ الرياض');
    }

    public function test_technician_sees_reports_of_places_he_covers(): void
    {
        $tech = $this->user('kahraba', 'tech_electrical', [], ['HZ-02', 'HZ-03']); // حسابه بلا مكان؛ تغطيته غرف الكهرباء والتكييف
        $this->assertTrue($this->sees($tech, $this->incident('HZ-02')), 'الفني لا يرى بلاغ مكان يغطيه');
        $this->assertFalse($this->sees($tech, $this->incident('HZ-08')));
    }

    public function test_in_the_offices_visibility_is_by_department_not_by_place(): void
    {
        $i = $this->incident('HZ-06', 'hr'); // بلاغ إدارة الموارد البشرية في المكاتب
        $techOffices = $this->user('fani06', 'tech_electrical', ['place_id' => Place::idByCode('HZ-06')], ['HZ-06']);
        $coordFin = $this->user('fin.c', 'safety_coordinator', ['organization_unit_id' => OrganizationUnit::where('code', 'fin')->value('id'), 'place_id' => Place::idByCode('HZ-06')]);
        $coordHr = $this->user('hr.c', 'safety_coordinator', ['organization_unit_id' => OrganizationUnit::where('code', 'hr')->value('id')]);
        $this->assertFalse($this->sees($techOffices, $i), 'فني المكاتب يرى بلاغ إدارة لا يعنيه');
        $this->assertFalse($this->sees($coordFin, $i), 'منسق المالية يرى بلاغ الموارد البشرية لأن مكان حسابه المكاتب');
        $this->assertTrue($this->sees($coordHr, $i));
        // ومن أُحيل إليه يراه أياً كان
        $i->update(['incident_field_team_id' => $techOffices->id]);
        $this->assertTrue($this->sees($techOffices, $i->fresh()));
    }
}
