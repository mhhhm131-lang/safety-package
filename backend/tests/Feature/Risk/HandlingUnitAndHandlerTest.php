<?php

namespace Tests\Feature\Risk;

use App\Core\Intents\IntentRegistry;
use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Services\DeptSync;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskSubCategory;
use App\Modules\Risk\Services\RiskService;
use Database\Seeders\AffectedGroupsSeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * خطة المعالج — الخطوة ١ (معتمدة بكلمته ٢٠٢٦-١٠-٠٨، `backend/docs/plan-handler-2026-10-07.html`):
 *   «الإدارة المعالجة» في السجل العام يكتبها مسؤول السلامة وحده من قائمة الهيكل، وتُحفظ باسمها.
 *   «المعالج» يكتبه مدير الإدارة المعالجة من «إدارتي» (شاشة على مثال «فنيّي»)، خانة واحدة في العام لا غير.
 *   الأعمدة: «المسؤول» ← «المنسق»، «الفريق التنفيذي» ← «المعالج». نص الكتاب «من يطبّق الضوابط» لا يُمس.
 *   وحدة هي الإدارة المعالجة لخطر لا تُحذف (من شاشة الهيكل ولا من مزامنة اللوحة — الملاحظة ٤٢)، تُعطَّل.
 * كل فحص يقرأ ما في القاعدة أو ما تعيده الشاشة، لا وصف النتيجة.
 */
class HandlingUnitAndHandlerTest extends TestCase
{
    use RefreshDatabase, RiskFixtures;

