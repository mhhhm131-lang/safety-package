<?php

namespace Tests\Feature\Form;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Form\Inbox\FormTasks;
use App\Modules\Form\Models\FormAssignment;
use App\Modules\Form\Models\FormField;
use App\Modules\Form\Models\FormRound;
use App\Modules\Form\Models\FormSubmission;
use App\Modules\Form\Models\FormTemplate;
use App\Modules\Form\Support\NonMaterialPackage;
use App\Modules\Governance\Models\AppNotification;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\NonMaterialFormsSeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٢٩ — الخطوة ٢٩-٤ «الجولة» (قرار ٨٨ على وثيقة التصميم §٤-٣):
 *   فتح جولة لإدارة (مسؤول السلامة) = تكليف واحد لمدير الإدارة ببطاقة الإدارة، والموظفون لا يُكلَّفون بشيء؛
 *   لا جولة فوق جولة مفتوحة، ولا جولة لوحدة ليست من مبانيك، ولا لإدارة بلا مدير؛
 *   حفظ آلي للمسودة على الخادم وتعبئة على أيام؛ «لا مرحلة بلا حالة» عند الإرسال؛
 *   اعتماد المدير «البيانات صحيحة وأعتمدها» بعد التعبئة، بالاسم والوقت في السجل، والحالة «اعتمدها المدير»، ويُعلَم مسؤول السلامة؛
 *   صفحة الجولة بقاعدة الرؤية والمبنى؛ وجولة ثانية بعد الإقفال تكلّف المدير من جديد.
 */
class FormRoundTest extends TestCase
{
    use RefreshDatabase;

