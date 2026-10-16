<?php

namespace Tests\Feature\Form;

use App\Core\Services\CloseoutService;
use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Form\Inbox\FormTasks;
use App\Modules\Form\Models\FormAssignment;
use App\Modules\Form\Models\FormField;
use App\Modules\Form\Models\FormRound;
use App\Modules\Form\Models\FormSubmission;
use App\Modules\Form\Models\FormTemplate;
use App\Modules\Form\Services\RoundNumbersService;
use App\Modules\Form\Support\NonMaterialPackage;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\NonMaterialFormsSeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * المرحلة ٢٩ — الخطوة ٢٩-٧ «التبويب والقصة الكاملة» (قرار ٨٨): القصة من أولها إلى آخرها بالأدوار الخمسة في تشغيل واحد.
 *   مسؤول السلامة يفتح الجولة من تبويب «الطبقة الثانية» ← مدير الإدارة يحفظ مسودة ويعبّئ ويعتمد ← الموظفون يصلهم الإقرار السري ويجيبون (وأحدهم يُعلن اسمه)
 *   ← اللجنة والإدارة العليا ومسؤول السلامة يرون الأرقام بنصوصها، والمدير والمنسق بلا نصوص، والموظف وغير الإدارة ومبنى آخر لا يرون
 *   ← مسؤول السلامة يذكّر بالعدد ويقفل ← جولة ثانية تكلّف المدير من جديد. والقائمة العامة بلا قوالب الحزمة، وكل جدول مصنَّف للإغلاق.
 */
class Phase29StoryTest extends TestCase
{
    use RefreshDatabase;

    private EmergencyBuilding $main;
    private EmergencyBuilding $dmm;
    private OrganizationUnit $unitA;
    private OrganizationUnit $unitB;
    private OrganizationUnit $dmmOps;
    private User $salama;
    private User $lajna;
    private User $idara;
    private User $mudirA;
    private User $mudirB;
    private User $coordA;
    private User $coordDmm;
    /** @var User[] */
    private array $emp = [];
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
        $branch = OrganizationUnit::create(['code' => 'br-dmm', 'name' => 'فرع الشرقية', 'unit_type' => 'region', 'parent_id' => $hq->id]);
        $this->dmm = EmergencyBuilding::create(['code' => 'DMM', 'name' => 'فرع الدمام', 'branch' => 'فرع الشرقية', 'branch_unit_id' => $branch->id]);
        Place::createCategoriesFor($this->dmm);
        $dmmHub = Place::where('building_id', $this->dmm->id)->where('category', 'HZ-06')->firstOrFail();
        $branch->update(['place_id' => $dmmHub->id]);
        $this->dmmOps = OrganizationUnit::create(['code' => 'dmm-ops', 'name' => 'التشغيل', 'unit_type' => 'department', 'parent_id' => $branch->id, 'place_id' => $dmmHub->id]);

        $this->salama   = $this->user('salama', 'system_admin', $this->main);
        $this->lajna    = $this->user('lajna', 'safety_committee', $this->main);
        $this->idara    = $this->user('idara', 'top_management', $this->main);
        $this->mudirA   = $this->user('mudir.a', 'department_manager', $this->main, $this->unitA);
        $this->mudirB   = $this->user('mudir.b', 'department_manager', $this->main, $this->unitB);
        $this->coordA   = $this->user('coord.a', 'safety_coordinator', $this->main, $this->unitA);
        $this->coordDmm = $this->user('coord.dmm', 'safety_coordinator', $this->dmm, $this->dmmOps);
        for ($i = 0; $i < 6; $i++) {
            $this->emp[$i] = $this->user("emp.$i", 'employee', $this->main, $this->unitA);
        }
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

    private function keys(User $u): array
    {
        return app(FormTasks::class)->tasksFor($u)->pluck('key')->all();
    }

