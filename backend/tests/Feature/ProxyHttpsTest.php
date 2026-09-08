<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * خلف وكيل Render: التحويلات يجب أن تكون https وإلا رفضها المتصفح من صفحة https.
 */
class ProxyHttpsTest extends TestCase
{
    use RefreshDatabase;

    public function test_redirects_are_https_behind_proxy(): void
    {
        $u = User::create(['username' => 'fani', 'name' => 'الفني', 'password' => '1234']);
        \App\Modules\Governance\Models\UserProfile::create(['user_id' => $u->id, 'role' => 'field_worker', 'is_active' => true]);

        $r = $this->withServerVariables(['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_HOST' => 'ipa-safety.onrender.com'])
            ->post('/logout');
        $this->assertStringStartsWith('https://', $r->headers->get('Location'));

        $r = $this->withServerVariables(['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_HOST' => 'ipa-safety.onrender.com'])
            ->post('/login', ['username' => 'fani', 'password' => '1234', 'next' => '/dashboard.html']);
        $this->assertStringStartsWith('https://', $r->headers->get('Location'));
    }
}
