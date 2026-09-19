<?php

namespace Tests\Feature\Governance;

use App\Core\Inbox\InboxService;
use App\Models\User;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٢٠-٤ و٢٠-٤-ب (قرارات ٥١ و٥٢): مدير المرافق يسجل فنييه من شاشة «فنيّي» (لا يرى غيرهم)،
 * وكل ما يسجله غيرُ مسؤول السلامة (مدير المرافق، المناوب) يبقى «بانتظار الاعتماد» ولا يدخل ولا تُمنح صلاحيته
 * حتى يعتمده مسؤول السلامة من «ما ينتظرك» بزر «اعتمد» أو «أعِده».
 */
class AccountApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $salama; private User $marafiq; private User $munawib;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class]);
        $this->salama = $this->user('salama', 'system_admin');
        $this->marafiq = $this->user('marafiq', 'facilities_manager');
        $this->munawib = $this->user('munawib', 'system_staff');
    }

    private function user(string $username, string $role): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234']);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true]);
        return $u;
    }

    private function tasks(User $u): array
    {
        return app(InboxService::class)->forUser($u)->where('module', 'الحسابات')->pluck('question')->all();
    }

    public function test_facilities_manager_registers_technicians_and_safety_officer_activates(): void
    {
        $elec = Place::idByCode('HZ-02');
        // شاشة «فنيّي»: يراها مدير المرافق، ولا يرى فيها إلا الفنيين، وقائمة الأدوار فيها التخصصات الستة فقط
        $this->user('emp', 'employee');
        $h = $this->actingAs($this->marafiq)->get('/app/users')->assertOk()->getContent();
        $this->assertStringContainsString('فنيّي', $h);
        $this->assertStringNotContainsString('class="text-end">emp</td>', $h);
        $this->assertStringNotContainsString('class="text-end">salama</td>', $h);
        $c = $this->actingAs($this->marafiq)->get('/app/users/create')->assertOk()->getContent();
        $this->assertStringContainsString('value="tech_electrical"', $c);
        $this->assertStringNotContainsString('value="employee"', $c);
        $this->assertStringNotContainsString('value="system_admin"', $c);
        // لا يُنشئ غير فني، ولا يعدّل غير فني
        $this->actingAs($this->marafiq)->post('/app/users', ['username' => 'x', 'name' => 'x', 'password' => '123456', 'role' => 'employee'])->assertSessionHasErrors('role');
        $this->actingAs($this->marafiq)->get("/app/users/{$this->salama->id}/edit")->assertForbidden();

        // يسجل فنياً بتخصصه وتغطيته ← بانتظار الاعتماد: لا يدخل
        $this->actingAs($this->marafiq)->post('/app/users', ['username' => 'kahraba', 'name' => 'فني الكهرباء', 'password' => '123456', 'role' => 'tech_electrical',
            'place_id' => $elec, 'job_title' => 'فني كهرباء', 'coverage' => [$elec]])->assertRedirect('/app/users')->assertSessionHas('ok', fn ($m) => str_contains($m, 'بانتظار اعتماد مسؤول السلامة'));
        $k = User::where('username', 'kahraba')->first();
        $this->assertTrue($k->profile->isPending());
        $this->assertFalse($k->profile->is_active);
        $this->assertSame($this->marafiq->id, $k->profile->pending_by_id);
        $this->post('/login', ['username' => 'kahraba', 'password' => '123456'])->assertSessionHasErrors();
        $this->assertGuest();

        // مسؤول السلامة: مهمة في «ما ينتظرك» وإشعار؛ المناوب لا تصله المهمة
        $q = $this->tasks($this->salama);
        $this->assertCount(1, $q);
        $this->assertStringContainsString('فني الكهرباء', $q[0]);
        $this->assertStringContainsString('مدير المرافق', $q[0]);
        $this->assertSame([], $this->tasks($this->munawib));
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->salama->id, 'type' => 'account_pending']);
        $this->actingAs($this->salama)->get('/app')->assertOk()->assertSee('ينتظر اعتمادك')->assertSee("/app/users/{$k->id}/approve", false);

        // الاعتماد: يعمل الحساب ويدخل، وتُسجَّل الموافقة باسمه وتاريخها، وتختفي المهمة
        $this->actingAs($this->marafiq)->post("/app/users/{$k->id}/approve")->assertForbidden();
        $this->actingAs($this->munawib)->post("/app/users/{$k->id}/approve")->assertForbidden();
        $this->actingAs($this->salama)->post("/app/users/{$k->id}/approve")->assertRedirect();
        $p = $k->profile->fresh();
        $this->assertTrue($p->is_active);
        $this->assertFalse($p->isPending());
        $this->assertSame($this->salama->id, $p->approved_by_id);
        $this->assertNotNull($p->approved_at);
        $this->assertSame([], $this->tasks($this->salama));
        $this->post('/login', ['username' => 'kahraba', 'password' => '123456'])->assertRedirect();
        $this->assertAuthenticatedAs($k);
    }

    public function test_duty_officer_accounts_wait_too_and_safety_officer_accounts_do_not(): void
    {
        $this->actingAs($this->munawib)->post('/app/users', ['username' => 'm1', 'name' => 'من المناوب', 'password' => '123456', 'role' => 'employee'])->assertRedirect('/app/users');
        $this->assertTrue(User::where('username', 'm1')->first()->profile->isPending());
        $this->actingAs($this->salama)->post('/app/users', ['username' => 's1', 'name' => 'من مسؤول السلامة', 'password' => '123456', 'role' => 'employee'])->assertRedirect('/app/users');
        $s1 = User::where('username', 's1')->first()->profile;
        $this->assertFalse($s1->isPending());
        $this->assertTrue($s1->is_active);
        $this->assertSame($this->salama->id, $s1->approved_by_id);

        // «أعِده»: يبقى معطّلاً، تُمحى حالة الانتظار، ويُسجَّل السبب ويُبلَّغ من سجّله
        $m1 = User::where('username', 'm1')->first();
        $this->actingAs($this->salama)->post("/app/users/{$m1->id}/return", ['note' => 'الدور غير مناسب'])->assertRedirect();
        $p = $m1->profile->fresh();
        $this->assertFalse($p->isPending());
        $this->assertFalse($p->is_active);
        $this->assertSame('الدور غير مناسب', $p->return_note);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->munawib->id, 'type' => 'account_returned']);
        $this->actingAs($this->munawib)->get('/app/users')->assertOk()->assertSee('أُعيد: الدور غير مناسب');
        $this->assertSame([], $this->tasks($this->salama));
    }

    public function test_changing_role_or_coverage_by_a_non_admin_needs_approval_again_but_name_does_not(): void
    {
        $elec = Place::idByCode('HZ-02'); $hvac = Place::idByCode('HZ-03');
        $k = $this->user('kahraba', 'tech_electrical');
        $k->profile->coverage()->sync([$elec]);
        $put = fn (User $by, array $extra) => $this->actingAs($by)->put("/app/users/{$k->id}", $extra + ['username' => 'kahraba', 'name' => 'اسم kahraba', 'role' => 'tech_electrical', 'coverage' => [$elec]]);

        $put($this->marafiq, ['name' => 'فني الكهرباء الجديد', 'job_title' => 'فني'])->assertRedirect();
        $this->assertFalse($k->profile->fresh()->isPending(), 'تغيير الاسم لا يحتاج اعتماداً');
        $this->assertTrue($k->profile->fresh()->is_active);

        $put($this->marafiq, ['coverage' => [$elec, $hvac]])->assertRedirect();
        $p = $k->profile->fresh();
        $this->assertTrue($p->isPending(), 'تغيير التغطية يحتاج اعتماداً');
        $this->assertFalse($p->is_active);
        $this->assertStringContainsString('التغطية', (string) $p->pending_note);
        $this->assertSame(['HZ-02', 'HZ-03'], $p->coverage->pluck('code')->all());
        $this->assertCount(1, $this->tasks($this->salama));

        $this->actingAs($this->salama)->post("/app/users/{$k->id}/approve")->assertRedirect();
        $this->assertTrue($k->profile->fresh()->is_active);

        // مسؤول السلامة يغيّر الدور: نافذ فوراً
        $this->actingAs($this->salama)->put("/app/users/{$k->id}", ['username' => 'kahraba', 'name' => 'x', 'role' => 'tech_hvac'])->assertRedirect();
        $this->assertFalse($k->profile->fresh()->isPending());
        $this->assertSame('tech_hvac', $k->profile->fresh()->role);
    }
}