    public function test_the_whole_story_with_the_five_roles(): void
    {
        // ── ١ مسؤول السلامة: تبويب «الطبقة الثانية» والبطاقة والقائمة العامة بلا قوالب الحزمة ──
        $this->as($this->salama);
        $this->get(route('forms.index'))->assertOk()->assertSee('data-tab="layer2"', false)->assertDontSee('data-form="'.$this->card->id.'"', false);
        $this->get(route('forms.rounds.index'))->assertOk()->assertSee('data-card="'.$this->card->id.'"', false)->assertSee('بنود البطاقة')->assertSee('data-open-round', false);

        // حارس: منسق الدمام لا يفتح جولة لإدارة في الملز
        $this->as($this->coordDmm)->post(route('forms.rounds.store'), ['unit_id' => $this->unitA->id])->assertSessionHasErrors('unit_id');

        $this->as($this->salama)->post(route('forms.rounds.store'), ['unit_id' => $this->unitA->id])->assertSessionHasNoErrors();
        $round = FormRound::withoutGlobalScope('building')->where('organization_unit_id', $this->unitA->id)->firstOrFail();
        $this->assertSame(FormRound::STATE_SENT, $round->state);

        // ── ٢ مدير الإدارة: مهمة، مسودة، تعبئة، اعتماد ──
        $this->assertTrue(collect($this->keys($this->mudirA))->contains(fn ($k) => str_starts_with($k, 'form:')), 'بطاقة الإدارة تنتظر تعبئته');
        $this->assertSame([], array_filter($this->keys($this->emp[0]), fn ($k) => str_starts_with($k, 'form:')), 'الموظف لا يُكلَّف بشيء قبل الاعتماد');

        $claims = [];
        foreach ($this->card->fields as $f) {
            if ($f->isClaim()) $claims[$f->claimDef()['item'].'.'.$f->claimDef()['stage']] = $f;
        }
        $this->as($this->mudirA);
        $this->put(route('forms.draft', $this->card), ['fields' => [$claims['goals.doc']->id => ['claim' => 'yes', 'evidence' => 'خطة الإدارة ٢٠٢٦']]])->assertOk();
        $this->get(route('forms.fill', $this->card))->assertOk()->assertSee('خطة الإدارة ٢٠٢٦')->assertSee('data-round-header', false);
        $this->post(route('forms.rounds.approve', $round))->assertSessionHas('err'); // قبل التعبئة

        $answers = [];
        foreach ($claims as $f) {
            if (!$f->claimDef()['from_register']) $answers[$f->id] = ['claim' => 'no'];
        }
        foreach (['goals.doc', 'goals.ann', 'goals.und', 'goals.app', 'role.doc', 'role.ann', 'role.und'] as $k) {
            $answers[$claims[$k]->id] = ['claim' => 'yes', 'evidence' => 'دليل '.$k];
        }
        $this->post(route('forms.submit', $this->card), ['fields' => $answers, 'photos' => [$claims['goals.doc']->id => UploadedFile::fake()->createWithContent('plan.pdf', "%PDF-1.4\n%%EOF")]])->assertSessionHasNoErrors();
        $this->assertContains("round:{$round->id}:approve", $this->keys($this->mudirA));
        $this->post(route('forms.rounds.approve', $round))->assertSessionHas('ok');
        $round = $round->fresh();
        $this->assertSame(FormRound::STATE_ACK, $round->state, 'الإقرار أُرسل آلياً');

        // ── ٣ الموظفون: الإقرار السري ──
        $ack = FormTemplate::where('package_code', NonMaterialPackage::ackCode($round->id))->firstOrFail();
        $assigned = FormAssignment::withoutGlobalScope('building')->where('form_id', $ack->id)->pluck('assigned_to_id')->map(fn ($v) => (int) $v)->all();
        $this->assertCount(7, $assigned, 'ستة موظفين والمنسق — دون المدير');
        $this->assertNotContains($this->mudirA->id, $assigned);
        $this->assertContains($this->coordA->id, $assigned);
        $this->assertContains("form:".FormAssignment::withoutGlobalScope('building')->where('form_id', $ack->id)->where('assigned_to_id', $this->emp[0]->id)->value('id'), $this->keys($this->emp[0]));

        $ladders = [];
        foreach ($ack->fields as $f) $ladders[$f->ladderDef()['item']] = $f;
        $this->assertSame(['goals', 'role'], array_keys($ladders));
        $this->as($this->emp[0])->get(route('forms.fill', $ack))->assertOk()->assertSee('لا يُنسب جوابك إليك')->assertSee('دليل goals.doc')->assertSee('plan.pdf')->assertSee('اسم mudir.a');
        $plan = [
            [['yes', 'yes', 'yes', 'yes', ''], ['yes', 'yes', 'no', 'no', 'لم أفهم الصلاحيات']],
            [['yes', 'yes', 'yes', 'no', 'لا وقت للتطبيق'], ['yes', 'no', 'no', 'no', 'لم يُعلن']],
            [['yes', 'yes', 'yes', 'no', 'الصلاحيات في الورقة غير التي نُمارسها'], 'na'],
            [['yes', 'yes', 'no', 'no', 'لا أفهمه'], ['yes', 'yes', 'no', 'no', 'غامض']],
            [['yes', 'no', 'no', 'no', 'لم يصلني إعلان'], ['yes', 'yes', 'yes', 'yes', '']],
        ];
        foreach ($plan as $i => [$g, $r]) {
            $fields = [$ladders['goals']->id => ['doc' => $g[0], 'ann' => $g[1], 'und' => $g[2], 'app' => $g[3], 'note' => $g[4]]];
            $fields[$ladders['role']->id] = $r === 'na' ? ['na' => '1'] : ['doc' => $r[0], 'ann' => $r[1], 'und' => $r[2], 'app' => $r[3], 'note' => $r[4]];
            $post = ['fields' => $fields] + ($i === 0 ? ['reveal_name' => '1'] : []); // الأول يُعلن اسمه
            $this->as($this->emp[$i])->post(route('forms.submit', $ack), $post)->assertSessionHasNoErrors();
        }
        $subs = FormSubmission::withoutGlobalScope('building')->where('form_id', $ack->id)->get();
        $this->assertCount(5, $subs);
        $this->assertSame(4, $subs->whereNull('submitted_by_id')->count(), 'أربعة سريون');
        $this->assertSame($this->emp[0]->id, (int) $subs->whereNotNull('submitted_by_id')->first()->submitted_by_id, 'وواحد أعلن اسمه');

        // ── ٤ اللجنة والإدارة العليا ومسؤول السلامة: الأرقام بنصوصها؛ المدير والمنسق بلا نصوص؛ الموظف وغير الإدارة ومبنى آخر لا ──
        $n = app(RoundNumbersService::class)->compute($round, true);
        $this->assertSame(5, $n['answered']);
        $this->assertSame(7, $n['assigned']);
        $this->assertSame(1, $n['named']);
        $this->assertSame('goals', $n['top_gap']['item']);
        $this->assertSame('مفهوم وغير مطبَّق', $n['top_gap']['label']);
        $this->assertEqualsWithDelta(2.4, $n['level'], 0.05);
        foreach ([$this->lajna, $this->idara, $this->salama] as $viewer) {
            $this->as($viewer)->get(route('forms.rounds.numbers', $round))->assertOk()->assertSee('data-level="2.4"', false)->assertSee('لا وقت للتطبيق')->assertDontSee('اسم emp.');
        }
        foreach ([$this->mudirA, $this->coordA] as $viewer) {
            $this->as($viewer)->get(route('forms.rounds.numbers', $round))->assertOk()->assertSee('data-level="2.4"', false)->assertDontSee('لا وقت للتطبيق')->assertSee('بلا نصوص الموظفين');
        }
        foreach ([$this->emp[0], $this->mudirB, $this->coordDmm] as $viewer) {
            $this->as($viewer)->get(route('forms.rounds.numbers', $round))->assertForbidden();
        }
        $this->as($this->lajna)->get(route('forms.results', $ack))->assertOk()->assertSee('سري')->assertSee('اسم emp.0')->assertDontSee('اسم emp.1');
        $this->as($this->coordA)->get(route('forms.results', $ack))->assertForbidden();

        // ── ٥ مسؤول السلامة: المتابعة بالعدد، التذكير، الإقفال ──
        $this->as($this->salama);
        $this->assertNotContains("round:{$round->id}:close", $this->keys($this->salama), 'بقي اثنان بلا إجابة');
        $this->get(route('forms.tracking', $ack))->assertOk()->assertSee('data-anonymous-tracking', false)->assertDontSee('اسم emp.5')->assertSee('data-stat="عبّأ"', false);
        $this->post(route('forms.remind-all', $ack))->assertSessionHas('ok');
        $this->get(route('forms.rounds.show', $round))->assertOk()->assertSee('data-ack-total="7"', false)->assertSee('data-ack-done="5"', false)->assertSee('data-close', false);
        $this->post(route('forms.rounds.close', $round))->assertSessionHas('ok');
        $this->assertSame(FormRound::STATE_CLOSED, $round->fresh()->state);
        $this->as($this->emp[5])->get(route('forms.fill', $ack))->assertForbidden();
        $this->as($this->salama)->get(route('forms.rounds.numbers', $round))->assertOk()->assertSee('data-round-state="closed"', false);

        // ── ٦ جولة ثانية تكلّف المدير من جديد، والقائمة تعرض الجولتين ──
        $this->as($this->salama)->post(route('forms.rounds.store'), ['unit_id' => $this->unitA->id])->assertSessionHasNoErrors();
        $round2 = FormRound::withoutGlobalScope('building')->where('organization_unit_id', $this->unitA->id)->where('round_no', 2)->firstOrFail();
        $this->assertTrue($this->mudirA->can('fill', $this->card));
        $this->as($this->salama)->get(route('forms.rounds.index'))->assertOk()->assertSee('data-round="'.$round->id.'"', false)->assertSee('data-round="'.$round2->id.'"', false);
        $this->as($this->mudirB)->get(route('forms.rounds.index'))->assertOk()->assertDontSee('data-round="'.$round->id.'"', false);
        $this->as($this->idara)->get(route('forms.rounds.index'))->assertOk()->assertSee('data-round="'.$round->id.'"', false); // الإدارة العليا بلا form.list ترى التبويب
        $intent = \App\Core\Intents\IntentRegistry::forUser($this->idara)->firstWhere('key', 'forms_log');
        $this->assertNotNull($intent, 'بابها في «أريد أن»');
        $this->assertSame(route('forms.rounds.index'), $intent->url, 'يفتح تبويب الطبقة الثانية لا القائمة العامة');
        $this->assertSame(route('forms.index'), \App\Core\Intents\IntentRegistry::forUser($this->salama)->firstWhere('key', 'forms_log')->url);
        $this->as($this->emp[0])->get(route('forms.rounds.index'))->assertForbidden();

        // ── ٧ الإغلاق: كل جدول مصنَّف ──
        $this->assertSame([], app(CloseoutService::class)->unclassifiedTables());
    }
}
