<?php

namespace Tests\Feature\Risk;

use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
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
 * خطة المعالج — الخطوة ٤ (معتمدة بكلمته ٢٠٢٦-١٠-٠٨): خانة «المعالج» تخرج من التفعيل المفرد والدفعة والإضافة والتعديل في الخاص،
 * ومعها قائمة «كل حسابات المعهد»؛ يبقى المنسق. والخاص يعرض «الإدارة المعالجة» و«المعالج» من العام قراءةً —
 * فتغيير مسؤول السلامة أو مدير الإدارة المعالجة في العام يظهر في النسخة فوراً بلا تفعيل جديد. كل فحص يقرأ الشاشة أو القاعدة.
 */
class ActivationReadsHandlerFromGeneralTest extends TestCase
{
    use RefreshDatabase, RiskFixtures;

    private RiskCategory $cat;
    private RiskSubCategory $sub;
    private OrganizationUnit $fac;
    private Risk $ref;
    private User $salama;
    private User $marafiq;
    private User $hrMgr;
    private User $hrCoord;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class]);
        $this->cat = RiskCategory::create(['name' => 'الكهربائية', 'abbreviation' => 'EL', 'is_active' => true, 'created_at' => now()]);
        $this->sub = RiskSubCategory::create(['category_id' => $this->cat->id, 'name' => 'الصعق', 'abbreviation' => 'SH']);
        $this->fac = OrganizationUnit::create(['code' => 'fac', 'name' => 'المرافق والصيانة', 'unit_type' => 'section', 'parent_id' => $this->orgUnit('adm-eng')->id, 'order' => 99]);
        $this->ref = Risk::create(['risk_type' => 'reference', 'title' => 'غطاء مقبس مكسور', 'description' => 'x', 'category_id' => $this->cat->id, 'sub_category_id' => $this->sub->id,
            'severity' => 3, 'likelihood' => 2, 'status' => 'approved', 'handling_unit_id' => $this->fac->id, 'handling_unit_name' => 'المرافق والصيانة']);
        app(RiskService::class)->ensurePhases($this->ref);
        $this->salama = $this->makeUser('system_admin');
        $this->marafiq = $this->makeUser('facilities_manager', 'fac', 'مدير المرافق');
        $this->hrMgr = $this->makeUser('department_manager', 'hr', 'مدير الموارد');
        $this->hrCoord = $this->makeUser('safety_coordinator', 'hr', 'منسق الموارد');
    }

    private function body(array $extra = []): array
    {
        return $extra + ['scope_type' => 'org_unit', 'organization_unit_id' => $this->orgUnit('hr')->id, 'place_id' => Place::idByCode('HZ-06'),
            'severity' => 3, 'likelihood' => 2, 'assigned_coordinator_id' => $this->hrCoord->id];
    }

    /** نموذج التفعيل: بلا خانة «المعالج» ولا قائمة الحسابات لها، ويعرض معالج العام قراءةً */
    public function test_the_activation_form_shows_the_general_handler_read_only_and_asks_only_for_the_coordinator(): void
    {
        $html = (string) $this->actingAs($this->hrMgr)->get(route('risk.activate.form', $this->ref))->assertOk()->getContent();
        $this->assertStringNotContainsString('name="assigned_field_team_id"', $html);
        $this->assertStringContainsString('name="assigned_coordinator_id"', $html);
        $this->assertStringContainsString('الإدارة المعالجة: <b style="color:var(--text-main);">المرافق والصيانة</b>', $html);
        $this->assertStringContainsString('لم يُكتب بعد', $html);

        $this->ref->forceFill(['handler_specialty' => 'tech_electrical', 'handler_set_by_id' => $this->marafiq->id, 'handler_set_at' => now()])->save();
        $html = (string) $this->actingAs($this->hrMgr)->get(route('risk.activate.form', $this->ref))->assertOk()->getContent();
        $this->assertStringContainsString('فني الكهرباء', $html);
        $this->assertStringContainsString('كتبه مدير المرافق', $html);

        // التفعيل بلا معالج يمرّ، والمنسق وحده شرط
        $this->actingAs($this->hrMgr)->post(route('risk.activate', $this->ref), $this->body())->assertRedirect(route('risk.active.index'))->assertSessionHasNoErrors();
        $copy = Risk::where('risk_type', 'active')->where('parent_reference_id', $this->ref->id)->firstOrFail();
        $this->assertNull($copy->assigned_field_team_id);
        $this->assertSame($this->hrCoord->id, $copy->assigned_coordinator_id);
        $this->assertSame('active', $copy->status);
    }

    /** الدفعة: بلا خانة «المعالج» في النافذة، والتفعيل دفعةً بلا معالج يمرّ */
    public function test_bulk_activation_has_no_handler_field_and_works_without_one(): void
    {
        $other = Risk::create(['risk_type' => 'reference', 'title' => 'خطر ثانٍ', 'description' => 'x', 'category_id' => $this->cat->id, 'sub_category_id' => $this->sub->id,
            'severity' => 2, 'likelihood' => 2, 'status' => 'approved']);
        app(RiskService::class)->ensurePhases($other);
        $html = (string) $this->actingAs($this->hrMgr)->get(route('risk.reference.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="bulkHandler"', $html);
        $this->assertStringNotContainsString('name="assigned_field_team_id"', $html);

        $this->actingAs($this->hrMgr)->postJson(route('risk.activate.bulk'), ['risk_ids' => [$this->ref->id, $other->id], 'scope_type' => 'org_unit', 'organization_unit_id' => $this->orgUnit('hr')->id])
            ->assertOk()->assertJson(['created' => 2]);
        $copies = Risk::where('risk_type', 'active')->whereIn('parent_reference_id', [$this->ref->id, $other->id])->get();
        $this->assertCount(2, $copies);
        foreach ($copies as $c) {
            $this->assertNull($c->assigned_field_team_id);
            $this->assertSame($this->hrCoord->id, $c->assigned_coordinator_id, 'منسق الدفعة ليس منسق الوحدة');
        }
    }

    /** الخاص يقرأ من العام قراءةً: الإضافة والتعديل بلا خانة، والجدول والتفاصيل يعرضان ما في العام الآن */
    public function test_the_unit_register_reads_the_handling_unit_and_handler_from_the_general_register(): void
    {
        $this->actingAs($this->hrMgr)->post(route('risk.activate', $this->ref), $this->body())->assertRedirect();
        $copy = Risk::where('risk_type', 'active')->where('parent_reference_id', $this->ref->id)->firstOrFail();

        foreach ([route('risk.active.create'), route('risk.active.edit', $copy)] as $url) {
            $html = (string) $this->actingAs($this->hrMgr)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('name="assigned_field_team_id"', $html, $url);
            $this->assertStringContainsString('name="assigned_coordinator_id"', $html, $url);
        }
        $edit = (string) $this->actingAs($this->hrMgr)->get(route('risk.active.edit', $copy))->getContent();
        $this->assertStringContainsString('الإدارة المعالجة: <b style="color:var(--text-main);">المرافق والصيانة</b>', $edit);
        $this->assertStringContainsString('لم يُكتب بعد', $edit);

        // التعديل بلا معالج يمرّ ولا يمسّ شيئاً من العام
        // (نموذج التعديل يرسل أصل النسخة parent_reference_id كما يعرضه؛ بلا هذه الخانة يفقد الخطر أصله — سلوك قائم، لوحظ ولم يُلمس)
        $this->actingAs($this->hrMgr)->post(route('risk.active.update', $copy), $this->body(['category_id' => $this->cat->id, 'sub_category_id' => $this->sub->id,
            'parent_reference_id' => $this->ref->id, 'title' => 'غطاء مقبس مكسور (نسخة)']))
            ->assertRedirect(route('risk.active.index'))->assertSessionHasNoErrors();
        $this->assertSame('المرافق والصيانة', $this->ref->fresh()->handling_unit_name);
        $this->assertSame($this->ref->id, $copy->fresh()->parent_reference_id);

        // تفاصيل النسخة (JSON) والجدول: من العام
        $j = $this->actingAs($this->hrMgr)->getJson(url('app/risk/registry/tree/active/risk/'.$copy->id))->assertOk()->json();
        $this->assertSame('المرافق والصيانة', $j['handling_unit']);
        $this->assertNull($j['handler']);
        $index = (string) $this->actingAs($this->hrMgr)->get(route('risk.active.index'))->assertOk()->getContent();
        foreach (['<th>الإدارة المعالجة</th>', '<th>المنسق</th>', '<th>المعالج</th>'] as $th) $this->assertStringContainsString($th, $index);
        $this->assertStringNotContainsString('الفريق التنفيذي', $index);
        $this->assertStringNotContainsString('owner_department', $index);

        // مدير المرافق يسمّي في العام ← النسخة تعرضه فوراً بلا تفعيل جديد
        $this->actingAs($this->marafiq)->post(route('risk.handlers.set', $this->ref), ['handler' => 'spec:tech_electrical'])->assertRedirect();
        $j = $this->actingAs($this->hrMgr)->getJson(url('app/risk/registry/tree/active/risk/'.$copy->id))->assertOk()->json();
        $this->assertSame('فني الكهرباء', $j['handler']);
        $this->assertSame('مدير المرافق', $j['handler_set_by']);
        $this->assertNull($copy->fresh()->assigned_field_team_id, 'النسخة نسخت المعالج بدل أن تقرأه');
        $this->assertStringContainsString('فني الكهرباء', (string) $this->actingAs($this->hrMgr)->get(route('risk.active.edit', $copy))->getContent());

        // ومسؤول السلامة نقل الإدارة المعالجة ← النسخة تتبع
        $this->actingAs($this->salama)->post(route('risk.reference.update', $this->ref), ['category_id' => $this->cat->id, 'sub_category_id' => $this->sub->id,
            'severity' => 3, 'likelihood' => 2, 'title' => 'غطاء مقبس مكسور', 'handling_unit_id' => $this->orgUnit('hr')->id])->assertRedirect();
        $j = $this->actingAs($this->hrMgr)->getJson(url('app/risk/registry/tree/active/risk/'.$copy->id))->assertOk()->json();
        $this->assertSame('الإدارة العامة للموارد البشرية', $j['handling_unit']);
        $this->assertNull($j['handler']);
    }
}
