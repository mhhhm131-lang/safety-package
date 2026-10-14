<?php

namespace Tests\Feature\Form;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Form\Models\FormAssignment;
use App\Modules\Form\Models\FormField;
use App\Modules\Form\Models\FormSubmission;
use App\Modules\Form\Models\FormTemplate;
use App\Modules\Form\Services\FormService;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٢٩ — الخطوة ٢٩-٠ «العزل أولاً» (قرار ٨٧، وثيقة التصميم §٤-٦): بوابة العزل في وحدة النماذج.
 *
 * القوالب عامة (هي الحزمة نفسها)، وما يُعزل هو ما يُعبَّأ: التكليف والتعبئة يحملان الإدارة والمبنى.
 *   - بالمبنى في الموضع الواحد (قرار ٨٤): `BelongsToBuilding` على التكليفات والتعبئات.
 *   - بالوحدة بقاعدة واحدة (`FormVisibilityService`) تُقرأ في النتائج والمتابعة والتصدير وعدّادات القائمة معاً.
 *   - حارس الكتابة: الإرسال إلى وحدة ليست من مبانيك يُرفض.
 * الثغرة التي تُغلق هنا: منسق إدارة كان يفتح نتائج ومتابعة قالب عُبّئ في إدارة أخرى.
 */
class FormIsolationTest extends TestCase
{
    use RefreshDatabase;

    private EmergencyBuilding $main;
    private EmergencyBuilding $dmm;
    private OrganizationUnit $hq;
    private OrganizationUnit $unitA;
    private OrganizationUnit $unitB;
    private OrganizationUnit $branch;
    private OrganizationUnit $dmmOps;

    private User $salama;     // مسؤول السلامة — يرى الكل
    private User $lajna;      // اللجنة الدائمة — ترى الكل
    private User $coordA;     // منسق الإدارة «أ» في الملز
    private User $coordB;     // منسق الإدارة «ب» في الملز
    private User $coordDmm;   // منسق إدارة التشغيل في الدمام
    private User $empA;
    private User $empB;
    private User $empDmm;

    private FormTemplate $form;
    private FormService $forms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
        $this->main = EmergencyBuilding::main();
        $this->hq = OrganizationUnit::create(['code' => 'hq', 'name' => 'المركز الرئيسي', 'unit_type' => 'company']);
        $this->main->update(['branch_unit_id' => $this->hq->id]);
        $hub = Place::idByCode('HZ-06');
        $this->unitA = OrganizationUnit::create(['code' => 'hq-a', 'name' => 'إدارة أ', 'unit_type' => 'department', 'parent_id' => $this->hq->id, 'place_id' => $hub]);
        $this->unitB = OrganizationUnit::create(['code' => 'hq-b', 'name' => 'إدارة ب', 'unit_type' => 'department', 'parent_id' => $this->hq->id, 'place_id' => $hub]);

        $this->branch = OrganizationUnit::create(['code' => 'br-dmm', 'name' => 'فرع الشرقية', 'unit_type' => 'region', 'parent_id' => $this->hq->id]);
        $this->dmm = EmergencyBuilding::create(['code' => 'DMM', 'name' => 'فرع الدمام', 'branch' => 'فرع الشرقية', 'branch_unit_id' => $this->branch->id]);
        Place::createCategoriesFor($this->dmm);
        $dmmHub = Place::where('building_id', $this->dmm->id)->where('category', 'HZ-06')->firstOrFail();
        $this->branch->update(['place_id' => $dmmHub->id]);
        $this->dmmOps = OrganizationUnit::create(['code' => 'dmm-ops', 'name' => 'التشغيل', 'unit_type' => 'department', 'parent_id' => $this->branch->id, 'place_id' => $dmmHub->id]);

        $this->salama   = $this->user('salama', 'system_admin', $this->main);
        $this->lajna    = $this->user('lajna', 'safety_committee', $this->main);
        $this->coordA   = $this->user('coord.a', 'safety_coordinator', $this->main, $this->unitA);
        $this->coordB   = $this->user('coord.b', 'safety_coordinator', $this->main, $this->unitB);
        $this->coordDmm = $this->user('coord.dmm', 'safety_coordinator', $this->dmm, $this->dmmOps);
        $this->empA     = $this->user('emp.a', 'employee', $this->main, $this->unitA);
        $this->empB     = $this->user('emp.b', 'employee', $this->main, $this->unitB);
        $this->empDmm   = $this->user('emp.dmm', 'employee', $this->dmm, $this->dmmOps);

        $this->forms = app(FormService::class);
        $this->form = FormTemplate::create(['title' => 'إقرار الطبقة الثانية', 'form_type' => FormTemplate::TYPE_SURVEY, 'created_by_id' => $this->salama->id]);
        $this->form->fields()->create(['label' => 'ما الواقع؟', 'field_type' => FormField::TYPE_TEXT, 'is_required' => true, 'order' => 1]);

