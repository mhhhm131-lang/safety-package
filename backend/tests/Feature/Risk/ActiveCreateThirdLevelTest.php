<?php

namespace Tests\Feature\Risk;

use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
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
 * المرحلة ١٨-٢ (٢٠٢٦-٠٩-١٧): شاشة «إضافة خطر في السجل الفعلي» — المستوى الثالث كان يقرأ جدول الأسباب (بلا ربط بفئة ← فارغ دائماً).
 * الآن: الأخطار المرجعية تحت الفئة الفرعية + «خطر جديد غير موجود»؛ اختيار مرجعي يربط parent_reference_id؛
 * ومسؤول السلامة يضيف الخطر الفعلي الجديد إلى السجل العام بزر.
 */
class ActiveCreateThirdLevelTest extends TestCase
{
    use RefreshDatabase;

    private RiskCategory $cat; private RiskSubCategory $sub; private User $salama; private User $mudir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class]);
        $this->cat = RiskCategory::create(['name' => 'الحريق والانفجار', 'abbreviation' => 'FI', 'created_at' => now()]);
        $this->sub = RiskSubCategory::create(['category_id' => $this->cat->id, 'name' => 'حريق المركبات والمواقف', 'abbreviation' => 'VEH']);
        $this->salama = $this->user('salama', 'system_admin');
        $this->mudir = $this->user('mudir', 'department_manager', OrganizationUnit::first()->id);
    }

    private function user(string $username, string $role, ?int $unitId = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234']);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'organization_unit_id' => $unitId]);
        return $u;
    }

    private function reference(string $title): Risk
    {
        $r = app(RiskService::class)->createRisk(null, ['title' => $title, 'description' => 'وصف '.$title, 'category_id' => $this->cat->id,
            'sub_category_id' => $this->sub->id, 'severity' => 4, 'likelihood' => 3, 'status' => 'approved'], 'reference');
        $r->update(['status' => 'approved']);
        return $r->fresh();
    }

    /** المستوى الثالث في الشاشة يأتي من الأخطار المرجعية لا من الأسباب، وفيه خيار «خطر جديد». */
    public function test_create_form_third_level_reads_reference_risks_not_causes(): void
    {
        $this->reference('اشتعال مركبة في مواقف القبو');
        $h = $this->actingAs($this->mudir)->get('/app/risk/active/create')->assertOk()->getContent();
        $this->assertStringNotContainsString('ajax/causes', $h);
        $this->assertStringContainsString('risks-by-sub-category', $h);
        $this->assertStringContainsString('name="parent_reference_id"', $h);
        $this->assertStringContainsString('خطر جديد غير موجود في السجل', $h);
        // المسار القائم يعطي الأخطار الثلاثة تحت الفرعية لمن يملك التفعيل
        $this->reference('حريق بطاريات المركبات الكهربائية'); $this->reference('اشتعال تسرب وقود');
        $this->actingAs($this->mudir)->getJson("/app/risk/registry/tree/reference/risks-by-sub-category/{$this->sub->id}")->assertOk()->assertJsonCount(3);
    }

    /** اختيار خطر مرجعي: يُربط به ويُشتق اسمه منه إن تُرك فارغاً، وتُنسخ مراحله بأسبابها وإجراءاتها ومتأثريها (ما كشفه المستخدم على المنشور). */
    public function test_store_with_reference_links_parent_and_derives_title(): void
    {
        $ref = $this->reference('اشتعال مركبة في مواقف القبو');
        $gid = \App\Modules\Risk\Models\AffectedGroup::first()->id;
        app(RiskService::class)->persistAllPhases($ref, ['proactive' => ['preventive_action' => 'منع الوقوف فوق المصارف وفحص التمديدات',
            'cause_names' => ['تسرب وقود', 'ماس كهربائي في مركبة'], 'affected_group_ids' => [$gid], 'affected_impact' => [$gid => 4]]]);
        // الحقول الفارغة كما يرسلها النموذج لا تمحو المنسوخ؛ والمكتوب يغلب
        $this->actingAs($this->mudir)->post('/app/risk/active/create', ['category_id' => $this->cat->id, 'sub_category_id' => $this->sub->id,
            'parent_reference_id' => $ref->id, 'title' => '', 'severity' => 4, 'likelihood' => 2, 'scope_type' => 'org_unit',
            'organization_unit_id' => OrganizationUnit::first()->id, 'place_id' => Place::idByCode('HZ-01'),
            'phases' => ['proactive' => ['preventive_action' => '', 'corrective_action' => 'إخلاء المواقف وإطفاء بالرغوة', 'cause_names' => ['']],
                         'reactive' => ['preventive_action' => '', 'cause_names' => ['']]]])->assertRedirect();
        $a = Risk::where('risk_type', 'active')->first();
        $this->assertNotNull($a);
        $this->assertSame($ref->id, $a->parent_reference_id);
        $this->assertSame('اشتعال مركبة في مواقف القبو', $a->title);
        $pro = $a->phases()->where('phase', 'proactive')->with(['causes', 'affectedGroups'])->first();
        $this->assertSame('منع الوقوف فوق المصارف وفحص التمديدات', $pro->preventive_action);
        $this->assertSame('إخلاء المواقف وإطفاء بالرغوة', $pro->corrective_action);
        $this->assertEqualsCanonicalizing(['تسرب وقود', 'ماس كهربائي في مركبة'], $pro->causes->pluck('name')->all());
        $this->assertSame([$gid], $pro->affectedGroups->pluck('id')->all());
        $this->assertSame(3, $a->phases()->count());
        // خطر مرجعي من فئة أخرى لا يُقبل
        $other = RiskSubCategory::create(['category_id' => $this->cat->id, 'name' => 'أخرى', 'abbreviation' => 'OTH']);
        $this->actingAs($this->mudir)->post('/app/risk/active/create', ['category_id' => $this->cat->id, 'sub_category_id' => $other->id,
            'parent_reference_id' => $ref->id, 'severity' => 1, 'likelihood' => 1])->assertSessionHasErrors('parent_reference_id');
    }

    /** خطر جديد غير موجود: بلا مرجع، الاسم بيده؛ ومسؤول السلامة وحده يضيفه إلى السجل العام بزر. */
    public function test_new_risk_without_reference_and_safety_officer_promotes_it(): void
    {
        $this->actingAs($this->mudir)->post('/app/risk/active/create', ['category_id' => $this->cat->id, 'sub_category_id' => $this->sub->id,
            'parent_reference_id' => '', 'title' => 'انزلاق على منحدر المواقف عند المطر', 'severity' => 3, 'likelihood' => 3,
            'scope_type' => 'org_unit', 'organization_unit_id' => OrganizationUnit::first()->id])->assertRedirect();
        $a = Risk::where('risk_type', 'active')->first();
        $this->assertNull($a->parent_reference_id);
        $this->assertSame('انزلاق على منحدر المواقف عند المطر', $a->title);

        // زر «أضفه إلى السجل العام» لمسؤول السلامة فقط، وعلى الخطر الفعلي غير المرتبط بمرجع
        $this->actingAs($this->salama)->get("/app/risk/{$a->id}/detail")->assertOk()->assertSee('أضفه إلى السجل العام');
        $this->actingAs($this->mudir)->get("/app/risk/{$a->id}/detail")->assertOk()->assertDontSee('أضفه إلى السجل العام');
        $this->actingAs($this->mudir)->post("/app/risk/{$a->id}/to-reference")->assertForbidden();
        $this->actingAs($this->salama)->post("/app/risk/{$a->id}/to-reference")->assertRedirect()->assertSessionHas('success');
        $ref = Risk::where('risk_type', 'reference')->first();
        $this->assertNotNull($ref);
        $this->assertSame('انزلاق على منحدر المواقف عند المطر', $ref->title);
        $this->assertSame($this->sub->id, $ref->sub_category_id);
        $this->assertSame($ref->id, $a->fresh()->parent_reference_id);
        // ثانية لا تكرر
        $this->actingAs($this->salama)->post("/app/risk/{$a->id}/to-reference")->assertRedirect();
        $this->assertSame(1, Risk::where('risk_type', 'reference')->count());
        $this->actingAs($this->salama)->get("/app/risk/{$a->id}/detail")->assertOk()->assertDontSee('أضفه إلى السجل العام');
    }
}
