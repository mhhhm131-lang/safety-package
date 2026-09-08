<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Governance\Models\AppNotification;
use App\Modules\Governance\Models\AuditLog;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ١ — الحوكمة. البوابة: كل دور يدخل ويرى ما يخصه فقط، وكل فعل مسجّل.
 */
class GovernanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlacesSeeder::class);
        $this->seed(OrganizationUnitsSeeder::class);
    }

    private function user(string $username, string $role, ?string $unitCode = null, bool $active = true): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234']);
        UserProfile::create([
            'user_id' => $u->id, 'role' => $role, 'is_active' => $active,
            'organization_unit_id' => $unitCode ? OrganizationUnit::where('code', $unitCode)->value('id') : null,
        ]);
        return $u;
    }

    public function test_seed_reference_data(): void
    {
        $this->assertSame(9, Place::count());
        $this->assertSame(32, OrganizationUnit::count());
        $this->assertSame('HZ-06', OrganizationUnit::where('code', 'adm-eng')->first()->place->code);
        $this->assertSame('v-shared', OrganizationUnit::where('code', 'adm-eng')->first()->parent->code);
    }

    public function test_permission_matrix_on_screens(): void
    {
        $safety = $this->user('salama', 'system_admin');
        $duty = $this->user('munawib', 'system_staff');
        $tech = $this->user('fani', 'field_worker');
        $exec = $this->user('idara', 'top_management');
        $employee = $this->user('mowazaf', 'employee');

        // بلا دخول (قبل أي actingAs لأن الجلسة تبقى داخل الاختبار)
        $this->get('/app/users')->assertRedirect();

        // مسؤول السلامة: كل الشاشات
        foreach (['/app', '/app/users', '/app/org', '/app/places', '/app/audit', '/app/notifications'] as $p) {
            $this->actingAs($safety)->get($p)->assertOk();
        }
        // المناوب: المستخدمون والهيكل نعم؛ الإعدادات والتدقيق لا
        $this->actingAs($duty)->get('/app/users')->assertOk();
        $this->actingAs($duty)->get('/app/org')->assertOk();
        $this->actingAs($duty)->get('/app/places')->assertForbidden();
        $this->actingAs($duty)->get('/app/audit')->assertForbidden();
        // الفني والإدارة العليا والموظف: لا حوكمة
        foreach ([$tech, $exec, $employee] as $u) {
            $this->actingAs($u)->get('/app')->assertOk();
            $this->actingAs($u)->get('/app/users')->assertForbidden();
            $this->actingAs($u)->get('/app/org')->assertForbidden();
            $this->actingAs($u)->get('/app/audit')->assertForbidden();
        }
    }

    public function test_daily_work_session_maps_roles_and_blocks_employee(): void
    {
        $safety = $this->user('salama', 'system_admin');
        $this->actingAs($safety)->getJson('/api/store?all=1')->assertOk()
            ->assertJsonPath('session.r', 'safety')->assertJsonPath('session.role', 'system_admin');

        $dept = $this->user('mudir', 'department_manager', 'it');
        $this->actingAs($dept)->getJson('/api/store?all=1')->assertOk()
            ->assertJsonPath('session.r', 'dept')->assertJsonPath('session.d', 'it');

        $fm = $this->user('marafiq', 'facilities_manager');
        $this->actingAs($fm)->getJson('/api/store?all=1')->assertOk()->assertJsonPath('session.r', 'fm');

        $employee = $this->user('mowazaf', 'employee');
        $this->actingAs($employee)->getJson('/api/store?all=1')->assertStatus(403);
        $this->actingAs($employee)->putJson('/api/store/ipa-place', ['data' => '{}', 'version' => 0])->assertStatus(403);

        $disabled = $this->user('old', 'field_worker', null, false);
        $this->actingAs($disabled)->getJson('/api/store?all=1')->assertStatus(403);
    }

    public function test_disabled_account_cannot_login(): void
    {
        $this->user('old', 'field_worker', null, false);
        $this->post('/login', ['username' => 'old', 'password' => '1234'])->assertSessionHasErrors('username');
        $this->assertGuest();
        $this->user('fani', 'field_worker');
        $this->post('/login', ['username' => 'fani', 'password' => '1234'])->assertRedirect();
        $this->assertAuthenticated();
        $this->assertDatabaseHas('audit_logs', ['action' => 'login', 'model_name' => 'User']);
    }

    public function test_users_crud_is_audited(): void
    {
        $safety = $this->user('salama', 'system_admin');
        $this->actingAs($safety)->post('/app/users', [
            'username' => 'Ahmad', 'name' => 'أحمد', 'password' => 'secret1', 'role' => 'field_worker',
            'organization_unit_id' => OrganizationUnit::where('code', 'adm-eng')->value('id'),
            'place_id' => Place::where('code', 'HZ-01')->value('id'),
        ])->assertRedirect('/app/users');
        $ahmad = User::where('username', 'ahmad')->first();
        $this->assertNotNull($ahmad);
        $this->assertSame('field_worker', $ahmad->role());
        $this->assertTrue(AuditLog::where('model_name', 'UserProfile')->where('action', 'created')->where('user_id', $safety->id)->exists());

        $this->actingAs($safety)->post("/app/users/{$ahmad->id}/toggle")->assertRedirect();
        $this->assertFalse($ahmad->fresh()->isActive());
        $this->assertTrue(AuditLog::where('model_name', 'UserProfile')->where('action', 'updated')->exists());

        // لا يعطّل حسابه
        $this->actingAs($safety)->post("/app/users/{$safety->id}/toggle")->assertSessionHas('err');
        $this->assertTrue($safety->fresh()->isActive());

        // الفني لا ينشئ حسابات
        $tech = $this->user('fani', 'field_worker');
        $this->actingAs($tech)->post('/app/users', ['username' => 'x', 'name' => 'x', 'password' => 'secret1', 'role' => 'employee'])->assertForbidden();
    }

    public function test_org_units_screen_and_dashboard_document_stay_in_sync(): void
    {
        $safety = $this->user('salama', 'system_admin');

        // ١) من شاشة الخادم: إضافة قسم → وثيقة اللوحة تُحدَّث ونسختها ترتفع
        $it = OrganizationUnit::where('code', 'it')->first();
        $this->actingAs($safety)->post('/app/org', ['name' => 'قسم الشبكات', 'unit_type' => 'section', 'parent_id' => $it->id, 'place_id' => Place::where('code', 'HZ-04')->value('id')])
            ->assertRedirect('/app/org');
        $doc = $this->actingAs($safety)->getJson('/api/store/ipa-depts')->assertOk();
        $rows = json_decode($doc->json('data'), true);
        $net = collect($rows)->firstWhere('name', 'قسم الشبكات');
        $this->assertNotNull($net);
        $this->assertSame('it', $net['parent']);
        $this->assertSame('HZ-04', $net['place']);
        $v = $doc->json('version');

        // ٢) من اللوحة: تعديل الاسم وإضافة وحدة وحذف أخرى → الجدول يتبع
        $rows = array_values(array_filter($rows, fn ($r) => $r['id'] !== 'chg')); // حذف مكتب إدارة التحول (بلا أقسام)
        $rows[] = ['id' => 'newq', 'name' => 'قسم جديد من اللوحة', 'parent' => 'hr', 'place' => 'HZ-07', 'mgr' => 'فلان'];
        foreach ($rows as &$r) { if ($r['id'] === 'it') $r['name'] = 'تقنية المعلومات'; }
        unset($r);
        $this->actingAs($safety)->putJson('/api/store/ipa-depts', ['data' => json_encode($rows, JSON_UNESCAPED_UNICODE), 'version' => $v])->assertOk();
        $this->assertSame('تقنية المعلومات', $it->fresh()->name);
        $this->assertDatabaseMissing('organization_units', ['code' => 'chg']);
        $new = OrganizationUnit::where('code', 'newq')->first();
        $this->assertSame('hr', $new->parent->code);
        $this->assertSame('HZ-07', $new->place->code);
        $this->assertSame('فلان', $new->manager_name);

        // ٣) الحذف الممنوع: وحدة لها أقسام تُعطَّل لا تُحذف
        $this->actingAs($safety)->delete('/app/org/'.$it->id)->assertSessionHas('err');
        $this->assertDatabaseHas('organization_units', ['code' => 'it']);
    }

    public function test_places_screen_links_documents_and_forms(): void
    {
        $safety = $this->user('salama', 'system_admin');
        $r = $this->actingAs($safety)->get('/app/places')->assertOk();
        $r->assertSee('/HZ-01-basement/safety-plan.html', false)
          ->assertSee('/HZ-01-basement/response-plan.html', false)
          ->assertSee('/HZ-01-basement/inspection-form.html', false)
          ->assertSee('/HZ-00-safety-center/fire-inspection.html', false)
          ->assertDontSee('/HZ-00-safety-center/response-plan.html', false)
          ->assertSee('/dashboard.html#place=HZ-05', false);
    }

    public function test_notifications_inbox(): void
    {
        $safety = $this->user('salama', 'system_admin');
        $other = $this->user('fani', 'field_worker');
        app(\App\Core\Services\NotificationService::class)->notifyRoles(['system_admin'], 'test', 'بلاغ جديد', 'وصل بلاغ', '/app');
        $this->assertSame(1, AppNotification::count());
        $this->actingAs($safety)->getJson('/app/notifications/count')->assertOk()->assertJson(['count' => 1]);
        $this->actingAs($other)->getJson('/app/notifications/count')->assertOk()->assertJson(['count' => 0]);
        $n = AppNotification::first();
        $this->actingAs($other)->postJson("/app/notifications/{$n->id}/read")->assertForbidden();
        $this->actingAs($safety)->postJson("/app/notifications/{$n->id}/read")->assertOk();
        $this->actingAs($safety)->getJson('/app/notifications/count')->assertJson(['count' => 0]);
        $this->actingAs($safety)->get('/app/notifications')->assertOk()->assertSee('بلاغ جديد');
    }
}
