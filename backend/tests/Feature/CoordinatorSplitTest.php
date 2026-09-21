<?php

namespace Tests\Feature;

use App\Core\Intents\IntentRegistry;
use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Emergency\Support\RoleCards;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٢١-١ (قرار ٥٣): فصل «منسق السلامة» عن «منسق الإخلاء والطوارئ».
 * منسق الإخلاء دور خفيف كالمسعف (صلاحيات الموظف + الاستجابة) وهو منسق الفريق الأولي وحامل بطاقة ٨؛
 * منسق السلامة يبقى بصلاحياته، يرشّحه مدير وحدته ويعتمده مسؤول السلامة (قرار ٥٢).
 */
class CoordinatorSplitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
    }

    private function user(string $username, string $role, ?int $unitId = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'organization_unit_id' => $unitId]);
        return $u;
    }

    public function test_evac_coordinator_is_a_light_team_role_and_holds_card_eight(): void
    {
        $this->assertSame('منسق الإخلاء والطوارئ', PermissionRegistry::ROLES['evac_coordinator'] ?? null);
        $this->assertContains('evac_coordinator', PermissionRegistry::ALL);
        $this->assertNull(PermissionRegistry::uiRole('evac_coordinator'));
        $this->assertCount(28, PermissionRegistry::assignableRoles()); // ٢٢-٦ب (قرار ٦٠): + طبيب العيادة

        $perms = PermissionRegistry::getRolePermissions('evac_coordinator');
        $expected = array_merge(PermissionRegistry::getRolePermissions('employee'), ['emergency.respond']);
        sort($perms);
        sort($expected);
        $this->assertSame($expected, $perms);
        $this->assertFalse(PermissionRegistry::hasPermission('evac_coordinator', 'incident.manage'));
        $this->assertFalse(PermissionRegistry::hasPermission('evac_coordinator', 'permit.review'));

        $this->assertSame([8], RoleCards::cardsOfRole('evac_coordinator'));
        $this->assertSame(8, RoleCards::forUser($this->user('munassiq', 'evac_coordinator')));
    }

    public function test_safety_coordinator_keeps_permissions_and_leaves_the_team_card(): void
    {
        $perms = PermissionRegistry::getRolePermissions('safety_coordinator');
        $this->assertCount(45, $perms);
        foreach (['incident.manage', 'risk.create', 'risk.activate', 'emergency.respond'] as $code) $this->assertContains($code, $perms);
        $this->assertSame([], RoleCards::cardsOfRole('safety_coordinator'));
        $this->assertNull(RoleCards::forUser($this->user('tansiq', 'safety_coordinator')));
    }

    public function test_department_manager_nominates_safety_coordinator_and_safety_officer_approves(): void
    {
        $unit = OrganizationUnit::where('is_active', true)->orderBy('id')->first();
        $other = OrganizationUnit::where('is_active', true)->where('id', '!=', $unit->id)->whereNotIn('id', OrganizationUnit::descendantIdsOf($unit->id))->orderByDesc('id')->first();
        $mudir = $this->user('mudir', 'department_manager', $unit->id);
        $salama = $this->user('salama', 'system_admin');

        $this->assertTrue(IntentRegistry::forUser($mudir)->contains('key', 'my_coordinator'));
        $this->actingAs($mudir)->get('/app/users')->assertOk()->assertSee('منسق سلامة إدارتي');

        // لا يسجّل إلا منسق سلامة، ولوحدته فقط
        $base = ['username' => 'coord1', 'name' => 'منسق تجريبي', 'password' => 'secret1'];
        $this->actingAs($mudir)->post('/app/users', $base + ['role' => 'employee', 'organization_unit_id' => $unit->id])->assertSessionHasErrors('role');
        $this->actingAs($mudir)->post('/app/users', $base + ['role' => 'safety_coordinator', 'organization_unit_id' => $other->id])->assertSessionHasErrors('organization_unit_id');

        $this->actingAs($mudir)->post('/app/users', $base + ['role' => 'safety_coordinator', 'organization_unit_id' => $unit->id])->assertRedirect()->assertSessionHasNoErrors();
        $p = UserProfile::where('role', 'safety_coordinator')->whereHas('user', fn ($q) => $q->where('username', 'coord1'))->first();
        $this->assertNotNull($p);
        $this->assertTrue($p->isPending());
        $this->assertFalse((bool) $p->is_active);
        $this->assertSame($unit->id, $p->organization_unit_id);

        // المدير لا يمس حساباً خارج منسقي وحدته
        $this->actingAs($mudir)->get("/app/users/{$salama->id}/edit")->assertForbidden();

        $this->actingAs($salama)->post("/app/users/{$p->user_id}/approve")->assertRedirect();
        $p->refresh();
        $this->assertTrue((bool) $p->is_active);
        $this->assertFalse($p->isPending());
        $this->assertSame($salama->id, $p->approved_by_id);
    }
}
