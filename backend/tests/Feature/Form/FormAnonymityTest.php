<?php

namespace Tests\Feature\Form;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Form\Models\FormAssignment;
use App\Modules\Form\Models\FormField;
use App\Modules\Form\Models\FormSubmission;
use App\Modules\Form\Models\FormTemplate;
use App\Modules\Form\Services\FormService;
use App\Modules\Governance\Models\AppNotification;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٢٩ — الخطوة ٢٩-٢ «السرية» (قرار ٨٨ §٤، صندوقان):
 *   صندوق التكليف: اسم ووقت إكمال بلا إجابة. صندوق الإجابات: الأجوبة والإدارة فقط — لا شخص ولا تكليف ولا جهاز، والتاريخ باليوم.
 *   من اختار «أُعلن اسمي» يُحفظ منسوباً. قالب لا يقبل السرية لا يُسرّ. النتائج والتصدير والمتابعة والتأخر: العدد لا الأسماء.
 *   تحت خمسة مجيبين لا نتائج. ونتائج القالب السري لمن يرى الكل (مسؤول السلامة، المناوب، الإدارة العليا، اللجنة) لا لمنسق إدارة.
 */
class FormAnonymityTest extends TestCase
{
    use RefreshDatabase;

    private EmergencyBuilding $main;
    private OrganizationUnit $unitA;
    private User $salama;
    private User $coordA;
    /** @var User[] */
    private array $emp = [];
    private FormTemplate $anon;
    private FormTemplate $named;
    private FormField $text;
    private FormField $radio;
    private FormService $forms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
        $this->main = EmergencyBuilding::main();
        $hq = OrganizationUnit::create(['code' => 'hq', 'name' => 'المركز الرئيسي', 'unit_type' => 'company']);
        $this->main->update(['branch_unit_id' => $hq->id]);
        $this->unitA = OrganizationUnit::create(['code' => 'hq-a', 'name' => 'إدارة أ', 'unit_type' => 'department', 'parent_id' => $hq->id, 'place_id' => Place::idByCode('HZ-06')]);

        $this->salama = $this->user('salama', 'system_admin');
        $this->coordA = $this->user('coord.a', 'safety_coordinator', $this->unitA);
        for ($i = 0; $i < 5; $i++) {
            $this->emp[$i] = $this->user("emp.$i", 'employee', $this->unitA);
        }
        $this->forms = app(FormService::class);

        $this->anon = FormTemplate::create(['title' => 'إقرار سري', 'form_type' => FormTemplate::TYPE_SURVEY, 'allows_anonymous' => true, 'created_by_id' => $this->salama->id]);
        $this->text  = $this->anon->fields()->create(['label' => 'ما الواقع؟', 'field_type' => FormField::TYPE_TEXT, 'is_required' => true, 'order' => 1]);
        $this->radio = $this->anon->fields()->create(['label' => 'هل وصلك؟', 'field_type' => FormField::TYPE_RADIO, 'options' => ['نعم', 'لا'], 'is_required' => true, 'order' => 2]);
        $this->named = FormTemplate::create(['title' => 'استبيان بالاسم', 'form_type' => FormTemplate::TYPE_SURVEY, 'created_by_id' => $this->salama->id]);
        $this->named->fields()->create(['label' => 'رأيك', 'field_type' => FormField::TYPE_TEXT, 'is_required' => true, 'order' => 1]);
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

    private function assign(FormTemplate $form, array $users, ?string $due = null): void
    {
        $this->as($this->salama);
        $this->forms->assignToUsers($form, array_map(fn (User $u) => $u->id, $users), $due, $this->salama->id);
    }

    private function answers(string $text = 'الصلاحيات في الورقة غير التي نُمارسها'): array
    {
        return ['fields' => [$this->text->id => $text, $this->radio->id => 'نعم']];
    }

    public function test_anonymous_submission_keeps_no_person_no_assignment_no_device_and_day_only_date(): void
    {
        $this->assign($this->anon, [$this->emp[0]]);

        $this->as($this->emp[0]);
        $this->post(route('forms.submit', $this->anon), $this->answers())
            ->assertSessionHasNoErrors()->assertRedirect(route('forms.submitted', $this->anon));

        $s = FormSubmission::withoutGlobalScope('building')->sole();
        $this->assertNull($s->submitted_by_id, 'لا شخص');
        $this->assertNull($s->assignment_id, 'لا تكليف');
        $this->assertNull($s->submitted_ip, 'لا جهاز');
        $this->assertTrue($s->submitted_at->eq(now()->startOfDay()), 'التاريخ باليوم لا بالساعة');
        $this->assertTrue($s->created_at->eq(now()->startOfDay()), 'وقت الإنشاء كذلك');
        $this->assertSame($this->unitA->id, (int) $s->organization_unit_id, 'الإدارة محفوظة');
        $this->assertSame($this->main->id, (int) $s->building_id, 'المبنى محفوظ');
        $this->assertSame(2, $s->answers()->count());

        $a = FormAssignment::withoutGlobalScope('building')->where('assigned_to_id', $this->emp[0]->id)->sole();
        $this->assertSame(FormAssignment::STATUS_COMPLETED, $a->status, 'صندوق التكليف: مكتمل');
        $this->assertNotNull($a->completed_at);
        $this->assertNull($a->submission, 'لا رابط من التكليف إلى الإجابة');

        $this->get(route('forms.fill', $this->anon))->assertForbidden();
        $this->get(route('forms.submitted', $this->anon))->assertOk()->assertSee('سرياً')->assertSee(now()->format('Y-m-d'));
    }

