<?php

namespace Tests\Feature;

use App\Core\Intents\IntentRegistry;
use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Services\ResponsePlanSync;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ١٥-٢ (قرار ٤٣): أدوار الفريق الأولي الثلاثة — مسعف، منقذ، إطفائي.
 * كل دور يضم أي عدد من الأشخاص؛ صلاحياته صلاحيات الموظف + emergency.respond؛ بلا لوحة ولا نماذج فحص.
 */
class TeamRolesTest extends TestCase
{
    use RefreshDatabase;

    private const TEAM_ROLES = ['medic' => 'مسعف', 'rescuer' => 'منقذ', 'firefighter' => 'إطفائي'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
    }

    private function user(string $username, string $role, ?string $placeCode = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'place_id' => Place::idByCode($placeCode)]);
        return $u;
    }

    public function test_three_team_roles_exist_and_each_holds_many_accounts(): void
    {
        $salama = $this->user('salama', 'system_admin');
        foreach (self::TEAM_ROLES as $key => $label) {
            $this->assertSame($label, PermissionRegistry::ROLES[$key] ?? null, "الدور $key غير موجود");
            $this->assertContains($key, PermissionRegistry::ALL);
            $this->assertNull(PermissionRegistry::uiRole($key), "$key لا يفتح اللوحة");
            foreach ([1, 2] as $n) {
                $this->actingAs($salama)->post('/app/users', ['username' => "$key$n", 'name' => "$label $n", 'password' => 'secret1', 'role' => $key])->assertRedirect();
            }
            $this->assertSame(2, UserProfile::where('role', $key)->count(), "الدور $key لا يضم حسابين");
        }
        $this->actingAs($salama)->get('/app/users/create')->assertOk()->assertSee('مسعف')->assertSee('منقذ')->assertSee('إطفائي');
    }

    public function test_team_role_has_employee_permissions_plus_emergency_respond(): void
    {
        $employee = PermissionRegistry::getRolePermissions('employee');
        foreach (array_keys(self::TEAM_ROLES) as $key) {
            $perms = PermissionRegistry::getRolePermissions($key);
            sort($perms);
            $expected = array_merge($employee, ['emergency.respond']);
            sort($expected);
            $this->assertSame($expected, $perms, "صلاحيات $key");
        }
    }

    public function test_medic_reports_opens_live_incident_and_marks_step_but_no_daily_work(): void
    {
        $root = dirname(base_path());
        if (!is_file($root.'/HZ-06-offices/response-plan.html')) $this->markTestSkipped('وثائق المعهد غير موجودة');
        app(ResponsePlanSync::class)->sync($root);

        $medic = $this->user('saad', 'medic', 'HZ-06');
        $h = $this->actingAs($medic)->get('/app')->assertOk()->getContent();
        $this->assertStringContainsString('data-intent="report"', $h);
        $this->assertStringContainsString('data-intent="sos"', $h);      // أستغيث الآن
        $this->assertStringNotContainsString('data-intent="trigger"', $h);
        $this->assertStringNotContainsString('data-intent="forms"', $h); // لا نماذج فحص
        $this->assertFalse(IntentRegistry::forUser($medic)->contains('key', 'inspections'));
        $this->actingAs($medic)->get('/app/inspections')->assertForbidden();
        $this->actingAs($medic)->getJson('/api/store?all=1')->assertStatus(403);

        $salama = $this->user('salama', 'system_admin');
        $building = EmergencyBuilding::main();
        $this->actingAs($salama)->post("/app/emergency/buildings/{$building->id}/trigger", [
            'incident_type' => 'fire', 'severity' => 'high', 'place_id' => Place::idByCode('HZ-06'),
        ])->assertRedirect();
        $i = EmergencyIncident::orderByDesc('id')->first();

        $this->actingAs($medic)->get("/app/emergency/incidents/{$i->id}/live")->assertOk()->assertSee('خطوات الخطة');
        $step = $i->planSteps()->where('path_key', 'medical')->orderBy('sort')->first();
        $this->actingAs($medic)->post("/app/emergency/incidents/{$i->id}/steps/{$step->id}/done")->assertSessionHas('success');
        $this->assertSame('done', $step->fresh()->status);
        $this->assertSame($medic->id, $step->fresh()->done_by_id);
    }
}
