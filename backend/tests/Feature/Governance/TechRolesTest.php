<?php

namespace Tests\Feature\Governance;

use App\Core\Permissions\PermissionRegistry as P;
use App\Models\User;
use App\Modules\Emergency\Support\RoleCards;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Incident\Models\Incident;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٢٠-٣ (قرار ٥١): الأدوار ٢٦ — الفنيون الستة بالتخصص بصلاحية الفني نفسها ودور واجهة الفني،
 * وبطاقاتهم ١٤–١٩ لهم؛ «الفني المنفّذ» لا يُنشأ به حساب جديد ويبقى مفتاحه للحسابات القائمة حتى تُنقل؛
 * فريق الإسناد للطبيب والأمن ومراقب الحريق.
 */
class TechRolesTest extends TestCase
{
    use RefreshDatabase;

    private User $salama;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class]);
        $this->salama = User::create(['username' => 'salama', 'name' => 'مسؤول السلامة', 'password' => '1234']);
        UserProfile::create(['user_id' => $this->salama->id, 'role' => 'system_admin', 'is_active' => true]);
    }

    public function test_six_technician_roles_carry_the_technician_permissions_and_cards(): void
    {
        $this->assertSame(['tech_fire_pump', 'tech_generator', 'tech_fire_alarm', 'tech_hvac', 'tech_elevator', 'tech_electrical'], P::TECH_ROLES);
        $this->assertCount(28, P::assignableRoles(), 'الأدوار القابلة للإسناد ليست ٢٨'); // ٢١-١ (قرار ٥٣) + ٢٢-٦ب (قرار ٦٠)
        $this->assertArrayNotHasKey('field_worker', P::assignableRoles());
        $this->assertArrayHasKey('field_worker', P::ROLES, 'مفتاح الفني المنفّذ يبقى للحسابات القائمة');

        $fw = P::getRolePermissions('field_worker');
        foreach (P::TECH_ROLES as $r) {
            $this->assertSame($fw, P::getRolePermissions($r), "$r لا يحمل صلاحيات الفني");
            $this->assertSame('tech', P::uiRole($r));
            $this->assertTrue(P::isTech($r));
        }
        $this->assertTrue(P::isTech('field_worker'));
        $this->assertFalse(P::isTech('support_team'));

        // البطاقات ١٤–١٩ للفنيين لا للإسناد؛ والإسناد يبقى ٤ و٥ و١٣
        $this->assertSame('tech_electrical', RoleCards::CARDS[19]['role']);
        $this->assertSame('tech_fire_pump', RoleCards::CARDS[14]['role']);
        $this->assertSame([4, 5, 13], array_keys(array_filter(RoleCards::CARDS, fn ($c) => ($c['role'] ?? null) === 'support_team')));
        $u = User::create(['username' => 'kahraba', 'name' => 'فني الكهرباء', 'password' => '1234']);
        UserProfile::create(['user_id' => $u->id, 'role' => 'tech_electrical', 'is_active' => true, 'place_id' => Place::idByCode('HZ-02')]);
        $this->assertSame(19, RoleCards::forUser($u));
        $this->actingAs($u)->get('/app')->assertOk()->assertSee('href="/role-cards/support/role-19.html"', false)->assertSee('بطاقة دوري');
    }

    public function test_new_accounts_use_a_specialty_and_old_field_worker_accounts_keep_working_until_moved(): void
    {
        $h = $this->actingAs($this->salama)->get('/app/users/create')->assertOk()->getContent();
        $this->assertStringContainsString('value="tech_electrical"', $h);
        $this->assertStringNotContainsString('value="field_worker"', $h);
        $this->actingAs($this->salama)->post('/app/users', ['username' => 'old1', 'name' => 'x', 'password' => '123456', 'role' => 'field_worker'])->assertSessionHasErrors('role');
        $this->actingAs($this->salama)->post('/app/users', ['username' => 'new1', 'name' => 'x', 'password' => '123456', 'role' => 'tech_hvac'])->assertRedirect('/app/users');

        // حساب قائم بالدور القديم: يدخل ويعمل، والقائمة تنبّه إلى نقله
        $fani = User::create(['username' => 'fani', 'name' => 'الفني', 'password' => '1234']);
        UserProfile::create(['user_id' => $fani->id, 'role' => 'field_worker', 'is_active' => true, 'place_id' => Place::idByCode('HZ-01')]);
        $this->actingAs($fani)->get('/app')->assertOk()->assertSee('data-intent="inspect"', false);
        $this->actingAs($this->salama)->get('/app/users')->assertOk()->assertSee('انقله إلى تخصص');
        // والتعديل يعرض الدور القديم محدَّداً ليُبدَّل، ولا يقبل حفظه كما هو
        $this->actingAs($this->salama)->get("/app/users/{$fani->id}/edit")->assertOk()->assertSee('value="field_worker"', false);
        $this->actingAs($this->salama)->put("/app/users/{$fani->id}", ['username' => 'fani', 'name' => 'الفني', 'role' => 'field_worker'])->assertSessionHasErrors('role');
        $this->actingAs($this->salama)->put("/app/users/{$fani->id}", ['username' => 'fani', 'name' => 'الفني', 'role' => 'tech_electrical', 'place_id' => Place::idByCode('HZ-01')])->assertRedirect();
        $this->assertSame('tech_electrical', $fani->fresh()->profile->role);
    }

    public function test_specialty_technician_is_a_field_handler_for_occupant_incidents(): void
    {
        $tech = User::create(['username' => 'mas', 'name' => 'فني المصاعد', 'password' => '1234']);
        UserProfile::create(['user_id' => $tech->id, 'role' => 'tech_elevator', 'is_active' => true, 'place_id' => Place::idByCode('HZ-06')]);
        $munawib = User::create(['username' => 'mn', 'name' => 'المناوب', 'password' => '1234']);
        UserProfile::create(['user_id' => $munawib->id, 'role' => 'system_staff', 'is_active' => true]);

        $this->post('/incident/normal', ['description' => 'مصعد عالق بين الدورين', 'place_id' => Place::idByCode('HZ-06')])->assertRedirect();
        $i = Incident::first();
        // الإحالة إلى فني بالتخصص تُقبل كما كانت تُقبل للفني المنفّذ، ويظهر في قائمة الفنيين
        $this->actingAs($munawib)->get("/app/incidents/{$i->id}")->assertOk()->assertSee('فني المصاعد');
        $this->actingAs($munawib)->post("/app/incidents/{$i->id}/refer", ['field_worker_id' => $tech->id])->assertRedirect();
        $this->assertSame($tech->id, $i->fresh()->incident_field_team_id);
        $this->actingAs($tech)->get('/app')->assertOk()->assertSee($i->code);
    }
}
