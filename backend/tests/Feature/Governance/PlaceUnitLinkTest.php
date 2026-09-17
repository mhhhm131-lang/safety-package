<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyTeam;
use App\Modules\Emergency\Services\TeamSync;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\PlaceUnit;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Incident\Models\Incident;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskSubCategory;
use App\Modules\Store\Models\InstituteDocument;
use Database\Seeders\AffectedGroupsSeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ١٨-٣ (ج) (قرار ٤٧): الوحدة داخل المكان تُحمل اختيارياً في بلاغ الشاغل والخطر الفعلي والفريق الأولي —
 * «في القاعات · قاعة تدريب ٣١٢» بدل «في القاعات».
 */
class PlaceUnitLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $salama; private User $fani; private User $mudir; private Place $halls; private PlaceUnit $hall; private PlaceUnit $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class]);
        $this->halls = Place::where('code', 'HZ-07')->first();
        $this->hall = PlaceUnit::create(['place_id' => $this->halls->id, 'type' => 'training_hall', 'name' => '٣١٢', 'floor' => '٣', 'capacity' => 25]);
        $this->room = PlaceUnit::create(['place_id' => Place::idByCode('HZ-02'), 'type' => 'electrical_room', 'name' => 'غرفة كهرباء ٢']);
        $this->salama = $this->user('salama', 'system_admin');
        $this->fani = $this->user('fani', 'field_worker', 'HZ-07');
        $this->mudir = $this->user('mudir', 'department_manager', null, 'fin');
    }

    private function user(string $username, string $role, ?string $placeCode = null, ?string $unitCode = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234']);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'place_id' => Place::idByCode($placeCode),
            'organization_unit_id' => $unitCode ? OrganizationUnit::where('code', $unitCode)->value('id') : null]);
        return $u;
    }

    /** الشاغل يختار القاعة: تُحفظ وتظهر للفني في البطاقة وفي صفحة البلاغ وفي التتبع؛ وحدة من مكان آخر تُرفض. */
    public function test_occupant_report_carries_the_unit(): void
    {
        $h = $this->get('/incident/normal')->assertOk()->getContent();
        $this->assertStringContainsString('name="place_unit_id"', $h);
        $this->assertStringContainsString('قاعة تدريب: ٣١٢ · الدور ٣', $h);
        // وحدة من مكان آخر (غرفة كهرباء) مع مكان القاعات: مرفوضة
        $this->post('/incident/normal', ['description' => 'دخان من جهاز العرض', 'place_id' => $this->halls->id, 'place_unit_id' => $this->room->id])->assertSessionHasErrors('place_unit_id');
        $this->post('/incident/normal', ['description' => 'دخان من جهاز العرض', 'place_id' => $this->halls->id, 'place_unit_id' => $this->hall->id])->assertRedirect();
        $i = Incident::first();
        $this->assertSame($this->hall->id, $i->place_unit_id);
        $this->actingAs($this->fani)->get('/app')->assertOk()->assertSee('في HZ-07 القاعات التدريبية · قاعة تدريب ٣١٢');
        $this->actingAs($this->fani)->get("/app/incidents/{$i->id}")->assertOk()->assertSee('قاعة تدريب ٣١٢ الدور ٣');
        auth()->logout();
        $this->get('/incident/track?code='.$i->secret_tracking_code)->assertOk()->assertSee('قاعة تدريب ٣١٢');
    }

    /** الخطر الفعلي يحمل الوحدة في الإضافة والتعديل، والوحدة من مكان آخر تُرفض. */
    public function test_active_risk_carries_the_unit(): void
    {
        $cat = RiskCategory::create(['name' => 'الحريق والانفجار', 'abbreviation' => 'FI', 'created_at' => now()]);
        $sub = RiskSubCategory::create(['category_id' => $cat->id, 'name' => 'حريق القاعات', 'abbreviation' => 'HAL']);
        $this->actingAs($this->mudir)->get('/app/risk/active/create')->assertOk()->assertSee('name="place_unit_id"', false);
        $base = ['category_id' => $cat->id, 'sub_category_id' => $sub->id, 'title' => 'حريق جهاز عرض', 'severity' => 3, 'likelihood' => 2,
            'scope_type' => 'org_unit', 'organization_unit_id' => OrganizationUnit::where('code', 'fin')->value('id'), 'place_id' => $this->halls->id];
        $this->actingAs($this->mudir)->post('/app/risk/active/create', $base + ['place_unit_id' => $this->room->id])->assertSessionHasErrors('place_unit_id');
        $this->actingAs($this->mudir)->post('/app/risk/active/create', $base + ['place_unit_id' => $this->hall->id])->assertRedirect();
        $r = Risk::where('risk_type', 'active')->first();
        $this->assertSame($this->hall->id, $r->place_unit_id);
        $this->actingAs($this->mudir)->get("/app/risk/active/{$r->id}/edit")->assertOk()->assertSee('value="'.$this->hall->id.'" data-place="'.$this->halls->id.'" selected', false);
        $this->actingAs($this->mudir)->post("/app/risk/active/{$r->id}/edit", $base + ['place_unit_id' => ''])->assertRedirect();
        $this->assertNull($r->fresh()->place_unit_id);
    }

    /** الفريق الأولي للإدارة يُربط بوحدتها في المكان حين يكون لها صف في وحدات الأماكن. */
    public function test_team_sync_links_department_unit(): void
    {
        $offices = Place::where('code', 'HZ-06')->first();
        $fin = OrganizationUnit::where('code', 'fin')->first();
        $dept = PlaceUnit::create(['place_id' => $offices->id, 'type' => 'department', 'name' => $fin->name, 'floor' => '٦', 'organization_unit_id' => $fin->id]);
        $row = fn ($role, $name) => ['role' => $role, 'name' => $name, 'user' => '', 'dept' => '', 'phone' => '', 'trained' => '', 'trainer' => ''];
        InstituteDocument::create(['key' => 'ipa-place', 'version' => 1, 'data' => json_encode(['HZ-06' => ['plans' => [], 'units' => ['fin' => [
            'team' => [$row('المنسق', 'ناصر'), $row('المسعف', 'سعد'), $row('المنقذ', 'خالد'), $row('الإطفائي', 'فهد')],
            'nom' => ['by' => 'م', 'dept' => 'fin', 'date' => '2026-09-01'], 'appr' => [], 'hr' => [],
        ]]]], JSON_UNESCAPED_UNICODE)]);
        app(TeamSync::class)->sync();
        $team = EmergencyTeam::where('source', 'place_profile')->where('place_id', $offices->id)->first();
        $this->assertNotNull($team);
        $this->assertSame($dept->id, $team->place_unit_id);
    }
}