    public function test_reveal_name_keeps_attribution_and_named_template_ignores_the_flag(): void
    {
        $this->assign($this->anon, [$this->emp[0]]);
        $this->assign($this->named, [$this->emp[1]]);

        $this->as($this->emp[0]);
        $this->post(route('forms.submit', $this->anon), $this->answers() + ['reveal_name' => '1'])->assertSessionHasNoErrors();
        $s = FormSubmission::withoutGlobalScope('building')->sole();
        $this->assertSame($this->emp[0]->id, (int) $s->submitted_by_id, 'أعلن اسمه فنُسبت إليه');
        $this->assertNotNull($s->assignment_id);
        $this->assertNotNull($s->submitted_ip);

        $this->as($this->emp[1]);
        $field = $this->named->fields->first();
        $this->post(route('forms.submit', $this->named), ['fields' => [$field->id => 'رأي'], 'reveal_name' => '0'])->assertSessionHasNoErrors();
        $n = FormSubmission::withoutGlobalScope('building')->where('form_id', $this->named->id)->sole();
        $this->assertSame($this->emp[1]->id, (int) $n->submitted_by_id, 'قالب لا يقبل السرية لا يُسرّ');
    }

    public function test_results_hidden_below_five_then_shown_without_names_or_clock(): void
    {
        $this->assign($this->anon, $this->emp);
        for ($i = 0; $i < 4; $i++) {
            $this->as($this->emp[$i]);
            $this->post(route('forms.submit', $this->anon), $this->answers("واقع $i"))->assertSessionHasNoErrors();
        }

        $this->as($this->salama);
        $r = $this->get(route('forms.results', $this->anon));
        $r->assertOk()->assertSee('خمسة')->assertDontSee('واقع 0')->assertDontSee('اسم emp');
        $this->get(route('forms.export', $this->anon))->assertRedirect()->assertSessionHas('err');

        $this->as($this->emp[4]);
        $this->post(route('forms.submit', $this->anon), $this->answers('واقع 4'))->assertSessionHasNoErrors();

        $this->as($this->salama);
        $r = $this->get(route('forms.results', $this->anon));
        $r->assertOk()->assertSee('واقع 4')->assertSee('سري')->assertDontSee('اسم emp')->assertDontSee(now()->format('Y-m-d H:'));
        $this->assertCount(5, $this->forms->results($this->anon, $this->salama));
        $csv = $this->get(route('forms.export', $this->anon))->streamedContent();
        $this->assertStringContainsString('سري', $csv);
        $this->assertStringNotContainsString('اسم emp', $csv);
        $this->assertStringNotContainsString(now()->format('Y-m-d H:'), $csv);
        $this->assertStringContainsString(now()->format('Y-m-d'), $csv);
    }

    public function test_unit_coordinator_cannot_open_anonymous_results_and_tracking_shows_counts_not_names(): void
    {
        $this->assign($this->anon, [$this->emp[0], $this->emp[1]]);
        $this->assign($this->named, [$this->emp[2]]);
        $this->as($this->emp[0]);
        $this->post(route('forms.submit', $this->anon), $this->answers())->assertSessionHasNoErrors();

        $this->as($this->coordA);
        $this->get(route('forms.results', $this->anon))->assertForbidden();
        $this->get(route('forms.export', $this->anon))->assertForbidden();
        $t = $this->get(route('forms.tracking', $this->anon));
        $t->assertOk()->assertSee('data-stat="مكلَّف"', false)->assertSee('ذكّر من لم يعبّئ')
            ->assertDontSee('اسم emp.0')->assertDontSee('اسم emp.1');
        $this->get(route('forms.tracking', $this->named))->assertOk()->assertSee('اسم emp.2');

        $this->as($this->salama);
        $this->get(route('forms.results', $this->anon))->assertOk();
    }

    public function test_overdue_notification_names_nobody_on_anonymous_template(): void
    {
        $this->assign($this->anon, [$this->emp[0]], now()->subDay()->toDateString());
        $this->assign($this->named, [$this->emp[1]], now()->subDay()->toDateString());

        $this->artisan('forms:check-overdue')->assertSuccessful();

        $mine = AppNotification::where('user_id', $this->salama->id)->where('type', 'form.overdue')->get();
        $this->assertCount(2, $mine);
        $anon = $mine->firstWhere('title', 'تأخر مكلَّف عن نموذج: إقرار سري');
        $this->assertNotNull($anon);
        $this->assertStringNotContainsString('اسم emp.0', (string) $anon->message);
        $named = $mine->firstWhere('title', 'تأخر مكلَّف عن نموذج: استبيان بالاسم');
        $this->assertStringContainsString('اسم emp.1', (string) $named->message);
    }
}
