<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Services\PanicAlertService;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Incident\Models\Incident;
use App\Modules\Incident\Services\IncidentEmergencyBridge;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مرحلة الفروع — الخطوة ٤ (قرار ٧٨): ما كان يفترض «المبنى الرئيسي» يأخذ مبنى المكان أو الحساب —
 * الحالة الطارئة من بلاغ عاجل على مبنى مكان البلاغ (وحالة الملز المفتوحة لا تمنعها)، والذعر والزوار بمبنى صاحبهما.
 */
class BranchesStep4Test extends TestCase
{
    use RefreshDatabase;

    private EmergencyBuilding $main;
    private EmergencyBuilding $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
        $this->main = EmergencyBuilding::main();
        $branch = OrganizationUnit::create(['code' => 'dmm', 'name' => 'فرع الشرقية', 'unit_type' => 'branch']);
        $this->b = EmergencyBuilding::create(['code' => 'DMM', 'name' => 'فرع الشرقية — المبنى الرئيسي', 'branch' => 'فرع الشرقية', 'branch_unit_id' => $branch->id]);
        Place::createCategoriesFor($this->b);
    }

    private function bp(string $cat): Place
    {
        return Place::where('building_id', $this->b->id)->where('category', $cat)->firstOrFail();
    }

    private function user(string $username, string $role, ?EmergencyBuilding $building = null, ?Place $place = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234']);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'building_id' => $building?->id, 'place_id' => $place?->id]);
        return $u;
    }

    private function openEmergency(EmergencyBuilding $building, Place $place, string $code): EmergencyIncident
    {
        return EmergencyIncident::create(['building_id' => $building->id, 'place_id' => $place->id, 'incident_code' => $code, 'incident_type' => 'fire', 'status' => 'active', 'is_drill' => false, 'triggered_at' => now()]);
    }

    public function test_urgent_incident_in_a_branch_opens_an_emergency_on_its_building_even_while_malaz_has_one_open(): void
    {
        $this->openEmergency($this->main, Place::find(Place::idByCode('HZ-01')), 'ط-9001');
        $salama = $this->user('salama', 'system_admin');
        $inc = Incident::create(['code' => 'ش-0101', 'title' => 'دخان', 'description' => 'دخان في القبو', 'incident_type' => 'urgent', 'status' => 'new', 'place_id' => $this->bp('HZ-01')->id]);

        $em = app(IncidentEmergencyBridge::class)->trigger($inc, $salama, 'fire');
        $this->assertSame($this->b->id, $em->building_id, 'الحالة فُتحت على الملز لا على مبنى البلاغ');
        $this->assertSame($this->bp('HZ-01')->id, $em->place_id);
        $this->assertSame(2, EmergencyIncident::open()->count());

        // حالة مفتوحة في مبنى البلاغ نفسه تمنع حالة ثانية فيه (كما كان في المبنى الواحد)
        $inc2 = Incident::create(['code' => 'ش-0102', 'title' => 'دخان', 'description' => 'دخان آخر', 'incident_type' => 'urgent', 'status' => 'new', 'place_id' => $this->bp('HZ-02')->id]);
        try {
            app(IncidentEmergencyBridge::class)->trigger($inc2, $salama, 'fire');
            $this->fail('فُتحت حالة ثانية في مبنى فيه حالة مفتوحة');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('مفتوحة', $e->getMessage());
        }
        $this->assertSame(2, EmergencyIncident::open()->count());
    }

    public function test_panic_alert_takes_the_sender_building(): void
    {
        $fani = $this->user('fani.b', 'field_worker', $this->b, $this->bp('HZ-01'));
        $alert = app(PanicAlertService::class)->trigger($fani, ['message' => 'نجدة']);
        $this->assertSame($this->b->id, $alert->building_id, 'تنبيه الذعر نُسب إلى الملز');
        $this->assertSame($this->bp('HZ-01')->id, $alert->place_id);

        $employee = $this->user('mowathaf', 'employee', null, Place::find(Place::idByCode('HZ-06')));
        $this->assertSame($this->main->id, app(PanicAlertService::class)->trigger($employee, ['message' => 'نجدة'])->building_id);
    }

    public function test_visitor_check_in_defaults_to_the_host_building(): void
    {
        $duty = $this->user('munawib.b', 'system_staff', $this->b, $this->bp('HZ-00'));
        $this->actingAs($duty);
        $r = $this->postJson('/api/emergency/visitors/check-in', ['name' => 'زائر الشرقية', 'place_id' => $this->bp('HZ-06')->id]);
        $r->assertCreated();
        $this->assertSame($this->b->id, (int) \App\Modules\Emergency\Models\EmergencyVisitor::where('name', 'زائر الشرقية')->value('building_id'), 'الزائر نُسب إلى الملز');
    }

    public function test_emergency_center_follows_the_session_building(): void
    {
        $this->openEmergency($this->b, $this->bp('HZ-01'), 'ط-9002');
        $salama = $this->user('salama', 'system_admin');
        $this->actingAs($salama);
        $this->get(route('emergency.dashboard'))->assertOk()->assertSee('ط-9002');
        $this->post(route('app.building.switch', $this->b))->assertRedirect();
        $this->get(route('emergency.dashboard'))->assertOk()->assertSee($this->b->name)->assertSee('ط-9002');

        $munawibB = $this->user('munawib.b', 'system_staff', $this->b, $this->bp('HZ-00'));
        $this->actingAs($munawibB);
        $this->get(route('emergency.dashboard'))->assertOk()->assertSee('ط-9002');
    }
}
