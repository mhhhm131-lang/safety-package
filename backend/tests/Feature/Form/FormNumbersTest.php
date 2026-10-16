<?php

namespace Tests\Feature\Form;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Form\Inbox\FormTasks;
use App\Modules\Form\Models\FormField;
use App\Modules\Form\Models\FormRound;
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
use Tests\TestCase;

/**
 * المرحلة ٢٩ — الخطوة ٢٩-٦ «شاشة الأرقام» (قرار ٨٨ §٢ و§٣): للجنة والإدارة العليا ومسؤول السلامة (وللمدير والمنسق نسب إدارتهم بلا نصوص الموظفين).
 *   السُّلّم التراكمي: درجة الموظف في البند = عدد «نعم» المتصلة من الأسفل (وصلتني ← أُعلن ← أفهمه ← أطبّقه)؛ النسب على من أجاب و«لا ينطبق» خارج الحساب؛
 *   رقم البند متوسط درجات موظفيه من أربعة؛ رقم الإدارة متوسط بنودها؛ الفجوة = ما ادّعته الإدارة − رقم البند؛
 *   الفجوات مرتبة: «مفهوم وغير مطبَّق» أولاً ثم «معلن وغير مفهوم» ثم … ثم ما قالت فيه الإدارة «لا»؛ تحت خمسة مجيبين لا أرقام؛
 *   سطر الإدارة: المستوى من ٤، أكبر فجوة، أجاب ن من م؛ لا بطاقات أبعاد؛ والإقفال لمسؤول السلامة ومهمته في «ما ينتظرك».
 */
class FormNumbersTest extends TestCase
{
    use RefreshDatabase;

    private EmergencyBuilding $main;
    private OrganizationUnit $unitA;
    private OrganizationUnit $unitB;
    private User $salama;
    private User $lajna;
    private User $mudirA;
    private User $mudirB;
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