    private RiskCategory $cat;
    private RiskSubCategory $sub;
    private OrganizationUnit $fac;
    private User $salama;
    private User $duty;
    private User $marafiq;
    private User $hrManager;
    private User $tech;
    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class]);
        $this->cat = RiskCategory::create(['name' => 'الكهربائية', 'abbreviation' => 'EL', 'is_active' => true, 'created_at' => now()]);
        $this->sub = RiskSubCategory::create(['category_id' => $this->cat->id, 'name' => 'الصعق', 'abbreviation' => 'SH']);
        // «المرافق والصيانة» ليست في الهيكل المبذور — تُضاف من شاشة الهيكل تحت الشؤون الإدارية والهندسية (الخطة، البند ٣)
        $this->fac = OrganizationUnit::create(['code' => 'fac', 'name' => 'المرافق والصيانة', 'unit_type' => 'section', 'parent_id' => $this->orgUnit('adm-eng')->id, 'order' => 99]);
        $this->salama = $this->makeUser('system_admin');
        $this->duty = $this->makeUser('system_staff');
        $this->marafiq = $this->makeUser('facilities_manager', 'fac', 'مدير المرافق');
        $this->hrManager = $this->makeUser('department_manager', 'hr', 'مدير الموارد');
        $this->tech = $this->makeUser('tech_electrical', 'fac', 'فني الكهرباء أحمد');
        $this->outsider = $this->makeUser('employee', 'hr', 'موظف الموارد');
    }

    private function body(string $title, array $extra = []): array
    {
        return array_merge(['category_id' => $this->cat->id, 'sub_category_id' => $this->sub->id, 'severity' => 3, 'likelihood' => 2, 'title' => $title], $extra);
    }

    private function approvedRisk(string $title, ?OrganizationUnit $handling = null): Risk
    {
        $r = Risk::create(['risk_type' => 'reference', 'title' => $title, 'description' => 'x', 'category_id' => $this->cat->id, 'sub_category_id' => $this->sub->id,
            'severity' => 3, 'likelihood' => 2, 'status' => 'approved', 'handling_unit_id' => $handling?->id, 'handling_unit_name' => $handling?->name]);
        app(RiskService::class)->ensurePhases($r);
        return $r;
    }

    private function detail(User $u, Risk $r): array
    {
        return $this->actingAs($u)->getJson(url('app/risk/registry/tree/reference/risk/'.$r->id))->assertOk()->json();
    }

    /** مسؤول السلامة يكتب الإدارة المعالجة من الهيكل؛ تُحفظ باسمها وتظهر في تفاصيل الخطر */
    public function test_the_safety_officer_sets_the_handling_unit_by_name_in_the_general_register(): void
    {
        $this->actingAs($this->salama)->post(route('risk.reference.store'), $this->body('غطاء مقبس مكسور', ['handling_unit_id' => $this->fac->id]))
            ->assertRedirect(route('risk.reference.index'));
        $r = Risk::where('title', 'غطاء مقبس مكسور')->firstOrFail();
        $this->assertSame($this->fac->id, $r->handling_unit_id);
        $this->assertSame('المرافق والصيانة', $r->handling_unit_name);
        $this->assertNull($r->handler_specialty);
        $this->assertNull($r->handler_user_id);

        $j = $this->detail($this->hrManager, $r);
        $this->assertSame('المرافق والصيانة', $j['handling_unit']);
        $this->assertNull($j['handler']);

        // التعديل ينقلها إلى إدارة أخرى، ولا يمسّ نص الكتاب «من يطبّق الضوابط» وقد خرجت خاناته من النموذج
        $op = $r->phases()->firstOrFail(); // الخطوة ٦: صف واحد بلا أطوار
        $op->update(['responsible_org_unit_text' => '٨ المنسق في المكان (الجولة) · ١٩ فني الكهرباء (الإصلاح)']);
        $r->forceFill(['handler_specialty' => 'tech_electrical', 'handler_set_by_id' => $this->marafiq->id, 'handler_set_at' => now()])->save(); // معالج من الإدارة الأولى
        $this->actingAs($this->salama)->post(route('risk.reference.update', $r), $this->body('غطاء مقبس مكسور', ['handling_unit_id' => $this->orgUnit('hr')->id]))
            ->assertRedirect(route('risk.reference.index'));
        $r->refresh();
        $this->assertSame($this->orgUnit('hr')->id, $r->handling_unit_id);
        $this->assertSame('الإدارة العامة للموارد البشرية', $r->handling_unit_name);
        $this->assertNull($r->handler_specialty, 'معالج الإدارة السابقة انتقل مع تغيير الإدارة المعالجة');
        $this->assertNull($r->handler_set_by_id);
        $this->assertSame('٨ المنسق في المكان (الجولة) · ١٩ فني الكهرباء (الإصلاح)', $op->fresh()->responsible_org_unit_text);
        // والتعديل بلا تغيير الإدارة لا يمسّ المعالج
        $r->forceFill(['handler_specialty' => 'tech_hvac', 'handler_set_by_id' => $this->hrManager->id, 'handler_set_at' => now()])->save();
        $this->actingAs($this->salama)->post(route('risk.reference.update', $r), $this->body('غطاء مقبس مكسور (عُدّل)', ['handling_unit_id' => $this->orgUnit('hr')->id]))->assertRedirect();
        $this->assertSame('tech_hvac', $r->fresh()->handler_specialty);

        // نموذج التعديل يعرض الخانة لمسؤول السلامة، ونص الكتاب مقروءاً، بلا خانات «الجهة» لكل طور
        $html = (string) $this->actingAs($this->salama)->get(route('risk.reference.edit', $r))->assertOk()->getContent();
        $this->assertStringContainsString('name="handling_unit_id"', $html);
        $this->assertStringContainsString('من يطبّق الضوابط (من الكتاب)', $html);
        $this->assertStringContainsString('١٩ فني الكهرباء (الإصلاح)', $html);
        $this->assertStringNotContainsString('responsible_org_unit_id', $html);
        $this->assertStringNotContainsString('responsible_user_text', $html);
        $create = (string) $this->actingAs($this->salama)->get(route('risk.reference.create'))->assertOk()->getContent();
        $this->assertStringContainsString('name="handling_unit_id"', $create);
        $this->assertStringNotContainsString('responsible_org_unit_id', $create);
        // بكلمته (٢٠٢٦-١٠-٠٨): خانة «نوع الخطر (المستوى الثالث)» خرجت من النموذجين — فئة ← فرعية ← خطر
        foreach ([$create, $html] as $form) {
            $this->assertStringNotContainsString('name="risk_type_category_id"', $form);
            $this->assertStringNotContainsString('riskTypeSelect', $form);
            $this->assertStringContainsString('id="subCatSelect"', $form);
        }
    }

    /** الأعمدة في شاشة العام: الإدارة المعالجة والمعالج والمنسق — ولا «الفريق التنفيذي» */
    public function test_the_general_register_columns_are_renamed(): void
    {
        $html = (string) $this->actingAs($this->salama)->get(route('risk.reference.index'))->assertOk()->getContent();
        foreach (['<th>الإدارة المعالجة</th>', '<th>المعالج</th>', '<th>المنسق</th>', '<th>الإدارة</th>'] as $th) $this->assertStringContainsString($th, $html);
        $this->assertStringNotContainsString('الفريق التنفيذي', $html);
        $this->assertStringNotContainsString('<th>المسؤول</th>', $html);
        $this->assertStringNotContainsString('owner_department', $html);
    }

    /** غير مسؤول السلامة لا يكتب الإدارة المعالجة: مقترح المناوب يُحفظ بلا إدارة، ومدير الإدارة لا يصل نموذج العام */
    public function test_nobody_else_sets_the_handling_unit(): void
    {
        $this->actingAs($this->duty)->post(route('risk.reference.store'), $this->body('مقترح بإدارة', ['handling_unit_id' => $this->fac->id]))->assertRedirect();
        $p = Risk::where('title', 'مقترح بإدارة')->firstOrFail();
        $this->assertSame('pending_approval', $p->status);
        $this->assertNull($p->handling_unit_id, 'المناوب كتب الإدارة المعالجة وهي لمسؤول السلامة وحده');
        $this->assertNull($p->handling_unit_name);
        $this->assertStringNotContainsString('name="handling_unit_id"', (string) $this->actingAs($this->duty)->get(route('risk.reference.create'))->assertOk()->getContent());

        $r = $this->approvedRisk('خطر معتمد', $this->fac);
        $this->actingAs($this->marafiq)->post(route('risk.reference.update', $r), $this->body('خطر معتمد', ['handling_unit_id' => $this->orgUnit('hr')->id]))->assertForbidden();
        $this->assertSame($this->fac->id, $r->fresh()->handling_unit_id);
    }

    /** مدير الإدارة المعالجة يسمّي المعالج من «إدارتي» — تخصصاً أو شخصاً من إدارته — ويكتب هذه الخانة وحدها */
    public function test_the_handling_unit_manager_names_the_handler_from_his_screen(): void
    {
        $r = $this->approvedRisk('بلاطة مكسورة', $this->fac);
        $other = $this->approvedRisk('تنمّر', $this->orgUnit('hr'));
        $none = $this->approvedRisk('خطر بلا إدارة');

        // الشاشة: مدير المرافق يرى خطره وحده؛ مدير الموارد يرى خطره وحده؛ الموظف ٤٠٣
        $html = (string) $this->actingAs($this->marafiq)->get(route('risk.handlers.index'))->assertOk()->getContent();
        $this->assertStringContainsString('بلاطة مكسورة', $html);
        $this->assertStringNotContainsString('تنمّر', $html);
        $this->assertStringNotContainsString('خطر بلا إدارة', $html);
        $this->assertStringContainsString('فني الكهرباء أحمد', $html); // شخص من إدارته
        $this->assertStringNotContainsString('موظف الموارد', $html);
        $this->assertStringContainsString('1 بلا معالج', $html);
        $hr = (string) $this->actingAs($this->hrManager)->get(route('risk.handlers.index'))->assertOk()->getContent();
        $this->assertStringContainsString('تنمّر', $hr);
        $this->assertStringNotContainsString('بلاطة مكسورة', $hr);
        $this->actingAs($this->outsider)->get(route('risk.handlers.index'))->assertForbidden();
        $this->actingAs($this->salama)->get(route('risk.handlers.index'))->assertForbidden(); // الخانة لمدير الإدارة، لا لمسؤول السلامة

        // تخصص
        $this->actingAs($this->marafiq)->post(route('risk.handlers.set', $r), ['handler' => 'spec:tech_electrical'])
            ->assertRedirect(route('risk.handlers.index'))->assertSessionHas('ok', fn ($v) => str_contains((string) $v, 'يعالجه فني الكهرباء'));
        $r->refresh();
        $this->assertSame('tech_electrical', $r->handler_specialty);
        $this->assertNull($r->handler_user_id);
        $this->assertSame($this->marafiq->id, $r->handler_set_by_id);
        $this->assertNotNull($r->handler_set_at);
        $j = $this->detail($this->hrManager, $r);
        $this->assertSame('فني الكهرباء', $j['handler']);
        $this->assertSame('مدير المرافق', $j['handler_set_by']);
        $this->assertStringContainsString('كتبه مدير المرافق', (string) $this->actingAs($this->marafiq)->get(route('risk.handlers.index'))->getContent());

        // شخص من إدارته
        $this->actingAs($this->marafiq)->post(route('risk.handlers.set', $r), ['handler' => 'user:'.$this->tech->id])->assertRedirect();
        $r->refresh();
        $this->assertSame($this->tech->id, $r->handler_user_id);
        $this->assertNull($r->handler_specialty);
        $this->assertSame('فني الكهرباء أحمد', $this->detail($this->hrManager, $r)['handler']);

        // شخص من غير إدارته: يُرفض ولا يتغير شيء
        $this->actingAs($this->marafiq)->from(route('risk.handlers.index'))->post(route('risk.handlers.set', $r), ['handler' => 'user:'.$this->outsider->id])
            ->assertRedirect(route('risk.handlers.index'))->assertSessionHasErrors('handler');
        $this->assertSame($this->tech->id, $r->fresh()->handler_user_id);
        $this->actingAs($this->marafiq)->post(route('risk.handlers.set', $r), ['handler' => 'spec:plumber'])->assertSessionHasErrors('handler');

        // خطر ليس على إدارته: ٤٠٤؛ ومدير إدارة أخرى على خطره: ٤٠٤
        $this->actingAs($this->marafiq)->post(route('risk.handlers.set', $other), ['handler' => 'spec:tech_hvac'])->assertNotFound();
        $this->actingAs($this->hrManager)->post(route('risk.handlers.set', $r), ['handler' => 'spec:tech_hvac'])->assertNotFound();
        $this->assertSame($this->tech->id, $r->fresh()->handler_user_id);
        $this->assertNull($other->fresh()->handler_specialty);

        // لا يمسّ غير خانة المعالج: ما يُرسل معها يُهمل
        $this->actingAs($this->marafiq)->post(route('risk.handlers.set', $r), ['handler' => 'spec:tech_hvac', 'title' => 'عنوان مزوّر', 'handling_unit_id' => $this->orgUnit('hr')->id, 'severity' => 5])->assertRedirect();
        $r->refresh();
        $this->assertSame('بلاطة مكسورة', $r->title);
        $this->assertSame($this->fac->id, $r->handling_unit_id);
        $this->assertSame(3, $r->severity);
        $this->assertSame('tech_hvac', $r->handler_specialty);

        // المحو
        $this->actingAs($this->marafiq)->post(route('risk.handlers.set', $r), ['handler' => ''])->assertRedirect();
        $r->refresh();
        $this->assertNull($r->handler_specialty);
        $this->assertNull($r->handler_user_id);
        $this->assertNull($r->handler_set_by_id);
    }

    /** الباب في «أريد أن»: لمدير الإدارة المربوط بوحدة، لا للموظف ولا لمسؤول السلامة */
    public function test_the_door_appears_for_unit_managers_only(): void
    {
        $keys = fn (User $u) => IntentRegistry::forUser($u)->map(fn ($i) => $i->key)->all();
        $this->assertContains('handlers', $keys($this->marafiq));
        $this->assertContains('handlers', $keys($this->hrManager));
        $this->assertNotContains('handlers', $keys($this->outsider));
        $this->assertNotContains('handlers', $keys($this->salama));
        $this->assertNotContains('handlers', $keys($this->makeUser('department_manager'))); // بلا وحدة
        $this->actingAs($this->marafiq)->get('/app')->assertOk()->assertSee('معالجو أخطار إدارتي');
    }

    /** وحدة هي الإدارة المعالجة لخطر لا تُحذف: شاشة الهيكل ترفض وتسمّي الخطر، ومزامنة اللوحة تعطّلها ولا تحذفها */
    public function test_a_unit_that_handles_risks_is_never_deleted_only_deactivated(): void
    {
        // وحدة بلا أقسام وبلا موظفين — لا يمنع حذفها إلا الخطر المربوط بها
        $sec = OrganizationUnit::create(['code' => 'sec', 'name' => 'الأمن والسلامة', 'unit_type' => 'section', 'parent_id' => $this->orgUnit('adm-eng')->id, 'order' => 98]);
        $r = $this->approvedRisk('بلاطة مكسورة', $sec);
        $r->update(['code' => 'ME-01-01']);

        $this->actingAs($this->salama)->from(route('app.org.index'))->delete(route('app.org.destroy', $sec))
            ->assertRedirect(route('app.org.index'))->assertSessionHas('err', fn ($v) => str_contains((string) $v, 'ME-01-01 بلاطة مكسورة'));
        $this->assertDatabaseHas('organization_units', ['id' => $sec->id]);

        // اللوحة تكتب الإدارات بلا «الأمن والسلامة» (الملاحظة ٤٢): تُعطَّل ويبقى الخطر مربوطاً بها
        $rows = OrganizationUnit::where('id', '!=', $sec->id)->orderBy('order')->get()
            ->map(fn ($u) => ['id' => $u->code, 'name' => $u->name, 'parent' => $u->parent?->code ?? '', 'place' => 'HZ-06', 'mgr' => ''])->all();
        app(DeptSync::class)->fromDocument($rows);
        $fresh = OrganizationUnit::find($sec->id);
        $this->assertNotNull($fresh, 'مزامنة اللوحة حذفت وحدة مربوطة بخطر');
        $this->assertFalse($fresh->is_active);
        $this->assertSame($sec->id, $r->fresh()->handling_unit_id);
        $this->assertSame('الأمن والسلامة', $this->detail($this->salama, $r)['handling_unit']);

        // وحدة بلا أخطار تُحذف كما كانت
        $tmp = OrganizationUnit::create(['code' => 'tmp', 'name' => 'وحدة مؤقتة', 'unit_type' => 'section', 'order' => 100]);
        $this->actingAs($this->salama)->delete(route('app.org.destroy', $tmp))->assertRedirect();
        $this->assertDatabaseMissing('organization_units', ['id' => $tmp->id]);
    }
}
