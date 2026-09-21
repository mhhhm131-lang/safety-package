<?php

namespace Tests\Feature\Governance;

use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Emergency\Support\RoleCards;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٢٠-٦ (قرار ٥١): مطابقة الأدوار بالبطاقات — لكل بطاقة من الـ٢١ حامل معروف (دور، أو عضو فريق، أو بلا حساب)،
 * وحساب فريق الإسناد يحمل بطاقته بالاسم (الطبيب ٤ / الأمن ٥ / مراقب الحريق ١٣)؛ وصفحة «الأدوار والبطاقات» للقراءة.
 */
class RoleCardsMatchTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $username, string $role, array $extra = []): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234']);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true] + $extra);
        return $u;
    }

    public function test_every_card_has_a_known_holder_and_every_role_is_placed(): void
    {
        $rows = RoleCards::holders();
        $this->assertCount(21, $rows);
        foreach ($rows as $no => $r) {
            $this->assertNotSame('', trim($r['holder']), "البطاقة $no بلا حامل");
            $this->assertContains($r['kind'], ['role', 'team', 'none', 'named']);
        }
        $this->assertSame('role', $rows[1]['kind']);   $this->assertSame('admin_eng_manager', $rows[1]['role']);
        $this->assertSame('named', $rows[4]['kind']);  $this->assertSame('support_team', $rows[4]['role']);
        $this->assertSame('named', $rows[13]['kind']);
        $this->assertSame('team', $rows[9]['kind']);   $this->assertStringContainsString('المسعف', $rows[9]['holder']);
        $this->assertSame('none', $rows[6]['kind']);   $this->assertStringContainsString('المحاضر', $rows[6]['holder']);
        $this->assertSame('none', $rows[7]['kind']);
        $this->assertSame('role', $rows[19]['kind']);  $this->assertSame('tech_electrical', $rows[19]['role']);
        $this->assertSame('role', $rows[21]['kind']);  $this->assertSame('system_staff', $rows[21]['role']);

        // كل دور قابل للإسناد له موضع: بطاقة، أو «بلا بطاقة سلامة» بسبب مكتوب
        $placed = RoleCards::rolesPlacement();
        $this->assertSame(array_keys(PermissionRegistry::assignableRoles()), array_keys($placed));
        foreach ($placed as $role => $p) $this->assertNotSame('', trim($p['text']), "الدور $role بلا موضع");
        $this->assertSame([20], $placed['system_admin']['cards']);
        $this->assertSame([4, 5, 13], $placed['support_team']['cards']);
        $this->assertSame([9], $placed['medic']['cards']);
        $this->assertSame([], $placed['department_manager']['cards']);
        $this->assertStringContainsString('ترشيح', $placed['department_manager']['text']);
    }

    public function test_support_team_account_holds_its_card_by_name_and_opens_it(): void
    {
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class]);
        $salama = $this->user('salama', 'system_admin');
        $doc = $this->user('doc', 'support_team', ['role_card_no' => 4]);
        $amn = $this->user('amn', 'support_team');

        $this->assertSame(4, RoleCards::forUser($doc));
        $this->assertNull(RoleCards::forUser($amn), 'بلا بطاقة محددة يبقى الفهرس');
        $this->actingAs($doc)->get('/app')->assertOk()->assertSee('href="/role-cards/support/role-04.html"', false)->assertSee('طبيب المعهد');

        // شاشة الحساب: خانة «بطاقته» تظهر لفريق الإسناد بثلاث بطاقات، وتُحفظ؛ ولا تُقبل بطاقة ليست لدوره
        $h = $this->actingAs($salama)->get("/app/users/{$amn->id}/edit")->assertOk()->getContent();
        $this->assertStringContainsString('name="role_card_no"', $h);
        $this->assertStringContainsString('أفراد الأمن المكلفون', $h);
        $this->actingAs($salama)->put("/app/users/{$amn->id}", ['username' => 'amn', 'name' => 'اسم amn', 'role' => 'support_team', 'role_card_no' => 5])->assertRedirect();
        $this->assertSame(5, $amn->profile->fresh()->role_card_no);
        $this->assertSame(5, RoleCards::forUser($amn->fresh()));
        $this->actingAs($salama)->put("/app/users/{$amn->id}", ['username' => 'amn', 'name' => 'اسم amn', 'role' => 'support_team', 'role_card_no' => 19])->assertSessionHasErrors('role_card_no');
        // دور ببطاقة واحدة: الخانة لا تظهر، وما يُرسل فيها يُهمل
        $this->actingAs($salama)->put("/app/users/{$salama->id}", ['username' => 'salama', 'name' => 'x', 'role' => 'system_admin', 'role_card_no' => 5])->assertRedirect();
        $this->assertNull($salama->profile->fresh()->role_card_no);
    }

    public function test_roles_and_cards_page_is_readable_by_any_account(): void
    {
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class]);
        $emp = $this->user('emp', 'employee');
        $this->get('/app/roles')->assertRedirect('/login'); // الضيف قبل أي دخول
        $h = $this->actingAs($emp)->get('/app/roles')->assertOk()->getContent();
        $this->assertSame(28, substr_count($h, 'data-role="')); // ٢١-١ (قرار ٥٣) + ٢٢-٦ب (قرار ٦٠): + منسق الإخلاء والطوارئ + طبيب العيادة
        $this->assertSame(21, substr_count($h, 'data-card="'));
        $this->assertStringContainsString('فني الكهرباء', $h);
        $this->assertStringContainsString('طبيب المعهد', $h);
        $this->assertStringContainsString('مالك النظام', $h); // لماذا لا مالك عندنا: جهة واحدة
        $this->actingAs($emp)->post('/app/roles')->assertStatus(405); // للقراءة فقط: لا كتابة على هذا المسار
        $this->actingAs($emp)->get('/app')->assertOk()->assertSee('data-intent="roles_map"', false);
    }
}