        $this->salama = $this->user('salama', 'system_admin');
        $this->lajna  = $this->user('lajna', 'safety_committee');
        $this->mudirA = $this->user('mudir.a', 'department_manager', $this->unitA);
        $this->mudirB = $this->user('mudir.b', 'department_manager', $this->unitB);
        for ($i = 0; $i < 7; $i++) {
            $this->emp[$i] = $this->user("emp.$i", 'employee', $this->unitA);
        }
        $this->card = FormTemplate::where('package_code', NonMaterialPackage::CODE)->firstOrFail();
    }

    private function user(string $username, string $role, ?OrganizationUnit $unit = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234']);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'building_id' => $this->main->id, 'organization_unit_id' => $unit?->id]);
        return $u;
    }

    private function as(User $u): static
    {
        \App\Modules\Governance\Services\BuildingScope::forget($u);
        return $this->actingAs($u);
    }

    /** جولة لإدارة أ: الأهداف «نعم» في المراحل الأربع، الدور «نعم» في موثّق ومعلن ومفهوم و«لا» في مطبَّق، والباقي «لا»؛ ثم اعتماد. */
    private function approvedRound(): FormRound
    {
        $claims = [];
        foreach ($this->card->fields as $f) {
            if ($f->isClaim()) $claims[$f->claimDef()['item'].'.'.$f->claimDef()['stage']] = $f;
        }
        $this->as($this->salama)->post(route('forms.rounds.store'), ['unit_id' => $this->unitA->id])->assertSessionHasNoErrors();
        $round = FormRound::withoutGlobalScope('building')->where('organization_unit_id', $this->unitA->id)->latest('id')->firstOrFail();
        $answers = [];
        foreach ($claims as $key => $f) {
            if ($f->claimDef()['from_register']) continue;
            $answers[$f->id] = ['claim' => 'no'];
        }
        foreach (['goals.doc', 'goals.ann', 'goals.und', 'goals.app', 'role.doc', 'role.ann', 'role.und'] as $k) {
            $answers[$claims[$k]->id] = ['claim' => 'yes', 'evidence' => 'دليل '.$k];
        }
        $this->as($this->mudirA)->post(route('forms.submit', $this->card), ['fields' => $answers])->assertSessionHasNoErrors();
        $this->as($this->mudirA)->post(route('forms.rounds.approve', $round))->assertSessionHas('ok');

        return $round->fresh();
    }

    private function ack(FormRound $round): FormTemplate
    {
        return FormTemplate::where('package_code', NonMaterialPackage::ackCode($round->id))->firstOrFail();
    }

    /** @return array<string, FormField> بمفتاح البند */
    private function ladders(FormTemplate $ack): array
    {
        $out = [];
        foreach ($ack->fields as $f) $out[$f->ladderDef()['item']] = $f;
        return $out;
    }

    /** إجابة موظف: لكل بند [doc, ann, und, app, note] أو 'na' */
    private function answer(User $e, FormTemplate $ack, array $goals, array|string $role): void
    {
        $l = $this->ladders($ack);
        $fields = [];
        $fields[$l['goals']->id] = ['doc' => $goals[0], 'ann' => $goals[1], 'und' => $goals[2], 'app' => $goals[3], 'note' => $goals[4] ?? ''];
        $fields[$l['role']->id] = $role === 'na' ? ['na' => '1'] : ['doc' => $role[0], 'ann' => $role[1], 'und' => $role[2], 'app' => $role[3], 'note' => $role[4] ?? ''];
        $this->as($e)->post(route('forms.submit', $ack), ['fields' => $fields])->assertSessionHasNoErrors();
    }

    public function test_numbers_hidden_below_five_respondents(): void
    {
        $round = $this->approvedRound();
        $ack = $this->ack($round);
        for ($i = 0; $i < 4; $i++) {
            $this->answer($this->emp[$i], $ack, ['yes', 'yes', 'yes', 'yes'], 'na');
        }

        $n = app(RoundNumbersService::class)->compute($round, true);
        $this->assertSame(4, $n['answered']);
        $this->assertSame(7, $n['assigned']);
        $this->assertTrue($n['hidden']);
        $this->assertNull($n['level']);

        $this->as($this->salama)->get(route('forms.rounds.numbers', $round))->assertOk()
            ->assertSee('data-numbers-hidden="4"', false)->assertSee('أجاب 4 من 7')->assertDontSee('data-item=', false);
    }

    public function test_cumulative_ladder_shares_level_gap_order_and_who_sees_texts(): void
    {
        $round = $this->approvedRound();
        $ack = $this->ack($round);
        // الأهداف: درجات ٤، ٣، ٣، ٢، ١ ← عدّ تراكمي ٥/٤/٣/١ ← ١٠٠٪/٨٠٪/٦٠٪/٢٠٪ ← رقم البند ٢٫٦، الفجوة ١٫٤، «مفهوم وغير مطبَّق»
        // الدور: درجات ٢، ١، (لا ينطبق)، ٢، ٤ ← على أربعة مجيبين ٤/٣/١/١ ← ١٠٠٪/٧٥٪/٢٥٪/٢٥٪ ← ٢٫٢٥، الفجوة ٠٫٧٥، «معلن وغير مفهوم»
        // «لا» على «أطبّقه» تستلزم «ما الواقع» (٢٩-٥) — لذا كل «لا» على المرحلة الرابعة معها نص
        $this->answer($this->emp[0], $ack, ['yes', 'yes', 'yes', 'yes'],                          ['yes', 'yes', 'no', 'no', 'لم أفهم الصلاحيات']);
        $this->answer($this->emp[1], $ack, ['yes', 'yes', 'yes', 'no', 'لا وقت للتطبيق'],         ['yes', 'no', 'no', 'no', 'لم يُعلن']);
        $this->answer($this->emp[2], $ack, ['yes', 'yes', 'yes', 'no', 'الصلاحيات في الورقة غير التي نُمارسها'], 'na');
        $this->answer($this->emp[3], $ack, ['yes', 'yes', 'no', 'no', 'لا أفهمه'],                ['yes', 'yes', 'no', 'no', 'غامض']);
        $this->answer($this->emp[4], $ack, ['yes', 'no', 'no', 'no', 'لم يصلني إعلان'],            ['yes', 'yes', 'yes', 'yes']);

        $n = app(RoundNumbersService::class)->compute($round, true);
        $this->assertFalse($n['hidden']);
        $this->assertSame(5, $n['answered']);
        $this->assertSame(7, $n['assigned']);
        $this->assertSame(0, $n['named']);

        $byKey = collect($n['items'])->keyBy('key');
        $g = $byKey['goals'];
        $this->assertSame(5, $g['answered']);
        $this->assertSame(['doc' => 5, 'ann' => 4, 'und' => 3, 'app' => 1], $g['counts']);
        $this->assertSame(['doc' => 100, 'ann' => 80, 'und' => 60, 'app' => 20], $g['shares']);
        $this->assertEqualsWithDelta(2.6, $g['score'], 0.001);
        $this->assertSame(4, $g['claimed']);
        $this->assertEqualsWithDelta(1.4, $g['gap'], 0.001);
        $this->assertSame('app', $g['kind']);
        $this->assertSame(4, $g['notes_count']);
        $this->assertContains('لا وقت للتطبيق', $g['notes']);

        $r = $byKey['role'];
        $this->assertSame(4, $r['answered'], '«لا ينطبق» خارج الحساب');
        $this->assertSame(1, $r['na']);
        $this->assertSame(['doc' => 4, 'ann' => 3, 'und' => 1, 'app' => 1], $r['counts']);
        $this->assertSame(['doc' => 100, 'ann' => 75, 'und' => 25, 'app' => 25], $r['shares']);
        $this->assertEqualsWithDelta(2.25, $r['score'], 0.001);
        $this->assertSame(3, $r['claimed']);
        $this->assertSame('und', $r['kind']);

        $this->assertSame('none', $byKey['rules']['kind'], 'الإدارة لم تدّعِ شيئاً');
        $this->assertSame(['goals', 'role'], array_slice(array_column($n['items'], 'key'), 0, 2), 'الترتيب: مفهوم وغير مطبَّق أولاً ثم معلن وغير مفهوم');
        $this->assertEqualsWithDelta(2.4, $n['level'], 0.05, 'رقم الإدارة متوسط بنودها (٢٫٦ و٢٫٢٥)');
        $this->assertSame('goals', $n['top_gap']['item']);
        $this->assertSame('مفهوم وغير مطبَّق', $n['top_gap']['label']);
        $this->assertStringContainsString('لا أخطار', (string) $byKey['risks']['fact'], 'وقائع النظام بجانب الرقم');

        $page = $this->as($this->salama)->get(route('forms.rounds.numbers', $round));
        $page->assertOk()->assertSee('data-level="2.4"', false)->assertSee('data-top-gap="goals"', false)->assertSee('أجاب 5 من 7')
            ->assertSee('لا وقت للتطبيق')->assertSee('مفهوم وغير مطبَّق')->assertDontSee('اسم emp.')->assertDontSee('النفسي');
        $html = $page->getContent();
        $this->assertLessThan(strpos($html, 'data-item="role"'), strpos($html, 'data-item="goals"'), 'الأهداف قبل الدور في الجدول');
        $this->as($this->lajna)->get(route('forms.rounds.numbers', $round))->assertOk()->assertSee('لا وقت للتطبيق');
        $this->as($this->mudirA)->get(route('forms.rounds.numbers', $round))->assertOk()->assertSee('data-level="2.4"', false)
            ->assertDontSee('لا وقت للتطبيق')->assertSee('بلا نصوص الموظفين');
        $this->as($this->emp[0])->get(route('forms.rounds.numbers', $round))->assertForbidden();
        $this->as($this->mudirB)->get(route('forms.rounds.numbers', $round))->assertForbidden();
    }

    public function test_close_round_when_acks_complete_with_officer_task(): void
    {
        $round = $this->approvedRound();
        $ack = $this->ack($round);
        for ($i = 0; $i < 6; $i++) {
            $this->answer($this->emp[$i], $ack, ['yes', 'yes', 'yes', 'yes'], 'na');
        }
        $this->assertNotContains("round:{$round->id}:close", app(FormTasks::class)->tasksFor($this->salama)->pluck('key')->all(), 'بقي موظف لم يجب');
        $this->answer($this->emp[6], $ack, ['yes', 'yes', 'yes', 'yes'], 'na');
        $this->assertContains("round:{$round->id}:close", app(FormTasks::class)->tasksFor($this->salama)->pluck('key')->all(), 'اكتملت الإقرارات ← أقفل واعرض');
        $byKey = collect(app(RoundNumbersService::class)->compute($round, true)['items'])->keyBy('key');
        $this->assertSame('empty', $byKey['role']['kind'], 'الإدارة ادّعت والكل «لا ينطبق» ← لا مجيب على البند، لا «لم تدّعِ شيئاً»');
        $this->assertSame(7, $byKey['role']['na']);
        $this->assertSame('ok', $byKey['goals']['kind']);

        $this->as($this->mudirA)->post(route('forms.rounds.close', $round))->assertForbidden();
        $this->as($this->salama)->post(route('forms.rounds.close', $round))->assertSessionHas('ok');
        $round = $round->fresh();
        $this->assertSame(FormRound::STATE_CLOSED, $round->state);
        $log = $round->log;
        $this->assertSame('closed', end($log)['action']);
        $this->assertSame('اسم salama', end($log)['by']);
        $this->assertFalse($ack->fresh()->is_active, 'الإقرار يُوقف بالإقفال');
        $this->assertNotContains("round:{$round->id}:close", app(FormTasks::class)->tasksFor($this->salama)->pluck('key')->all());
        $this->as($this->salama)->post(route('forms.rounds.close', $round))->assertSessionHas('err');
        $this->as($this->salama)->get(route('forms.rounds.numbers', $round))->assertOk()->assertSee('data-level="4.0"', false);
    }
}
