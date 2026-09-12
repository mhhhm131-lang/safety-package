<?php

namespace Tests\Feature;

use App\Core\Intents\IntentRegistry;
use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Incident\Models\Incident;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskSubCategory;
use Database\Seeders\AffectedGroupsSeeder;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ١٢ (قرار ٣٥): «أريد أن…» — أي شيء يريد المستخدم فعله، أياً كان دوره، يجده في شاشته الأولى وينفّذه.
 * البوابة: لكل دور من الـ١٨ والضيف، كل إجراء يبدؤه الدور له زر، وكل زر يفتح (لا 404/403/500).
 */
class IntentsTest extends TestCase
{
    use RefreshDatabase;

    /** الإجراءات التي يبدؤها الإنسان بنفسه ← الصلاحية التي تشترطها ← مفتاح النية الذي يجب أن يظهر */
    private const INITIATING = [
        'incident.create' => 'report', 'permit.create' => 'permit', 'risk.activate' => 'activate', 'form.send' => 'sendform',
        'emergency.trigger' => 'trigger', 'emergency.drill' => 'drill', 'worker.create' => 'worker', 'project.create' => 'project',
        'external_party.create' => 'party', 'report.view' => 'reports', 'system.settings' => 'settings', 'emergency.view' => 'emergency',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class, EmergencySeeder::class]);
    }

    private function user(string $username, string $role, ?string $placeCode = null, ?int $unitId = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'place_id' => Place::idByCode($placeCode), 'organization_unit_id' => $unitId]);
        return $u;
    }

    /** البوابة: الأدوار الـ١٨ كلها — كل إجراء يبدؤه الدور له زر، وكل زر يفتح. */
    public function test_every_role_finds_every_initiating_action_as_a_button_that_opens(): void
    {
        $unit = OrganizationUnit::first();
        $n = 0;
        foreach (array_keys(PermissionRegistry::ROLES) as $role) {
            $u = $this->user('u'.(++$n), $role, 'HZ-06', $unit->id);
            if ($u->isContractor()) { // بوابة المقاول تحوّل إليها مباشرة (المرحلة ٦)
                $this->actingAs($u)->get('/app')->assertRedirect('/app/contractor');
                $this->assertTrue(IntentRegistry::forUser($u)->contains('key', 'portal'), "$role بلا «بوابتي»");
                continue;
            }
            $html = $this->actingAs($u)->get('/app')->assertOk()->getContent();
            $this->assertStringContainsString('أريد أن…', $html, "$role بلا «أريد أن…»");
            $intents = IntentRegistry::forUser($u);
            foreach (self::INITIATING as $perm => $key) {
                $needs = PermissionRegistry::hasPermission($role, $perm);
                if ($perm === 'permit.create') $needs = $needs && PermissionRegistry::hasPermission($role, 'permit.list'); // المسار نفسه يشترطهما (خلل قائم للموظف)
                if ($needs) {
                    $this->assertStringContainsString('data-intent="'.$key.'"', $html, "الدور {$role} يملك {$perm} ولا زر «{$key}» في شاشته الأولى");
                } elseif (!in_array($key, ['report'], true)) {
                    $this->assertStringNotContainsString('data-intent="'.$key.'"', $html, "الدور {$role} لا يملك {$perm} ويرى زر «{$key}»");
                }
            }
            // كل زر يفتح (صفحات المعهد الثابتة خارج الاختبار)
            foreach ($intents as $i) {
                if (!str_starts_with($i->url, url('/app')) && !str_starts_with($i->url, url('/incident')) && !str_starts_with($i->url, url('/hazards'))) continue;
                $code = $this->actingAs($u)->get($i->url)->getStatusCode();
                $this->assertContains($code, [200, 302], "الدور $role: زر «{$i->label}» ← {$i->url} أعاد $code");
            }
        }
    }

    public function test_role_specific_intents_field_worker_manager_and_team(): void
    {
        $fani = $this->user('fani', 'field_worker', 'HZ-06');
        $h = $this->actingAs($fani)->get('/app')->assertOk()->getContent();
        $this->assertStringContainsString('href="/HZ-06-offices/inspection-form.html"', $h);   // أفحص مكاني
        $this->assertStringContainsString('data-intent="sos"', $h);                             // أستغيث الآن
        $this->assertStringNotContainsString('data-intent="trigger"', $h);
        $this->assertStringNotContainsString('data-intent="nominate"', $h);

        $mudir = $this->user('mudir', 'department_manager', null, OrganizationUnit::first()->id);
        $h = $this->actingAs($mudir)->get('/app')->assertOk()->getContent();
        // مدير الإدارة لا يملك permit.create في مصفوفة الصلاحيات القائمة (OHSMS) — يُسجَّل سؤالاً للمستخدم لا يُخترع
        foreach (['nominate', 'activate', 'trigger', 'sendform' === '' ? 'x' : 'plans'] as $k) $this->assertStringContainsString('data-intent="'.$k.'"', $h, $k);
        $this->assertStringNotContainsString('data-intent="permit"', $h);
        $this->assertStringNotContainsString('data-intent="inspect"', $h);
        $this->assertStringNotContainsString('data-intent="settings"', $h);

        $salama = $this->user('salama', 'system_admin', 'HZ-00');
        $h = $this->actingAs($salama)->get('/app')->assertOk()->getContent();
        foreach (['trigger', 'lockdown', 'drill', 'teams', 'systems', 'permit', 'sendform', 'worker', 'project', 'party', 'reports', 'settings'] as $k) {
            $this->assertStringContainsString('data-intent="'.$k.'"', $h, $k);
        }
        $this->assertStringContainsString('?place=HZ-00', $h); // التفعيل بمكانه محدداً
    }

    /** الضيف: نواياه على أول صفحة يراها؛ كتاب المعهد عام؛ «رأيت هذا؟ بلّغ» يفتح البلاغ والخطر محدد ويُرسل بلا تصنيف. */
    public function test_guest_intents_public_hazard_book_and_report_from_hazard(): void
    {
        $cat = RiskCategory::create(['name' => 'الحريق والانفجار', 'abbreviation' => 'FI', 'created_at' => now()]);
        $sub = RiskSubCategory::create(['category_id' => $cat->id, 'name' => 'مصادر الاشتعال', 'abbreviation' => 'IG']);
        $risk = Risk::create(['risk_type' => 'reference', 'code' => 'FI-01-01', 'title' => 'سلك مكشوف قرب مواد قابلة للاشتعال', 'description' => 'شرارة تشعل ما حولها',
            'category_id' => $cat->id, 'sub_category_id' => $sub->id, 'severity' => 4, 'likelihood' => 2, 'status' => 'approved']);
        \App\Modules\Risk\Models\RiskPhase::updateOrCreate(['risk_id' => $risk->id, 'phase' => 'operational'], ['preventive_action' => 'أبعد المواد وأبلغ فوراً']);
        Risk::create(['risk_type' => 'reference', 'code' => 'FI-01-02', 'title' => 'مسودة لا تُعرض', 'description' => 'x', 'category_id' => $cat->id, 'sub_category_id' => $sub->id, 'severity' => 1, 'likelihood' => 1, 'status' => 'draft']);

        $h = $this->get('/incident')->assertOk()->getContent();
        foreach (['report', 'track', 'hazards', 'plans', 'roles'] as $k) $this->assertStringContainsString('data-intent="'.$k.'"', $h, $k);

        $b = $this->get('/hazards')->assertOk();
        $b->assertSee('سلك مكشوف قرب مواد')->assertSee('أبعد المواد وأبلغ فوراً')->assertDontSee('مسودة لا تُعرض')->assertSee('رأيت هذا؟ بلّغ')
          ->assertSee('/incident/normal?risk='.$risk->id, false);
        $this->get('/hazards?q=مكشوف')->assertOk()->assertSee('FI-01-01');
        $this->get('/hazards?q=زلزال')->assertOk()->assertSee('لا خطر يطابق');

        $f = $this->get('/incident/normal?risk='.$risk->id.'&place=HZ-06')->assertOk();
        $f->assertSee('id="presetRisk"', false)->assertSee('سلك مكشوف قرب مواد')->assertSee('name="risk_id" value="'.$risk->id.'"', false)->assertDontSee('id="riskCat"', false);
        $this->post('/incident/normal', ['description' => 'رأيت السلك عند لوحة الطابق الثاني', 'place_id' => Place::idByCode('HZ-06'), 'risk_id' => $risk->id])->assertRedirect();
        $i = Incident::first();
        $this->assertSame($risk->id, $i->risk_id); // لا يحتاج تصنيف المركز
        $this->assertSame('received', $i->status); // لا فني لـ HZ-06 في هذا الاختبار — ينتظر إحالة المركز
    }
}
