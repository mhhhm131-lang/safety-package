<?php

namespace Tests\Feature\Incident;

use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Incident\Models\Incident;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskSubCategory;
use App\Modules\Risk\Services\RiskCopyService;
use App\Modules\Risk\Services\RiskService;
use Database\Seeders\AffectedGroupsSeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * قرار ٧٢ (بكلمته ٢٠٢٦-١٠-٠٤): «البلاغ يجب يكون بخطر ويكون الخطر بداية نموذج البلاغ لكي يعمل البلاغ وإجراءاته كما خُطط له.
 * البلاغ العادي يتطلب تسجيل دخول لأن المبلّغ معروف ولا يُغلق البلاغ إلا بموافقته» · «السري نفس العادي لكن فقط بدون تسجيل دخول
 * ولا يمكن معرفة هوية المبلّغ» · «لا تغيّر النموذج، فقط ضع الخطر أول شيء قبل المكان».
 * البلاغات القائمة بلا خطر أو بلا حساب تكمل طريقها.
 */
class RiskFirstReportTest extends TestCase
{
    use RefreshDatabase;

    private Risk $ref;
    private User $emp;
    private User $salama;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class]);
        $c = RiskCategory::create(['name' => 'الكهربائية', 'abbreviation' => 'ELC', 'created_at' => now()]);
        $s = RiskSubCategory::create(['category_id' => $c->id, 'name' => 'الصعق', 'abbreviation' => 'SHK']);
        $m = app(RiskService::class)->createRisk(null, ['title' => 'صعق من مقبس تالف', 'description' => 'x', 'category_id' => $c->id, 'sub_category_id' => $s->id, 'severity' => 3, 'likelihood' => 3], 'master');
        $m->update(['status' => 'approved']);
        $this->ref = app(RiskCopyService::class)->masterToReference($m->fresh(), null);
        $this->ref->update(['status' => 'approved']);
        $this->emp = $this->user('muwazzaf', 'employee', 'hr');
        $this->salama = $this->user('salama', 'system_admin');
    }

    private function user(string $username, string $role, ?string $unitCode = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true,
            'organization_unit_id' => $unitCode ? OrganizationUnit::where('code', $unitCode)->value('id') : null, 'place_id' => Place::idByCode('HZ-06')]);
        return $u;
    }

    private function payload(array $extra = []): array
    {
        return $extra + ['description' => 'المقبس بجانب الباب يشرر', 'place_id' => Place::idByCode('HZ-06')];
    }

    public function test_the_normal_report_needs_an_account_and_returns_to_the_form_after_login(): void
    {
        $form = '/incident/normal?place=HZ-06';
        $this->get($form)->assertRedirect(route('login', ['next' => $form]));
        $this->post('/incident/normal', $this->payload(['risk_id' => $this->ref->id]))->assertRedirect(route('login', ['next' => '/incident/normal']));
        $this->assertSame(0, Incident::count(), 'أُرسل بلاغ عادي بلا حساب');

        // الدخول يعيده إلى النموذج نفسه بمكانه
        $this->post('/login', ['username' => 'muwazzaf', 'password' => '1234', 'next' => $form])->assertRedirect($form);
        $this->get($form)->assertOk();
    }

    public function test_the_risk_comes_first_and_is_required_in_the_normal_form(): void
    {
        $html = $this->actingAs($this->emp)->get('/incident/normal')->assertOk()->getContent();
        $this->assertNotFalse(mb_strpos($html, 'id="riskCat"'), 'لا قوائم خطر في النموذج');
        $this->assertLessThan(mb_strpos($html, 'name="place_id"'), mb_strpos($html, 'id="riskCat"'), 'الخطر ليس قبل المكان');
        $this->assertStringNotContainsString('<details', $html, 'الخطر ما زال مطوياً');
        $this->assertMatchesRegularExpression('/<select id="riskId" name="risk_id"[^>]*required/u', $html, 'خانة الخطر ليست إلزامية');
        $this->assertStringNotContainsString('بلا تصنيف', $html);
        $this->assertStringNotContainsString('لا يتطلب تسجيل دخول', $html);
        // بقية الخانات كما هي
        foreach (['name="location_text"', 'name="description"', 'id="img"'] as $field) $this->assertStringContainsString($field, $html);

        $this->actingAs($this->emp)->post('/incident/normal', $this->payload())->assertSessionHasErrors('risk_id');
        $this->assertSame(0, Incident::count(), 'أُرسل بلاغ بلا خطر');

        $this->actingAs($this->emp)->post('/incident/normal', $this->payload(['risk_id' => $this->ref->id]))->assertRedirect()->assertSessionHasNoErrors();
        $i = Incident::latest('id')->first();
        $this->assertSame($this->emp->id, $i->actor_id);
        $this->assertSame($this->ref->id, $i->risk_reference_id);
        $this->assertNull($i->secret_tracking_code, 'بلاغ بحساب لا يحتاج رمز تتبع');
    }

    public function test_the_secret_report_is_the_same_without_login_or_identity(): void
    {
        $html = $this->get('/incident/secret')->assertOk()->getContent();
        $this->assertLessThan(mb_strpos($html, 'name="place_id"'), mb_strpos($html, 'id="riskCat"'), 'الخطر ليس قبل المكان في السري');
        $this->assertMatchesRegularExpression('/<select id="riskId" name="risk_id"[^>]*required/u', $html);
        $this->assertStringNotContainsString('name="reporter_name"', $html);

        $this->post('/incident/secret', $this->payload())->assertSessionHasErrors('risk_id');
        $this->assertSame(0, Incident::count());

        $this->post('/incident/secret', $this->payload(['risk_id' => $this->ref->id]))->assertRedirect()->assertSessionHasNoErrors();
        $i = Incident::latest('id')->first();
        $this->assertSame('secret', $i->incident_type);
        $this->assertNull($i->actor_id);
        $this->assertNotNull($i->secret_tracking_code);
        $this->assertSame($this->ref->id, $i->risk_reference_id);

        // وصاحب الحساب إن أرسل سرياً لا يُسجَّل اسمه
        $this->actingAs($this->emp)->post('/incident/secret', $this->payload(['risk_id' => $this->ref->id]))->assertRedirect();
        $this->assertNull(Incident::latest('id')->first()->actor_id, 'السري سجّل هوية صاحب الحساب');
    }

    public function test_the_type_page_says_which_report_needs_an_account(): void
    {
        $this->get('/incident')->assertOk()
            ->assertDontSee('بلا تسجيل دخول')
            ->assertSee('بحسابك')
            ->assertSee('يخفي هويتك تماماً');
    }

    public function test_a_risk_chosen_from_the_book_stays_chosen_and_can_be_changed(): void
    {
        $html = $this->actingAs($this->emp)->get('/incident/normal?risk='.$this->ref->id.'&place=HZ-06')->assertOk()->getContent();
        $this->assertStringContainsString('name="risk_id" value="'.$this->ref->id.'"', $html);
        $this->assertStringContainsString($this->ref->title, $html);
        $this->assertLessThan(mb_strpos($html, 'name="place_id"'), mb_strpos($html, 'id="presetRisk"'), 'الخطر المحدد ليس قبل المكان');
        $this->assertStringContainsString('اختر خطراً آخر', $html);
        $this->assertStringNotContainsString('بلا تصنيف', $html);
    }

    /** ما أُرسل قبل القرار — بلا خطر وبلا حساب — يكمل طريقه: يصل المركز فيصنّفه */
    public function test_old_reports_without_a_risk_or_an_account_keep_their_path(): void
    {
        $old = $this->legacyReport(['description' => 'بلاط مكسور قرب المصعد', 'place_id' => Place::idByCode('HZ-06')]);
        $this->assertSame('received', $old->status);
        $this->assertNull($old->risk_id);
        $this->assertNotNull($old->secret_tracking_code);

        $this->actingAs($this->salama)->get("/app/incidents/{$old->id}")->assertOk()->assertSee('لم يُصنَّف بعد');
        $this->actingAs($this->salama)->post("/app/incidents/{$old->id}/link-risk", ['risk_id' => $this->ref->id])->assertRedirect();
        $this->assertSame($this->ref->id, $old->fresh()->risk_reference_id);
        $this->get('/incident/track?code='.$old->secret_tracking_code)->assertOk()->assertSee($old->code);
    }
}
