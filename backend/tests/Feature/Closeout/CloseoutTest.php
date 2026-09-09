<?php

namespace Tests\Feature\Closeout;

use App\Core\Services\CloseoutService;
use App\Models\User;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Incident\Models\Incident;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskControl;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Database\Seeders\RiskBookSeeder;
use Database\Seeders\RiskControlsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * المرحلة ٨-١ — الأمن والتنظيف.
 *
 * ما يجب أن يصمد: المرجعي لا يُمس، والحارس يمنع إغلاق الباب على الجميع،
 * وكل جدول مصنَّف (وإلا بقيت بيانات تجربة بعد التسليم أو حُذف مرجع).
 */
class CloseoutTest extends TestCase
{
    use RefreshDatabase;

    private User $salama;
    private User $fani;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, RiskBookSeeder::class, RiskControlsSeeder::class]);

        $this->salama = $this->user('salama', 'system_admin');
        $this->fani   = $this->user('fani', 'field_worker');
    }

    private function user(string $username, string $role, bool $active = true): User
    {
        $u = User::create([
            'username' => $username, 'name' => "اسم {$username}",
            'password' => CloseoutService::SEEDED_PASSWORD, 'email' => "{$username}@example.test",
        ]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => $active]);

        return $u;
    }

    private function activeRisk(): Risk
    {
        $category = RiskCategory::where('name', 'مخاطر الحريق')->firstOrFail();
        $risk = Risk::create([
            'risk_type' => 'active', 'title' => 'خطر فعلي للاختبار', 'description' => 'وصف',
            'category_id' => $category->id, 'place_id' => Place::idByCode('HZ-06'),
            'severity' => 4, 'likelihood' => 4, 'status' => 'active',
        ]);
        RiskControl::create([
            'risk_id' => $risk->id, 'risk_category_id' => $category->id, 'phase' => 'preventive',
            'description_ar' => 'بند تحكم للاختبار', 'evidence_type' => 'check',
        ]);

        return $risk;
    }

    private function incident(string $code): Incident
    {
        return Incident::create([
            'code' => $code, 'title' => 'بلاغ تجربة', 'description' => 'وصف',
            'incident_type' => 'normal', 'status' => 'new',
            'risk_id' => Risk::where('risk_type', 'reference')->value('id'),
            'place_id' => Place::idByCode('HZ-06'),
        ]);
    }

    // ════════════ الحارس: كل جدول مصنَّف ════════════

    public function test_every_table_is_classified(): void
    {
        $this->assertSame([], app(CloseoutService::class)->unclassifiedTables(),
            'جدول بلا تصنيف: صنّفه في OPERATIONAL أو REFERENCE قبل التسليم.');
    }

    public function test_no_table_is_in_both_lists(): void
    {
        $both = array_intersect(CloseoutService::OPERATIONAL, CloseoutService::REFERENCE);
        $this->assertSame([], array_values($both));
    }

    // ════════════ الحذف ════════════

    public function test_purge_removes_operational_and_keeps_reference(): void
    {
        $this->incident('ش-0001');
        $this->incident('ش-0002');
        $this->activeRisk();

        $masterBefore    = Risk::where('risk_type', 'master')->count();
        $referenceBefore = Risk::where('risk_type', 'reference')->count();
        $placesBefore    = Place::count();
        $usersBefore     = User::count();

        $this->assertGreaterThan(0, $masterBefore);

        app(CloseoutService::class)->purge();

        $this->assertSame(0, Incident::count());
        $this->assertSame(0, Risk::where('risk_type', 'active')->count());

        $this->assertSame($masterBefore, Risk::where('risk_type', 'master')->count());
        $this->assertSame($referenceBefore, Risk::where('risk_type', 'reference')->count());
        $this->assertSame($placesBefore, Place::count());
        $this->assertSame($usersBefore, User::count(), 'الحسابات لا تُحذف — تُعطَّل');
    }

    public function test_purge_keeps_category_level_controls(): void
    {
        // بنود التحكم على مستوى الفئة (risk_id فارغ) مرجعية — لا تُحذف مع الخطر الفعّال
        $categoryControls = RiskControl::whereNull('risk_id')->count();
        $this->assertGreaterThan(0, $categoryControls);

        $this->activeRisk();
        app(CloseoutService::class)->purge();

        $this->assertSame($categoryControls, RiskControl::whereNull('risk_id')->count());
    }

    public function test_inventory_counts_what_will_be_deleted(): void
    {
        $this->incident('ش-0001');
        $inventory = app(CloseoutService::class)->inventory();

        $this->assertSame(1, $inventory['بلاغات الشاغل']);
        $this->assertArrayHasKey('المخاطر الفعّالة (الإدارات والأماكن)', $inventory);
    }

    public function test_preserved_shows_the_reference_data(): void
    {
        $preserved = app(CloseoutService::class)->preserved();

        $this->assertGreaterThan(0, $preserved['كتاب المخاطر']);
        $this->assertSame(9, $preserved['الأماكن']);
    }

    // ════════════ تعطيل ما بقي على الكلمة المبذورة ════════════

    public function test_seeded_password_is_detected(): void
    {
        $closeout = app(CloseoutService::class);

        $this->assertTrue($closeout->stillSeeded($this->salama), 'الحساب المبذور على كلمة البذرة');
    }

    public function test_changed_password_is_not_seeded(): void
    {
        $this->salama->password = 'Strong-Real-2026';
        $this->salama->save();

        $this->assertFalse(app(CloseoutService::class)->stillSeeded($this->salama->refresh()));
    }

    public function test_a_renamed_seeded_account_is_still_caught(): void
    {
        // المستخدم أعاد تسمية حساب مبذور فخرج من القائمة التجريبية — والكلمة هي الخطر
        $this->fani->username = 'ohsmsadmin';
        $this->fani->save();

        $risky = app(CloseoutService::class)->riskyAccounts()->pluck('username');

        $this->assertContains('ohsmsadmin', $risky->all(),
            'الحساب المُعاد تسميته لا يفلت من الفحص');
    }

    public function test_renamed_seeded_account_is_disabled(): void
    {
        $this->salama->password = 'Strong-Real-2026';
        $this->salama->save();
        $this->fani->username = 'renamed.tech';
        $this->fani->save();

        $this->artisan('ipa:demo-off')->assertSuccessful();

        $this->assertFalse((bool) UserProfile::where('user_id', $this->fani->id)->value('is_active'));
    }

    public function test_screen_lists_a_risky_account_outside_the_demo_list(): void
    {
        $this->salama->password = 'Strong-Real-2026';
        $this->salama->save();
        $outsider = $this->user('sara.alahmad', 'department_manager'); // كلمته مبذورة

        $this->actingAs($this->salama->refresh())->get(route('app.closeout.index'))
            ->assertOk()
            ->assertSee('data-seeded="sara.alahmad"', false);
    }

    public function test_demo_off_refuses_when_no_admin_would_survive(): void
    {
        // salama هو مسؤول السلامة الوحيد وما زال على الكلمة المبذورة
        $this->artisan('ipa:demo-off')->assertFailed();

        $this->assertTrue((bool) UserProfile::where('user_id', $this->salama->id)->value('is_active'));
    }

    public function test_demo_off_spares_accounts_whose_password_changed(): void
    {
        // المستخدم غيّر كلمته: حسابه صار حقيقياً ولا يُعطَّل
        $this->salama->password = 'Strong-Real-2026';
        $this->salama->save();

        $this->artisan('ipa:demo-off')->assertSuccessful();

        $this->assertTrue((bool) UserProfile::where('user_id', $this->salama->id)->value('is_active'),
            'الحساب الذي غُيّرت كلمته لا يُعطَّل');
        $this->assertFalse((bool) UserProfile::where('user_id', $this->fani->id)->value('is_active'),
            'الباقي على الكلمة المبذورة يُعطَّل');
    }

    public function test_all_flag_disables_the_whole_demo_list(): void
    {
        $this->salama->password = 'Strong-Real-2026';
        $this->salama->save();
        $real = $this->user('sara.alahmad', 'system_admin');
        $real->password = 'Another-Real-2026';
        $real->save();

        $this->artisan('ipa:demo-off --all')->assertSuccessful();

        $this->assertFalse((bool) UserProfile::where('user_id', $this->salama->id)->value('is_active'));
        $this->assertTrue((bool) UserProfile::where('user_id', $real->id)->value('is_active'),
            'الحساب خارج القائمة التجريبية لا يُمس');
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->salama->password = 'Strong-Real-2026';
        $this->salama->save();

        $this->artisan('ipa:demo-off --dry-run')->assertSuccessful();

        $this->assertTrue((bool) UserProfile::where('user_id', $this->fani->id)->value('is_active'));
    }

    public function test_disabled_account_cannot_log_in(): void
    {
        $this->salama->password = 'Strong-Real-2026';
        $this->salama->save();
        $this->artisan('ipa:demo-off')->assertSuccessful();

        $this->post('/login', ['username' => 'fani', 'password' => CloseoutService::SEEDED_PASSWORD])
            ->assertSessionHasErrors();
        $this->assertGuest();
    }

    // ════════════ الشاشة ════════════

    public function test_screen_requires_settings_permission(): void
    {
        $this->actingAs($this->fani)->get(route('app.closeout.index'))->assertForbidden();
        $this->actingAs($this->salama)->get(route('app.closeout.index'))->assertOk();
    }

    public function test_screen_shows_inventory_and_password_state(): void
    {
        $this->incident('ش-0001');

        $this->actingAs($this->salama)->get(route('app.closeout.index'))
            ->assertOk()
            ->assertSee('data-purge="بلاغات الشاغل"', false)
            ->assertSee('data-keep="كتاب المخاطر"', false)
            ->assertSee('data-seeded="salama"', false)
            ->assertSee('مبذورة');
    }

    public function test_screen_marks_a_changed_password_as_safe(): void
    {
        $this->salama->password = 'Strong-Real-2026';
        $this->salama->save();

        $this->actingAs($this->salama->refresh())->get(route('app.closeout.index'))
            ->assertOk()
            ->assertSee('غُيّرت');
    }

    public function test_purge_needs_the_confirmation_word(): void
    {
        $this->incident('ش-0001');

        $this->actingAs($this->salama)
            ->post(route('app.closeout.purge'), ['confirm' => 'نعم'])
            ->assertSessionHasErrors('confirm');

        $this->assertSame(1, Incident::count(), 'لا حذف بلا كلمة التأكيد');

        $this->actingAs($this->salama)
            ->post(route('app.closeout.purge'), ['confirm' => 'احذف'])
            ->assertSessionHas('success');

        $this->assertSame(0, Incident::count());
    }

    public function test_screen_refuses_when_the_actor_would_disable_themselves(): void
    {
        // الفاعل تجريبي وما زال على الكلمة المبذورة: التعطيل يشمله
        $this->actingAs($this->salama)
            ->post(route('app.closeout.demo-off'))
            ->assertSessionHas('error');

        $this->assertTrue((bool) UserProfile::where('user_id', $this->salama->id)->value('is_active'));
    }

    public function test_screen_disables_once_the_actor_password_changed(): void
    {
        $this->salama->password = 'Strong-Real-2026';
        $this->salama->save();

        $this->actingAs($this->salama->refresh())
            ->post(route('app.closeout.demo-off'))
            ->assertSessionHas('success');

        $this->assertFalse((bool) UserProfile::where('user_id', $this->fani->id)->value('is_active'));
        $this->assertTrue((bool) UserProfile::where('user_id', $this->salama->id)->value('is_active'));
    }

    public function test_purge_is_atomic_and_leaves_no_orphans(): void
    {
        $risk = $this->activeRisk();
        $this->incident('ش-0001');

        app(CloseoutService::class)->purge();

        $this->assertSame(0, DB::table('risk_controls')->where('risk_id', $risk->id)->count());
        $this->assertSame(0, DB::table('incident_events')->count());
        $this->assertSame(0, DB::table('audit_logs')->count());
    }
}