    private EmergencyBuilding $main;
    private EmergencyBuilding $dmm;
    private OrganizationUnit $unitA;
    private OrganizationUnit $unitB;
    private OrganizationUnit $unitC;
    private OrganizationUnit $dmmOps;
    private User $salama;
    private User $lajna;
    private User $mudirA;
    private User $mudirB;
    private User $coordA;
    private User $empA;
    private User $coordDmm;
    private FormTemplate $card;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class, NonMaterialFormsSeeder::class]);
        $this->main = EmergencyBuilding::main();
        $hq = OrganizationUnit::create(['code' => 'hq', 'name' => 'المركز الرئيسي', 'unit_type' => 'company']);
        $this->main->update(['branch_unit_id' => $hq->id]);
        $hub = Place::idByCode('HZ-06');
        $this->unitA = OrganizationUnit::create(['code' => 'hq-a', 'name' => 'إدارة أ', 'unit_type' => 'department', 'parent_id' => $hq->id, 'place_id' => $hub]);
        $this->unitB = OrganizationUnit::create(['code' => 'hq-b', 'name' => 'إدارة ب', 'unit_type' => 'department', 'parent_id' => $hq->id, 'place_id' => $hub]);
        $this->unitC = OrganizationUnit::create(['code' => 'hq-c', 'name' => 'إدارة ج بلا مدير', 'unit_type' => 'department', 'parent_id' => $hq->id, 'place_id' => $hub]);

        $branch = OrganizationUnit::create(['code' => 'br-dmm', 'name' => 'فرع الشرقية', 'unit_type' => 'region', 'parent_id' => $hq->id]);
        $this->dmm = EmergencyBuilding::create(['code' => 'DMM', 'name' => 'فرع الدمام', 'branch' => 'فرع الشرقية', 'branch_unit_id' => $branch->id]);
        Place::createCategoriesFor($this->dmm);
        $dmmHub = Place::where('building_id', $this->dmm->id)->where('category', 'HZ-06')->firstOrFail();
        $branch->update(['place_id' => $dmmHub->id]);
        $this->dmmOps = OrganizationUnit::create(['code' => 'dmm-ops', 'name' => 'التشغيل', 'unit_type' => 'department', 'parent_id' => $branch->id, 'place_id' => $dmmHub->id]);

        $this->salama   = $this->user('salama', 'system_admin', $this->main);
        $this->lajna    = $this->user('lajna', 'safety_committee', $this->main);
        $this->mudirA   = $this->user('mudir.a', 'department_manager', $this->main, $this->unitA);
        $this->mudirB   = $this->user('mudir.b', 'department_manager', $this->main, $this->unitB);
        $this->coordA   = $this->user('coord.a', 'safety_coordinator', $this->main, $this->unitA);
        $this->empA     = $this->user('emp.a', 'employee', $this->main, $this->unitA);
        $this->coordDmm = $this->user('coord.dmm', 'safety_coordinator', $this->dmm, $this->dmmOps);
        $this->card = FormTemplate::where('package_code', NonMaterialPackage::CODE)->firstOrFail();
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

    private function open(OrganizationUnit $unit): FormRound
    {
        $this->as($this->salama)->post(route('forms.rounds.store'), ['unit_id' => $unit->id])->assertSessionHasNoErrors();
        return FormRound::withoutGlobalScope('building')->where('organization_unit_id', $unit->id)->latest('id')->firstOrFail();
    }

    /** @return array<string, FormField> */
    private function claims(): array
    {
        $out = [];
        foreach ($this->card->fields as $f) {
            if ($f->isClaim()) $out[$f->claimDef()['item'].'.'.$f->claimDef()['stage']] = $f;
        }
        return $out;
    }

    private function allNo(): array
    {
        $a = [];
        foreach ($this->claims() as $f) {
            if (!$f->claimDef()['from_register']) $a[$f->id] = ['claim' => 'no'];
        }
        return $a;
    }

    public function test_open_round_assigns_card_to_manager_only_and_guards(): void
    {
        $r = $this->open($this->unitA);
        $this->assertSame(FormRound::STATE_SENT, $r->state);
        $this->assertSame(1, $r->round_no);
        $this->assertSame($this->main->id, (int) $r->building_id, 'المبنى من فرع الوحدة');
        $this->assertSame('opened', $r->log[0]['action']);
        $this->assertSame('اسم salama', $r->log[0]['by']);

        $assignments = FormAssignment::withoutGlobalScope('building')->where('round_id', $r->id)->get();
        $this->assertCount(1, $assignments, 'تكليف واحد في الجولة');
        $this->assertSame($this->mudirA->id, (int) $assignments->first()->assigned_to_id, 'لمدير الإدارة وحده');
        $this->assertSame($this->card->id, (int) $assignments->first()->form_id);
        $this->assertSame(0, FormAssignment::withoutGlobalScope('building')->where('assigned_to_id', $this->empA->id)->count(), 'الموظف لا يُكلَّف بشيء');
        $this->assertTrue(AppNotification::where('user_id', $this->mudirA->id)->where('type', 'form.assigned')->exists());

        // لا جولة فوق جولة مفتوحة
        $this->as($this->salama)->post(route('forms.rounds.store'), ['unit_id' => $this->unitA->id])->assertSessionHas('err');
        $this->assertSame(1, FormRound::withoutGlobalScope('building')->count());
        // إدارة بلا مدير
        $this->as($this->salama)->post(route('forms.rounds.store'), ['unit_id' => $this->unitC->id])->assertSessionHas('err');
        // الوحدة ليست من مبانيك: منسق الدمام يفتح جولة لإدارة في الملز
        $this->as($this->coordDmm)->post(route('forms.rounds.store'), ['unit_id' => $this->unitB->id])->assertSessionHasErrors('unit_id');
        // مدير إدارة لا يفتح جولة
        $this->as($this->mudirA)->post(route('forms.rounds.store'), ['unit_id' => $this->unitA->id])->assertForbidden();
        $this->assertSame(1, FormRound::withoutGlobalScope('building')->count());
    }

    public function test_manager_autosaves_fills_with_no_stage_without_state_then_approves(): void
    {
        $r = $this->open($this->unitA);
        $claims = $this->claims();

        $this->as($this->mudirA);
        $page = $this->get(route('forms.fill', $this->card));
        $page->assertOk()->assertSee('إدارة أ')->assertSee('اسم mudir.a')->assertSee('data-round-header', false)->assertSee('الجولة');
        $this->assertStringContainsString('<strong>3</strong>', $page->getContent(), 'عدد موظفي الإدارة: المدير والمنسق والموظف');

        // حفظ آلي: المسودة على الخادم وتعود في الشاشة
        $this->put(route('forms.draft', $this->card), ['fields' => [$claims['goals.doc']->id => ['claim' => 'yes', 'evidence' => 'خطة الإدارة ٢٠٢٦']]])
            ->assertOk()->assertJsonStructure(['saved_at']);
        $a = FormAssignment::withoutGlobalScope('building')->where('round_id', $r->id)->firstOrFail();
        $this->assertNotNull($a->draft_saved_at);
        $this->assertSame('خطة الإدارة ٢٠٢٦', $a->draftValues()[$claims['goals.doc']->id]['evidence']);
        $this->get(route('forms.fill', $this->card))->assertOk()->assertSee('خطة الإدارة ٢٠٢٦');

        // الاعتماد قبل التعبئة يُرفض
        $this->post(route('forms.rounds.approve', $r))->assertSessionHas('err');
        $this->assertSame(FormRound::STATE_SENT, $r->fresh()->state);

        // «لا مرحلة بلا حالة»: ادعاء بلا نعم/لا يُرفض
        $answers = $this->allNo();
        unset($answers[$claims['rules.app']->id]);
        $this->post(route('forms.submit', $this->card), ['fields' => $answers])->assertSessionHasErrors("fields.{$claims['rules.app']->id}.claim");

        $answers = $this->allNo();
        $answers[$claims['goals.doc']->id] = ['claim' => 'yes', 'evidence' => 'خطة الإدارة ٢٠٢٦'];
        $this->post(route('forms.submit', $this->card), ['fields' => $answers])->assertSessionHasNoErrors();
        $s = FormSubmission::withoutGlobalScope('building')->sole();
        $this->assertSame($r->id, (int) $s->round_id, 'التعبئة تحمل الجولة');
        $this->assertNull($a->fresh()->draft, 'المسودة تُمسح بالإرسال');
        $this->assertSame(FormAssignment::STATUS_COMPLETED, $a->fresh()->status);

        // مهمة المدير: اعتمد
        $keys = app(FormTasks::class)->tasksFor($this->mudirA)->pluck('key')->all();
        $this->assertContains("round:{$r->id}:approve", $keys);

        // غير المدير لا يعتمد
        $this->as($this->mudirB)->post(route('forms.rounds.approve', $r))->assertForbidden();
        $this->as($this->coordA)->post(route('forms.rounds.approve', $r))->assertForbidden();

        $this->as($this->mudirA)->post(route('forms.rounds.approve', $r))->assertSessionHas('ok');
        $r = $r->fresh();
        // ٢٩-٥: الإدارة قالت «نعم» في بند وفيها موظفون بحساب ← يولّد النظام الإقرار ويرسله فتصير الحالة «الإقرارات جارية»
        $this->assertSame(FormRound::STATE_ACK, $r->state);
        $this->assertSame($this->mudirA->id, (int) $r->approved_by_id);
        $this->assertNotNull($r->approved_at);
        $log = $r->log;
        $approved = collect($log)->firstWhere('action', 'approved');
        $this->assertNotNull($approved);
        $this->assertSame('اسم mudir.a', $approved['by'], 'الاعتماد بالاسم');
        $this->assertNotEmpty($approved['at'], 'وبالوقت');
        $this->assertTrue(AppNotification::where('user_id', $this->salama->id)->where('type', 'form.round')->where('title', 'like', '%إدارة أ%')->exists(), 'يُعلَم مسؤول السلامة');
        $this->assertNotContains("round:{$r->id}:approve", app(FormTasks::class)->tasksFor($this->mudirA)->pluck('key')->all());

        // لا اعتماد ثانٍ
        $this->post(route('forms.rounds.approve', $r))->assertSessionHas('err');
    }

    public function test_round_page_follows_visibility_and_building(): void
    {
        $r = $this->open($this->unitA);

        $this->as($this->mudirA)->get(route('forms.rounds.show', $r))->assertOk()->assertSee('إدارة أ')->assertSee('عبّئ');
        $this->as($this->coordA)->get(route('forms.rounds.show', $r))->assertOk();
        $this->as($this->lajna)->get(route('forms.rounds.show', $r))->assertOk();
        $this->as($this->mudirB)->get(route('forms.rounds.show', $r))->assertForbidden();
        $this->as($this->empA)->get(route('forms.rounds.show', $r))->assertForbidden();
        $this->as($this->coordDmm)->get(route('forms.rounds.show', $r))->assertForbidden();

        $this->as($this->salama)->get(route('forms.rounds.index'))->assertOk()->assertSee('إدارة أ');
        $this->as($this->mudirB)->get(route('forms.rounds.index'))->assertOk()->assertDontSee('إدارة أ');
    }

    public function test_second_round_after_close_assigns_manager_again(): void
    {
        $r1 = $this->open($this->unitA);
        FormRound::withoutGlobalScope('building')->where('id', $r1->id)->update(['state' => FormRound::STATE_CLOSED]);

        $r2 = $this->open($this->unitA);
        $this->assertSame(2, $r2->round_no);
        $mine = FormAssignment::withoutGlobalScope('building')->where('assigned_to_id', $this->mudirA->id)->where('form_id', $this->card->id)->get();
        $this->assertCount(2, $mine, 'تكليف لكل جولة — القيد الفريد بالجولة');
        $this->assertSame($r2->id, (int) $mine->sortByDesc('id')->first()->round_id);
        $this->assertTrue($this->mudirA->can('fill', $this->card));
    }
}
