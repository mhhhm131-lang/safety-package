<?php

namespace Tests\Feature\Form;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Form\Models\FormAssignment;
use App\Modules\Form\Models\FormField;
use App\Modules\Form\Models\FormRound;
use App\Modules\Form\Models\FormSubmission;
use App\Modules\Form\Models\FormTemplate;
use App\Modules\Form\Services\FormService;
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
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * المرحلة ٢٩ — الخطوة ٢٩-٥ «توليد الإقرار السري» (قرار ٨٨: «بعد تأكيد المدير يرسله النظام لجميع موظفي الإدارة»):
 *   عند اعتماد المدير يولّد النظام قالب إقرار سري للجولة من البنود التي قالت فيها الإدارة «نعم» (بند «لا» في مراحله كلها لا يُعرض)،
 *   في كل بند ما شاركته الإدارة (الادعاء ودليله ووثيقته) ثم العبارات الأربع بالمتكلم نعم/لا، وخيار «لا ينطبق على عملي»،
 *   و«ما الواقع» إلزامية عند «لا» على «أطبّقه فعلاً» وحدها؛ ويكلّف به موظفي الإدارة (ممن له حساب) دون المدير المعتمِد؛ الحالة «الإقرارات جارية».
 *   الإجابة سرية (٢٩-٢)، ونتائجها لمن يرى الكل، وصفحة الجولة تعرض العدد لا الأسماء.
 */
class FormAckTest extends TestCase
{
    use RefreshDatabase;

    private EmergencyBuilding $main;
    private OrganizationUnit $unitA;
    private OrganizationUnit $unitB;
    private User $salama;
    private User $mudirA;
    private User $mudirB;
    private User $coordA;
    private User $empA;
    private FormTemplate $card;
    private FormService $forms;

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
        $this->mudirA = $this->user('mudir.a', 'department_manager', $this->unitA);
        $this->mudirB = $this->user('mudir.b', 'department_manager', $this->unitB);
        $this->coordA = $this->user('coord.a', 'safety_coordinator', $this->unitA);
        $this->empA   = $this->user('emp.a', 'employee', $this->unitA);
        $this->card   = FormTemplate::where('package_code', NonMaterialPackage::CODE)->firstOrFail();
        $this->forms  = app(FormService::class);
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

    /** @return array<string, FormField> */
    private function claims(): array
    {
        $out = [];
        foreach ($this->card->fields as $f) {
            if ($f->isClaim()) $out[$f->claimDef()['item'].'.'.$f->claimDef()['stage']] = $f;
        }
        return $out;
    }