        // التكليف من مسؤول السلامة (بلا نطاق) لثلاثة موظفين في إدارتين ومبنيين، ويعبّئ اثنان منهم
        $this->actingAs($this->salama);
        $this->forms->assignToUsers($this->form, [$this->empA->id, $this->empB->id, $this->empDmm->id], null, $this->salama->id);
        $this->actingAs($this->empB);
        $this->forms->submit($this->form, $this->empB->id, [$this->form->fields->first()->id => 'إجابة ب']);
        $this->actingAs($this->empDmm);
        $this->forms->submit($this->form, $this->empDmm->id, [$this->form->fields->first()->id => 'إجابة الدمام']);
        auth()->logout();
        \App\Modules\Governance\Services\BuildingScope::forget($this->empDmm);
    }

    private function user(string $username, string $role, EmergencyBuilding $building, ?OrganizationUnit $unit = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234']);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'building_id' => $building->id, 'organization_unit_id' => $unit?->id]);
        return $u;
    }

    private function as(User $u): static
    {
        \App\Modules\Governance\Services\BuildingScope::forget($u);
        return $this->actingAs($u);
    }

    /** الثغرة القائمة: منسق «أ» يطلب نتائج قالب عُبّئ في «ب» ← لا صفوف، ومنسق «ب» يرى تعبئة إدارته وحدها */
    public function test_coordinator_sees_results_of_his_unit_only(): void
    {
        $this->as($this->coordA);
        $this->assertCount(0, $this->forms->results($this->form, $this->coordA), 'منسق أ يرى تعبئة إدارة ب');
        $r = $this->get(route('forms.results', $this->form));
        $r->assertOk();
        $r->assertDontSee('اسم emp.b');
        $r->assertDontSee('إجابة ب');

        $this->as($this->coordB);
        $rows = $this->forms->results($this->form, $this->coordB);
        $this->assertCount(1, $rows);
        $this->assertSame($this->empB->id, $rows->first()->submitted_by_id);
        $this->get(route('forms.results', $this->form))->assertOk()->assertSee('اسم emp.b')->assertDontSee('اسم emp.dmm');
    }

    /** المتابعة والتصدير يقرآن القاعدة نفسها: منسق «أ» يرى تكليف موظفه وحده، وتصديره بلا أسماء غيره */
    public function test_tracking_and_export_follow_the_same_rule(): void
    {
        $this->as($this->coordA);
        $r = $this->get(route('forms.tracking', $this->form));
        $r->assertOk()->assertSee('اسم emp.a')->assertDontSee('اسم emp.b')->assertDontSee('اسم emp.dmm');

        $csv = $this->get(route('forms.export', $this->form));
        $csv->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringNotContainsString('اسم emp.b', $body, 'تصدير منسق أ يحمل اسم موظف إدارة ب');
        $this->assertStringNotContainsString('إجابة الدمام', $body);

        // عدّادات القائمة بالقاعدة نفسها: لمنسق أ تكليف واحد وصفر تعبئة
        $list = $this->get(route('forms.index'));
        $list->assertOk();
        $row = $list->viewData('forms')->first();
        $this->assertSame(1, (int) $row->assignments_count, 'عدّاد التكليفات لمنسق أ ليس تكليف إدارته وحده');
        $this->assertSame(0, (int) $row->submissions_count, 'عدّاد التعبئات لمنسق أ يعدّ تعبئات غيره');
    }

    /** حساب فرع: تعبئات الملز وتكليفاته خارج مبناه لا تُقرأ أصلاً (قرار ٨٤ على الوحدة) */
    public function test_branch_account_never_reads_main_building_rows(): void
    {
        $this->as($this->coordDmm);
        $this->assertSame(1, FormSubmission::count(), 'منسق الدمام يقرأ تعبئات الملز');
        $this->assertSame(1, FormAssignment::count(), 'منسق الدمام يقرأ تكليفات الملز');
        $rows = $this->forms->results($this->form, $this->coordDmm);
        $this->assertCount(1, $rows);
        $this->assertSame($this->empDmm->id, $rows->first()->submitted_by_id);
        $this->get(route('forms.results', $this->form))->assertOk()->assertSee('اسم emp.dmm')->assertDontSee('اسم emp.b');

        // تذكير بتكليف من الملز عبر الرابط ← 403 قبل أي كود
        $malazAssignment = FormAssignment::withoutGlobalScope('building')->where('assigned_to_id', $this->empA->id)->firstOrFail();
        $this->post(route('forms.remind', [$this->form, $malazAssignment]))->assertForbidden();
    }

    /** اللجنة ومسؤول السلامة يريان الكل: التعبئتان والتكليفات الثلاثة */
    public function test_committee_and_safety_officer_see_everything(): void
    {
        foreach ([$this->lajna, $this->salama] as $u) {
            $this->as($u);
            $this->assertCount(2, $this->forms->results($this->form, $u));
            $this->get(route('forms.results', $this->form))->assertOk()->assertSee('اسم emp.b')->assertSee('اسم emp.dmm');
        }
        $this->as($this->salama);
        $row = $this->get(route('forms.index'))->viewData('forms')->first();
        $this->assertSame(3, (int) $row->assignments_count);
        $this->assertSame(2, (int) $row->submissions_count);
    }

    /** الموظف لا نتائج ولا متابعة (الصلاحية)، ويرى تكليفه هو في «نماذجي» */
    public function test_employee_gets_403_on_results_and_tracking(): void
    {
        $this->as($this->empA);
        $this->get(route('forms.results', $this->form))->assertForbidden();
        $this->get(route('forms.tracking', $this->form))->assertForbidden();
        $this->get(route('forms.mine'))->assertOk()->assertSee('إقرار الطبقة الثانية');
    }

    /** حارس الكتابة: الإرسال إلى وحدة ليست من مبانيك يُرفض 422، وإلى وحدة من مبانيك يمرّ */
    public function test_sending_to_a_unit_outside_account_buildings_is_rejected(): void
    {
        $this->as($this->coordDmm);
        $this->post(route('forms.send.store', $this->form), ['mode' => 'unit', 'unit_id' => $this->unitA->id])
            ->assertSessionHasErrors('unit_id');
        $this->assertSame(0, FormAssignment::withoutGlobalScope('building')->where('assigned_to_id', $this->coordA->id)->count(), 'كُلّف منسق الملز من حساب الدمام');

        $this->post(route('forms.send.store', $this->form), ['mode' => 'unit', 'unit_id' => $this->dmmOps->id])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, FormAssignment::withoutGlobalScope('building')->where('assigned_to_id', $this->coordDmm->id)->count());
    }

    /** الصفوف القائمة قبل الترحيل (بلا مبنى ولا وحدة) يملؤها `OwnerScopeBackfill` من حساب صاحبها كما يفعل الإنشاء */
    public function test_backfill_fills_existing_rows_from_their_owner(): void
    {
        \Illuminate\Support\Facades\DB::table('form_assignments')->insert(['form_id' => $this->form->id, 'assigned_to_id' => $this->coordB->id, 'status' => 'pending', 'source' => 'user', 'created_at' => now()]);
        \Illuminate\Support\Facades\DB::table('form_submissions')->insert(['form_id' => $this->form->id, 'submitted_by_id' => $this->coordDmm->id, 'submitted_at' => now(), 'created_at' => now()]);
        \Illuminate\Support\Facades\DB::table('form_submissions')->insert(['form_id' => $this->form->id, 'submitted_by_id' => null, 'submitted_at' => now(), 'created_at' => now()]);

        $this->assertSame(3, \App\Modules\Form\Support\OwnerScopeBackfill::run());

        $a = FormAssignment::withoutGlobalScope('building')->where('assigned_to_id', $this->coordB->id)->firstOrFail();
        $this->assertSame([$this->main->id, $this->unitB->id], [(int) $a->building_id, (int) $a->organization_unit_id]);
        $s = FormSubmission::withoutGlobalScope('building')->where('submitted_by_id', $this->coordDmm->id)->firstOrFail();
        $this->assertSame([$this->dmm->id, $this->dmmOps->id], [(int) $s->building_id, (int) $s->organization_unit_id], 'مبنى الدمام يُشتق من فرع الوحدة');
        $orphan = FormSubmission::withoutGlobalScope('building')->whereNull('submitted_by_id')->firstOrFail();
        $this->assertSame($this->main->id, (int) $orphan->building_id, 'تعبئة بلا صاحب تذهب إلى المبنى الرئيسي');
        $this->assertNull($orphan->organization_unit_id);
    }

    /** ما يُعبَّأ يحمل إدارته ومبناه من حساب صاحبه (لا من المرسل)، فتبقى القاعدة صادقة بعد السرية (٢٩-٢) */
    public function test_assignments_and_submissions_carry_unit_and_building_of_their_owner(): void
    {
        $a = FormAssignment::withoutGlobalScope('building')->where('assigned_to_id', $this->empDmm->id)->firstOrFail();
        $this->assertSame($this->dmm->id, (int) $a->building_id);
        $this->assertSame($this->dmmOps->id, (int) $a->organization_unit_id);
        $s = FormSubmission::withoutGlobalScope('building')->where('submitted_by_id', $this->empB->id)->firstOrFail();
        $this->assertSame($this->main->id, (int) $s->building_id);
        $this->assertSame($this->unitB->id, (int) $s->organization_unit_id);
    }
}
