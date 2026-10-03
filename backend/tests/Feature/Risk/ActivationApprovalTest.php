<?php

namespace Tests\Feature\Risk;

use App\Core\Inbox\InboxService;
use App\Core\Inbox\Task;
use App\Models\User;
use App\Modules\Governance\Models\AppNotification;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
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
 * قرار ٧٠ (بكلمته ٢٠٢٦-١٠-٠٣: «أي إدارة أو قسم أو فرع يريد تفعيل خطر من السجل العام عبر منسق سلامة القسم أو الإدارة أو الفرع،
 * ويعتمد من مدير القسم أو الإدارة أو الفرع»): من يعتمد الخطر إن فعّله بنفسه صار نشطاً؛ غيره ينتظر المعتمد.
 * منسق السلامة نطاقه نطاق مديره — وحدته وما تحتها، لا «عام» ولا وحدة غيره. وما لم يُعتمد لا يعمل.
 * كُشف على المنشور: فعّل منسق القسم خطراً فصار «نشط» فوراً ولم تصل مديره بطاقة.
 */
class ActivationApprovalTest extends TestCase
{
    use RefreshDatabase, RiskFixtures;

    private Risk $ref;
    private RiskSubCategory $sub;
    private OrganizationUnit $hr;
    private OrganizationUnit $it;
    private User $salama;
    private User $munawib;
    private User $coord;
    private User $mudir;
    private User $other;
    private User $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class]);
        $this->hr = $this->orgUnit('hr');
        $this->it = $this->orgUnit('it');
        $this->ref = $this->reference('الكهربائية', 'ELC', 'الصعق', 'SHK', 'صعق من مقبس تالف');
        $this->salama = $this->makeUser('system_admin');
        $this->munawib = $this->makeUser('system_staff');
        $this->coord = $this->makeUser('safety_coordinator', 'hr');
        $this->mudir = $this->makeUser('department_manager', 'hr');
        $this->other = $this->makeUser('department_manager', 'it');
        $this->handler = $this->makeUser('tech_electrical');
    }

    private function reference(string $cat, string $ca, string $sub, string $sa, string $title): Risk
    {
        $c = RiskCategory::create(['name' => $cat, 'abbreviation' => $ca, 'created_at' => now()]);
        $this->sub = RiskSubCategory::create(['category_id' => $c->id, 'name' => $sub, 'abbreviation' => $sa]);
        $m = app(RiskService::class)->createRisk(null, ['title' => $title, 'description' => 'x', 'category_id' => $c->id, 'sub_category_id' => $this->sub->id, 'severity' => 3, 'likelihood' => 3], 'master');
        $m->update(['status' => 'approved']);
        $r = app(RiskCopyService::class)->masterToReference($m->fresh(), null);
        $r->update(['status' => 'approved']);
        return $r;
    }

    private function card(User $u, string $key): ?Task
    {
        return app(InboxService::class)->forUser($u->fresh())->first(fn (Task $t) => $t->key === $key);
    }

    /** ضغطة «تفعيل» في نموذج التفعيل بحساب صاحبها */
    private function activate(User $by, ?OrganizationUnit $unit, array $extra = [])
    {
        return $this->actingAs($by)->post(route('risk.activate', $this->ref), $extra + [
            'scope_type' => $unit ? 'org_unit' : 'general', 'organization_unit_id' => $unit?->id,
            'severity' => 3, 'likelihood' => 3,
            'assigned_coordinator_id' => $this->coord->id, 'assigned_field_team_id' => $this->handler->id,
        ]);
    }

    private function last(): ?Risk
    {
        return Risk::where('risk_type', 'active')->where('parent_reference_id', $this->ref->id)->latest('id')->first();
    }

    private function registered(): int
    {
        return Risk::where('risk_type', 'active')->count();
    }

    public function test_coordinator_activation_waits_for_the_unit_manager_then_becomes_active(): void
    {
        $this->activate($this->coord, $this->hr)->assertRedirect(route('risk.active.index'))->assertSessionHasNoErrors();
        $r = $this->last();
        $this->assertNotNull($r, 'لم يُنشأ الخطر في سجل الإدارة');
        $this->assertSame('pending_approval', $r->status, 'تفعيل المنسق صار نشطاً بلا اعتماد مديره');

        // البطاقة والتنبيه لمدير الوحدة وحده
        $key = "risk:{$r->id}:approve";
        $this->assertNotNull($this->card($this->mudir, $key), 'مدير الوحدة لم تصله بطاقة الاعتماد');
        foreach ([$this->salama, $this->munawib, $this->other, $this->coord] as $u) {
            $this->assertNull($this->card($u, $key), "{$u->username}: وصلته بطاقة اعتماد ليست له");
        }
        $this->assertTrue(AppNotification::where('user_id', $this->mudir->id)->where('type', 'risk.approve')->exists(), 'مدير الوحدة لم يُنبَّه');

        // لا يعتمده غير مديره
        foreach ([$this->coord, $this->salama, $this->munawib, $this->other] as $u) {
            $this->actingAs($u)->post(route('risk.approve', $r))->assertForbidden();
        }
        $this->assertSame('pending_approval', $r->fresh()->status);

        // ضغطة «اعتمد» واحدة ← نشط، وتختفي البطاقة
        $this->actingAs($this->mudir)->post($this->card($this->mudir, $key)->primary['url'])->assertRedirect();
        $this->assertSame('active', $r->fresh()->status, 'اعتمده المدير ولم يصر نشطاً');
        $this->assertSame($this->mudir->id, $r->fresh()->approved_by_id);
        $this->assertNull($this->card($this->mudir, $key));
    }

    public function test_coordinator_activates_for_his_unit_and_below_only(): void
    {
        // لا «عام» ولا وحدة غيره
        $this->activate($this->coord, null)->assertSessionHas('error');
        $this->activate($this->coord, $this->it)->assertSessionHas('error');
        $this->assertSame(0, $this->registered(), 'المنسق فعّل خارج وحدته');

        // منسق بلا وحدة لا يفعّل
        $loose = $this->makeUser('safety_coordinator');
        $this->activate($loose, $this->hr)->assertSessionHas('error');
        $this->assertSame(0, $this->registered());

        // منسق الإدارة يفعّل لقسم تحتها ← البطاقة عند مدير القسم ومدير الإدارة، وأول من يعتمد يكفي
        $section = OrganizationUnit::create(['name' => 'قسم التوظيف', 'code' => 'hr-rec', 'unit_type' => 'section', 'parent_id' => $this->hr->id, 'is_active' => true]);
        $qism = $this->makeUser('section_manager', 'hr-rec');
        $this->activate($this->coord, $section)->assertSessionHasNoErrors();
        $r = $this->last();
        $this->assertSame('pending_approval', $r?->status);
        $key = "risk:{$r->id}:approve";
        $this->assertNotNull($this->card($qism, $key), 'مدير القسم لم تصله البطاقة');
        $this->assertNotNull($this->card($this->mudir, $key), 'مدير الإدارة فوق القسم لم تصله البطاقة');
        $this->actingAs($qism)->post(route('risk.approve', $r))->assertRedirect();
        $this->assertSame('active', $r->fresh()->status);
        $this->assertNull($this->card($this->mudir, $key), 'بقيت البطاقة عند المدير الأعلى بعد اعتماد مدير القسم');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('managers')]
    public function test_a_unit_manager_activates_directly_for_his_unit_only(string $role): void
    {
        $adm = $this->orgUnit('adm-eng');
        $manager = $this->makeUser($role, 'adm-eng');
        $peer = $this->makeUser('department_manager', 'adm-eng'); // معتمد آخر للوحدة نفسها: لا بطاقة له

        $this->activate($manager, $adm)->assertSessionHasNoErrors();
        $r = $this->last();
        $this->assertSame('active', $r?->status, "{$role}: المدير فعّل لوحدته ولم يصر نشطاً فوراً");
        $this->assertNull($this->card($peer, "risk:{$r->id}:approve"));

        $this->activate($manager, $this->hr)->assertSessionHas('error');
        $this->activate($manager, null)->assertSessionHas('error');
        $this->assertSame(1, $this->registered(), "{$role}: فعّل خارج وحدته");
    }

    public static function managers(): array
    {
        return ['مدير فرع' => ['branch_manager'], 'مدير إدارة' => ['department_manager'], 'مدير قسم' => ['section_manager'],
            'مدير الشؤون الإدارية والهندسية' => ['admin_eng_manager'], 'مدير المرافق والصيانة' => ['facilities_manager'], 'رئيس الأمن والسلامة' => ['security_safety_head']];
    }

    public function test_safety_officer_and_duty_officer_follow_the_same_rule(): void
    {
        // مسؤول السلامة: العام نشط فوراً؛ ولإدارة ينتظر مديرها
        $this->activate($this->salama, null)->assertSessionHasNoErrors();
        $wide = $this->last();
        $this->assertSame('active', $wide?->status);
        $this->assertNull($wide->organization_unit_id);

        $this->activate($this->salama, $this->hr)->assertSessionHasNoErrors();
        $unit = $this->last();
        $this->assertSame('pending_approval', $unit->status, 'مسؤول السلامة فعّل لإدارة فصار نشطاً بلا مديرها');
        $this->assertNotNull($this->card($this->mudir, "risk:{$unit->id}:approve"));
        $this->assertNull($this->card($this->salama, "risk:{$unit->id}:approve"));
        $this->actingAs($this->mudir)->post(route('risk.approve', $unit))->assertRedirect();
        $this->assertSame('active', $unit->fresh()->status);

        // المناوب: العام ينتظر مسؤول السلامة؛ ولإدارة ينتظر مديرها
        $this->activate($this->munawib, null)->assertSessionHasNoErrors();
        $w2 = $this->last();
        $this->assertSame('pending_approval', $w2->status, 'المناوب فعّل عاماً فصار نشطاً بلا مسؤول السلامة');
        $this->assertNotNull($this->card($this->salama, "risk:{$w2->id}:approve"));
        $this->assertNull($this->card($this->mudir, "risk:{$w2->id}:approve"));
        $this->actingAs($this->mudir)->post(route('risk.approve', $w2))->assertForbidden();
        $this->actingAs($this->salama)->post(route('risk.approve', $w2))->assertRedirect();
        $this->assertSame('active', $w2->fresh()->status);

        $this->activate($this->munawib, $this->hr)->assertSessionHasNoErrors();
        $u2 = $this->last();
        $this->assertSame('pending_approval', $u2->status);
        $this->assertNotNull($this->card($this->mudir, "risk:{$u2->id}:approve"));
    }

    public function test_coordinator_sees_the_register_of_his_unit_like_his_manager(): void
    {
        $mine = $this->makeRisk(['title' => 'خطر الموارد البشرية', 'status' => 'active', 'organization_unit_id' => $this->hr->id, 'category_id' => $this->sub->category_id, 'sub_category_id' => $this->sub->id]);
        $theirs = $this->makeRisk(['title' => 'خطر تقنية المعلومات', 'status' => 'active', 'organization_unit_id' => $this->it->id, 'category_id' => $this->sub->category_id, 'sub_category_id' => $this->sub->id]);
        $list = fn (User $u) => collect($this->actingAs($u)->getJson(route('risk.registry.tree.risksBySubCategory', ['type' => 'active', 'subCatId' => $this->sub->id]))->assertOk()->json())->pluck('title')->all();

        foreach ([$this->coord, $this->mudir] as $u) {
            $this->assertContains($mine->title, $list($u), "{$u->username}: لا يرى خطر وحدته");
            $this->assertNotContains($theirs->title, $list($u), "{$u->username}: يرى خطر إدارة أخرى");
            $this->actingAs($u)->get(route('risk.show', $mine))->assertOk();
            $this->actingAs($u)->get(route('risk.show', $theirs))->assertForbidden();
        }
        $this->assertEqualsCanonicalizing([$mine->title, $theirs->title], $list($this->salama));
    }

    public function test_activation_form_offers_the_coordinator_his_units_only(): void
    {
        $scopeSelect = function (User $u): string {
            $html = $this->actingAs($u)->get(route('risk.activate.form', $this->ref))->assertOk()->getContent();
            $this->assertSame(1, preg_match('/<select name="organization_unit_id".*?<\/select>/su', $html, $m), 'لا قائمة وحدات في نموذج التفعيل');
            return $m[0];
        };
        $html = $this->actingAs($this->coord)->get(route('risk.activate.form', $this->ref))->assertOk()->getContent();
        $this->assertStringNotContainsString('عام — المؤسسة بالكامل', $html, 'نموذج المنسق يعرض «عام»');
        $units = $scopeSelect($this->coord);
        $this->assertStringContainsString($this->hr->name, $units);
        $this->assertStringNotContainsString($this->it->name, $units, 'نموذج المنسق يعرض إدارة غيره');
        $this->assertStringContainsString('value="'.$this->hr->id.'" selected', $units, 'وحدة المنسق ليست مختارة سلفاً');

        // مسؤول السلامة: العام وكل الوحدات
        $this->actingAs($this->salama)->get(route('risk.activate.form', $this->ref))->assertOk()->assertSee('عام — المؤسسة بالكامل');
        $this->assertStringContainsString($this->it->name, $scopeSelect($this->salama));
    }

    public function test_an_unapproved_activation_does_not_route_reports_nor_show_as_activated(): void
    {
        $offices = Place::where('code', 'HZ-06')->firstOrFail();
        $emp = $this->makeUser('employee', 'hr');
        $report = fn (string $text) => $this->actingAs($emp)->post('/incident/normal', ['description' => $text, 'risk_id' => $this->ref->id, 'place_id' => $offices->id])->assertRedirect();

        $this->activate($this->coord, $this->hr, ['place_id' => $offices->id])->assertSessionHasNoErrors();
        $r = $this->last();
        $this->assertSame('pending_approval', $r->status);

        // قبل الاعتماد: البلاغ يبقى في المركز، والخطر ليس «مفعّلاً» في ملف المكان
        $report('شرر من مقبس قرب الطابعة');
        $before = Incident::latest('id')->first();
        $this->assertSame('received', $before->status, 'خطر لم يُعتمد وجّه البلاغ');
        $this->assertNull($before->incident_field_team_id);
        $this->actingAs($this->salama)->get(route('app.places.units.file', $offices))->assertOk()->assertDontSee('data-risk="'.$r->code.'"', false);

        // بعد الاعتماد: يصل معالجه، ويظهر في ملف المكان
        $this->actingAs($this->mudir)->post(route('risk.approve', $r))->assertRedirect();
        $report('شرر من مقبس آخر');
        $after = Incident::latest('id')->first();
        $this->assertSame('forwarded', $after->status);
        $this->assertSame($this->handler->id, $after->incident_field_team_id);
        $this->actingAs($this->salama)->get(route('app.places.units.file', $offices))->assertOk()->assertSee('data-risk="'.$r->code.'"', false);
    }

    public function test_a_rejected_activation_returns_to_the_coordinator_and_cannot_be_self_approved(): void
    {
        $this->activate($this->coord, $this->hr);
        $r = $this->last();

        // لا باب خلفي: المنسق لا يعتمد خطره بتغيير الحالة
        foreach (['approved', 'active'] as $to) {
            $this->actingAs($this->coord)->post(route('risk.changeStatus', ['risk' => $r, 'status' => $to]));
            $this->assertSame('pending_approval', $r->fresh()->status, "المنسق نقل خطره إلى «{$to}» بلا مديره");
        }

        $this->actingAs($this->mudir)->post(route('risk.reject', $r), ['note' => 'المعالج ليس من إدارتنا'])->assertRedirect();
        $this->assertSame('rejected', $r->fresh()->status);
        $t = $this->card($this->coord, "risk:{$r->id}:rejected");
        $this->assertNotNull($t, 'المنسق لم تصله بطاقة الرفض');
        $this->actingAs($this->coord)->post($t->primary['url'])->assertRedirect();
        $this->assertSame('draft', $r->fresh()->status);
        $this->actingAs($this->coord)->post(route('risk.submit', $r))->assertRedirect();
        $this->assertSame('pending_approval', $r->fresh()->status);
        $this->actingAs($this->mudir)->post(route('risk.approve', $r))->assertRedirect();
        $this->assertSame('active', $r->fresh()->status);
    }

    /** الباب الثاني للفعل نفسه — «خطر جديد في السجل الفعلي» — بالنطاق نفسه: وحدته وما تحتها */
    public function test_a_new_register_risk_follows_the_same_scope(): void
    {
        $payload = fn (array $o) => $o + ['category_id' => $this->sub->category_id, 'sub_category_id' => $this->sub->id, 'severity' => 3, 'likelihood' => 2, 'title' => 'انزلاق عند المدخل',
            'assigned_coordinator_id' => $this->coord->id, 'assigned_field_team_id' => $this->handler->id];

        $this->actingAs($this->coord)->post(route('risk.active.store'), $payload(['scope_type' => 'org_unit', 'organization_unit_id' => $this->it->id]))->assertSessionHas('error');
        $this->actingAs($this->coord)->post(route('risk.active.store'), $payload(['scope_type' => 'general']))->assertSessionHas('error');
        $this->assertSame(0, $this->registered(), 'المنسق كتب خطراً خارج وحدته');

        $this->actingAs($this->coord)->post(route('risk.active.store'), $payload(['scope_type' => 'org_unit', 'organization_unit_id' => $this->hr->id]))->assertSessionHasNoErrors();
        $r = Risk::where('risk_type', 'active')->latest('id')->first();
        $this->assertSame($this->hr->id, $r?->organization_unit_id);
        $this->assertSame('draft', $r->status);

        // ولا ينقله بالتعديل إلى وحدة غيره
        $this->actingAs($this->coord)->post(route('risk.active.update', $r), $payload(['scope_type' => 'org_unit', 'organization_unit_id' => $this->it->id]))->assertSessionHas('error');
        $this->assertSame($this->hr->id, $r->fresh()->organization_unit_id);
    }
}