    /** يفتح جولة لإدارة أ ويعبّئ المدير: الأهداف نعم في المراحل الأربع (وثيقة PDF في «موثّق»)، الدور «موثّق» نعم فقط، والباقي لا؛ ثم يعتمد. */
    private function approvedRound(?User $manager = null, ?OrganizationUnit $unit = null): FormRound
    {
        $manager ??= $this->mudirA;
        $unit ??= $this->unitA;
        $claims = $this->claims();
        $this->as($this->salama)->post(route('forms.rounds.store'), ['unit_id' => $unit->id])->assertSessionHasNoErrors();
        $round = FormRound::withoutGlobalScope('building')->where('organization_unit_id', $unit->id)->latest('id')->firstOrFail();

        $answers = [];
        foreach ($claims as $f) {
            if (!$f->claimDef()['from_register']) $answers[$f->id] = ['claim' => 'no'];
        }
        $answers[$claims['goals.doc']->id] = ['claim' => 'yes', 'evidence' => 'خطة الإدارة ٢٠٢٦ — معتمدة ١٤/٩'];
        $answers[$claims['goals.ann']->id] = ['claim' => 'yes', 'evidence' => 'اجتماع الإدارة ٢٠/٩'];
        $answers[$claims['goals.und']->id] = ['claim' => 'yes', 'evidence' => 'ورشة تعريف'];
        $answers[$claims['goals.app']->id] = ['claim' => 'yes', 'evidence' => 'مؤشر ربعي'];
        $answers[$claims['role.doc']->id]  = ['claim' => 'yes', 'evidence' => 'الوصف الوظيفي المعتمد'];
        $this->as($manager)->post(route('forms.submit', $this->card), [
            'fields' => $answers,
            'photos' => [$claims['goals.doc']->id => UploadedFile::fake()->createWithContent('plan.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF")],
        ])->assertSessionHasNoErrors();
        $this->as($manager)->post(route('forms.rounds.approve', $round))->assertSessionHas('ok');

        return $round->fresh();
    }

    private function ack(FormRound $round): ?FormTemplate
    {
        return FormTemplate::where('package_code', NonMaterialPackage::ackCode($round->id))->first();
    }

    public function test_approval_generates_confidential_ack_from_yes_items_for_employees_only(): void
    {
        $round = $this->approvedRound();
        $this->assertSame(FormRound::STATE_ACK, $round->state, 'بعد الاعتماد: الإقرارات جارية');

        $ack = $this->ack($round);
        $this->assertNotNull($ack, 'قالب الإقرار وُلّد');
        $this->assertTrue($ack->allows_anonymous, 'سري');
        $this->assertSame(FormTemplate::FILLS_USER, $ack->fills_as);
        $this->assertSame($this->unitA->id, (int) $ack->organization_unit_id);
        $this->assertStringContainsString('إدارة أ', $ack->title);
        $this->assertStringContainsString('لا يُنسب جوابك إليك', (string) $ack->intro);

        $fields = $ack->fields;
        $this->assertCount(2, $fields, 'بندان قالت فيهما الإدارة نعم: الأهداف والدور — لا غير');
        $this->assertSame([FormField::TYPE_LADDER, FormField::TYPE_LADDER], $fields->pluck('field_type')->all());
        $this->assertSame(['goals', 'role'], $fields->map(fn ($f) => $f->ladderDef()['item'])->all());
        $goals = $fields[0]->ladderDef();
        $this->assertSame('أهداف الإدارة وارتباطها بأهداف المعهد ورؤيته ورؤية ٢٠٣٠', $fields[0]->label);
        $this->assertSame('yes', $goals['claims']['doc']['claim']);
        $this->assertSame('خطة الإدارة ٢٠٢٦ — معتمدة ١٤/٩', $goals['claims']['doc']['evidence']);
        $this->assertSame('plan.pdf', $goals['claims']['doc']['file_name'], 'الوثيقة التي رفعتها الإدارة تُعرض للموظف');
        $this->assertNotNull($goals['claims']['doc']['answer_id']);
        $this->assertSame('yes', $goals['claims']['app']['claim']);
        $role = $fields[1]->ladderDef();
        $this->assertSame('yes', $role['claims']['doc']['claim']);
        $this->assertSame('no', $role['claims']['ann']['claim']);
        $this->assertTrue($fields[0]->is_required);

        $assigned = FormAssignment::withoutGlobalScope('building')->where('form_id', $ack->id)->pluck('assigned_to_id')->map(fn ($v) => (int) $v)->sort()->values()->all();
        $this->assertSame(collect([$this->coordA->id, $this->empA->id])->sort()->values()->all(), $assigned, 'موظفو الإدارة بحساب، دون المدير المعتمِد ودون إدارة ب');
        $this->assertSame($round->id, (int) FormAssignment::withoutGlobalScope('building')->where('form_id', $ack->id)->first()->round_id);
        $this->assertTrue(AppNotification::where('user_id', $this->empA->id)->where('type', 'form.assigned')->exists());

        $log = $round->log;
        $last = end($log);
        $this->assertSame('acks_sent', $last['action']);
        $this->assertStringContainsString('2', $last['note']);
        $this->assertTrue(AppNotification::where('user_id', $this->salama->id)->where('type', 'form.round')->where('message', 'like', '%الإقرار%')->exists());

        // إدارة بلا موظفين غير مديرها: الاعتماد يمرّ ولا إقرار، والحالة تبقى «اعتمدها المدير» وسجلها يقول لماذا
        $rb = $this->approvedRound($this->mudirB, $this->unitB);
        $this->assertSame(FormRound::STATE_DONE, $rb->state);
        $this->assertNull($this->ack($rb));
        $logB = $rb->log;
        $this->assertSame('acks_none', end($logB)['action']);
    }

    public function test_employee_answers_ladder_confidentially_and_reality_is_required_when_not_applied(): void
    {
        $round = $this->approvedRound();
        $ack = $this->ack($round);
        [$goals, $role] = [$ack->fields[0], $ack->fields[1]];
        $docAnswerId = (int) $goals->ladderDef()['claims']['doc']['answer_id'];

        $this->as($this->empA);
        $page = $this->get(route('forms.fill', $ack));
        $page->assertOk()->assertSee('لا يُنسب جوابك إليك')->assertSee('أهداف الإدارة')->assertSee('خطة الإدارة ٢٠٢٦')->assertSee('plan.pdf')
            ->assertSee('وصلتني وثيقة مكتوبة بهذا')->assertSee('أطبّقه فعلاً في عملي اليومي')->assertSee('لا ينطبق على عملي')->assertSee('data-round-header', false)->assertSee('اسم mudir.a');
        $this->get(route('forms.answers.file', [$this->card, $docAnswerId]))->assertOk();
        $this->as($this->mudirB)->get(route('forms.answers.file', [$this->card, $docAnswerId]))->assertForbidden();

        // «لا» على «أطبّقه» بلا «ما الواقع» تُرفض
        $this->as($this->empA);
        $fields = [$goals->id => ['doc' => 'yes', 'ann' => 'yes', 'und' => 'yes', 'app' => 'no', 'note' => ''], $role->id => ['na' => '1']];
        $this->post(route('forms.submit', $ack), ['fields' => $fields])->assertSessionHasErrors("fields.{$goals->id}.note");
        // بند بلا جواب يُرفض
        $this->post(route('forms.submit', $ack), ['fields' => [$goals->id => ['doc' => 'yes', 'ann' => 'yes', 'und' => 'yes', 'app' => 'yes']]])->assertSessionHasErrors("fields.{$role->id}");

        $fields[$goals->id]['note'] = 'الصلاحيات في الورقة غير التي نُمارسها';
        $this->post(route('forms.submit', $ack), ['fields' => $fields])->assertSessionHasNoErrors()->assertRedirect(route('forms.submitted', $ack));

        $s = FormSubmission::withoutGlobalScope('building')->where('form_id', $ack->id)->sole();
        $this->assertNull($s->submitted_by_id, 'سرية');
        $this->assertSame($round->id, (int) $s->round_id);
        $by = $s->answers->keyBy('field_id');
        $this->assertSame(['na' => false, 'doc' => 'yes', 'ann' => 'yes', 'und' => 'yes', 'app' => 'no', 'note' => 'الصلاحيات في الورقة غير التي نُمارسها'], $by[$goals->id]->ladderValue());
        $this->assertTrue($by[$role->id]->ladderValue()['na']);
        $this->get(route('forms.fill', $ack))->assertForbidden();

        $this->as($this->salama);
        $summary = collect($this->forms->resultsSummary($ack, $this->salama))->keyBy(fn ($x) => $x['field']->id);
        $this->assertSame(1, $summary[$goals->id]['distribution']['أطبّقه فعلاً في عملي اليومي — لا']);
        $this->assertSame(1, $summary[$goals->id]['distribution']['وصلتني وثيقة مكتوبة بهذا — نعم']);
        $this->assertSame(1, $summary[$role->id]['distribution'][NonMaterialPackage::NA_LABEL]);
        $this->get(route('forms.results', $ack))->assertOk()->assertSee('خمسة');
        $this->as($this->mudirA)->get(route('forms.results', $ack))->assertForbidden();
        $this->as($this->coordA)->get(route('forms.results', $ack))->assertForbidden();
    }

    public function test_round_page_shows_ack_counts_not_names(): void
    {
        $round = $this->approvedRound();
        $ack = $this->ack($round);
        $this->as($this->empA)->post(route('forms.submit', $ack), ['fields' => [$ack->fields[0]->id => ['na' => '1'], $ack->fields[1]->id => ['na' => '1']]])->assertSessionHasNoErrors();

        $this->as($this->mudirA)->get(route('forms.rounds.show', $round))->assertOk()
            ->assertSee('data-round-state="ack"', false)->assertSee('الإقرار السري')->assertSee('data-ack-total="2"', false)->assertSee('data-ack-done="1"', false)
            ->assertDontSee('اسم emp.a')->assertDontSee('اسم coord.a');
        $this->as($this->salama)->get(route('forms.rounds.show', $round))->assertOk()->assertSee('data-ack-done="1"', false);
    }
}
