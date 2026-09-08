<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الطبقة صفر — المخزن المركزي: الجلسة، الحفظ الحرفي، تعارض النسخ، الحذف، الاستطلاع، الدخول.
 */
class StoreApiTest extends TestCase
{
    use RefreshDatabase;

    private function safety(): User
    {
        $u = User::create(['username' => 'salama', 'name' => 'مسؤول السلامة', 'password' => '1234']);
        \App\Modules\Governance\Models\UserProfile::create(['user_id' => $u->id, 'role' => 'system_admin', 'is_active' => true]);
        return $u;
    }

    public function test_unauthenticated_gets_401_json(): void
    {
        $this->getJson('/api/store?all=1')->assertStatus(401);
        $this->putJson('/api/store/ipa-place', ['data' => '{}'])->assertStatus(401);
    }

    public function test_index_returns_session_and_docs_verbatim(): void
    {
        $u = $this->safety();
        $raw = '{"HZ-01":{"plans":{},"units":{}},"levels":{"1":{"up":true}}}';
        $this->actingAs($u)->putJson('/api/store/ipa-place', ['data' => $raw, 'version' => 0])
            ->assertOk()->assertJson(['key' => 'ipa-place', 'version' => 1]);

        $r = $this->actingAs($u)->getJson('/api/store?all=1')->assertOk()
            ->assertJsonPath('session.u', 'salama')
            ->assertJsonPath('session.r', 'safety')
            ->assertJsonPath('docs.ipa-place.version', 1);
        // الكائن الفارغ {} يعود {} لا [] — حرفياً
        $this->assertSame($raw, $r->json('docs.ipa-place.data'));
        $this->assertNotEmpty($r->json('csrf'));
    }

    public function test_version_conflict_returns_409_with_server_copy(): void
    {
        $u = $this->safety();
        $this->actingAs($u)->putJson('/api/store/ipa-place', ['data' => '["a"]', 'version' => 0])->assertOk();
        $this->actingAs($u)->putJson('/api/store/ipa-place', ['data' => '["a","b"]', 'version' => 1])->assertOk()->assertJson(['version' => 2]);

        // جهاز آخر ما زال على النسخة ١
        $this->actingAs($u)->putJson('/api/store/ipa-place', ['data' => '["stale"]', 'version' => 1])
            ->assertStatus(409)
            ->assertJsonPath('version', 2)
            ->assertJsonPath('data', '["a","b"]');
    }

    public function test_versions_listing_and_keys_filter(): void
    {
        $u = $this->safety();
        $this->actingAs($u)->putJson('/api/store/ipa-park-form-v10', ['data' => '{"reports":[]}', 'version' => 0])->assertOk();
        $this->actingAs($u)->putJson('/api/store/ipa-occ', ['data' => '{"seq":0,"reports":[]}', 'version' => 0])->assertOk();

        $this->actingAs($u)->getJson('/api/store?versions=1')
            ->assertOk()->assertJsonPath('versions.ipa-park-form-v10', 1)->assertJsonPath('versions.ipa-occ', 1);

        $r = $this->actingAs($u)->getJson('/api/store?all=1&keys=ipa-occ')->assertOk();
        $this->assertArrayHasKey('ipa-occ', $r->json('docs'));
        $this->assertArrayNotHasKey('ipa-park-form-v10', $r->json('docs'));
    }

    public function test_delete_forbidden_keys_and_invalid_json(): void
    {
        $u = $this->safety();
        $this->actingAs($u)->putJson('/api/store/ipa-seen', ['data' => '{}', 'version' => 0])->assertOk();
        $this->actingAs($u)->deleteJson('/api/store/ipa-seen')->assertOk()->assertJson(['deleted' => true]);
        $this->actingAs($u)->getJson('/api/store/ipa-seen')->assertStatus(404);

        $this->actingAs($u)->putJson('/api/store/ipa-session', ['data' => '{}'])->assertStatus(422);
        $this->actingAs($u)->putJson('/api/store/ipa-store-pending', ['data' => '{}'])->assertStatus(422);
        $this->actingAs($u)->putJson('/api/store/other-key', ['data' => '{}'])->assertStatus(422);
        $this->actingAs($u)->putJson('/api/store/ipa-place', ['data' => '{not json'])->assertStatus(422);
    }

    public function test_login_page_and_login_with_username(): void
    {
        $this->safety();
        $this->get('/login')->assertOk()->assertSee('اسم المستخدم');
        $this->post('/login', ['username' => 'SALAMA', 'password' => '1234', 'next' => '/dashboard.html'])
            ->assertRedirect('/dashboard.html');
        $this->post('/logout');
        $this->post('/login', ['username' => 'salama', 'password' => '1234'])->assertRedirect('/app');
        $this->assertAuthenticated();
        $this->post('/login', ['username' => 'salama', 'password' => 'wrong'])->assertSessionHasErrors('username');
    }

    public function test_root_redirects_to_vision(): void
    {
        $this->get('/')->assertRedirect('/vision.html');
    }
}
