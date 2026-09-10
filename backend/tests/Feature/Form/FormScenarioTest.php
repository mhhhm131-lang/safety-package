<?php

namespace Tests\Feature\Form;

use App\Models\User;
use App\Modules\Form\Models\FormAssignment;
use App\Modules\Form\Models\FormField;
use App\Modules\Form\Models\FormSubmission;
use App\Modules\Form\Models\FormTemplate;
use App\Modules\Form\Services\FormService;
use App\Modules\Governance\Models\AppNotification;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskControl;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Database\Seeders\RiskBookSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * المرحلة ٧ — بوابة المرحلة (BACKEND.md ٧-٢):
 * إقرار يُولَّد من خطر ← تكليف ← توقيع ← نتائج ← متأخرون.
 *
 * ويغطي **مسار التعبئة** الذي لا اختبار له في OHSMS، وهو الذي كان معطوباً هناك:
 * الشاشة ترسل `fields[]` والخدمة تقرأ `answers[]`، و`submitted_at` بلا عمود.
 */
class FormScenarioTest extends TestCase
{
    use RefreshDatabase;

    private User $salama;   // مسؤول السلامة
    private User $mudir;    // مدير إدارة (مكلَّف)
    private User $fani;     // فني (مكلَّف)
    private FormService $forms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, RiskBookSeeder::class]);

        $this->salama = $this->user('salama', 'system_admin');
        $this->mudir  = $this->user('mudir', 'department_manager');
        $this->fani   = $this->user('fani', 'field_worker', placeCode: 'HZ-02');
        $this->forms  = app(FormService::class);
    }

    private function user(string $username, string $role, ?string $placeCode = null, ?int $unitId = null): User
    {
        $u = User::create([
            'username' => $username, 'name' => "اسم {$username}",
            'password' => '123456', 'email' => "{$username}@example.test",
        ]);
        UserProfile::create([
            'user_id' => $u->id, 'role' => $role, 'is_active' => true,
            'place_id' => $placeCode ? Place::idByCode($placeCode) : null,
            'organization_unit_id' => $unitId,
        ]);

        return $u;
    }

    /** خطر فعّال بدرجة محددة على مكان، مع بند تحكم استباقي. */
    private function risk(int $severity, int $likelihood, string $placeCode = 'HZ-02', string $title = 'خطر اختبار'): Risk
    {
        $category = RiskCategory::where('name', 'الحريق والانفجار')->firstOrFail();
        $risk = Risk::create([
            'risk_type' => 'active', 'title' => $title, 'description' => 'وصف الخطر للاختبار',
            'category_id' => $category->id, 'place_id' => Place::idByCode($placeCode),
            'severity' => $severity, 'likelihood' => $likelihood, 'status' => 'active',
        ]);
        RiskControl::create([
            'risk_id' => $risk->id, 'risk_category_id' => $category->id, 'phase' => 'preventive',
            'description_ar' => 'إبعاد المواد القابلة للاشتعال عن مصدر الحرارة', 'evidence_type' => 'check',
        ]);

        return $risk;
    }

    // ════════════ البوابة ════════════

    public function test_gate_declaration_from_risk_assign_sign_results_overdue(): void
    {
        $s = $this->actingAs($this->salama);

        // ١) الشاشات تفتح لمسؤول السلامة
        foreach (['/app/forms', '/app/forms/create', '/app/forms/generate', '/app/forms/mine'] as $url) {
            $s->get($url)->assertOk();
        }

        // ٢) توليد نموذج من خطر شديد (٤×٤=١٦) → إقرار موقَّع بشاهد
        $risk = $this->risk(4, 4, 'HZ-02', 'تراكم مواد قابلة للاشتعال قرب اللوحات');
        $s->post('/app/forms/generate', [
            'title'    => 'إقرار بمخاطر غرف الكهرباء',
            'risk_ids' => [$risk->id],
        ])->assertRedirect();

        $form = FormTemplate::where('title', 'إقرار بمخاطر غرف الكهرباء')->firstOrFail();
        $this->assertSame(FormTemplate::TYPE_DECLARATION_WITNESSED, $form->form_type, 'درجة ١٦ تستوجب إقراراً بشاهد');
        $this->assertStringContainsString('تراكم مواد', $form->intro);
        $this->assertStringContainsString('إبعاد المواد القابلة للاشتعال', $form->intro, 'ضوابط الخطر تظهر في المقدمة');
        $this->assertSame(Place::idByCode('HZ-02'), $form->place_id);
        $this->assertTrue($form->risks->contains($risk->id));

        // حقوله: إقرار + ملاحظات + توقيع + اسم الشاهد + توقيع الشاهد
        $types = $form->fields->pluck('field_type')->all();
        $this->assertContains(FormField::TYPE_ACKNOWLEDGE, $types);
        $this->assertSame(2, collect($types)->filter(fn ($t) => $t === FormField::TYPE_SIGNATURE)->count());

        // ٣) التكليف بالمكان: الفني وحده مكانه HZ-02
        $s->post("/app/forms/{$form->id}/send", [
            'mode' => 'place', 'place_id' => Place::idByCode('HZ-02'),
            'due_date' => now()->addDays(3)->toDateString(),
        ])->assertRedirect();

        $this->assertSame(1, $form->assignments()->count());
        $assignment = $form->assignments()->first();
        $this->assertSame($this->fani->id, $assignment->assigned_to_id);
        $this->assertSame('place', $assignment->source);

        // وأُشعر المكلَّف
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->fani->id, 'type' => 'form.assigned']);

        // ٤) غير المكلَّف لا يعبّئ
        $this->actingAs($this->mudir)->get("/app/forms/{$form->id}/fill")->assertForbidden();

        // ٥) المكلَّف يفتح النموذج ويرى مقدمته
        $this->actingAs($this->fani)->get("/app/forms/{$form->id}/fill")
            ->assertOk()->assertSee('اقرأ قبل التعبئة')->assertSee('تراكم مواد');

        // ٦) الإرسال بلا إقرار وبلا توقيع مرفوض
        $ack  = $form->fields->firstWhere('field_type', FormField::TYPE_ACKNOWLEDGE);
        $sigs = $form->fields->where('field_type', FormField::TYPE_SIGNATURE)->values();
        $witness = $form->fields->firstWhere('label', 'اسم الشاهد وصفته');

        $this->actingAs($this->fani)->post("/app/forms/{$form->id}/fill", [])
            ->assertSessionHasErrors(["fields.{$ack->id}", "signatures.{$sigs[0]->id}"]);
        $this->assertSame(0, FormSubmission::count(), 'لا تُسجَّل تعبئة ناقصة');

        // ٧) التعبئة الكاملة بتوقيعين
        $png = 'data:image/png;base64,'.base64_encode(
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')
        );
        $this->actingAs($this->fani)->post("/app/forms/{$form->id}/fill", [
            'fields' => [
                $ack->id     => '1', // خانة الإقرار ترسل "1" (قاعدة accepted)
                $witness->id => 'م. سعد — مشرف السلامة',
            ],
            'signatures' => [$sigs[0]->id => $png, $sigs[1]->id => $png],
        ])->assertSessionHasNoErrors()->assertRedirect();

        // ٨) التعبئة حُفظت فعلاً — وهذا ما كان معطوباً في OHSMS
        $submission = FormSubmission::firstOrFail();
        $this->assertSame($this->fani->id, $submission->submitted_by_id);
        $this->assertNotNull($submission->submitted_at, 'submitted_at عمود حقيقي');
        $this->assertSame($assignment->id, $submission->assignment_id, 'التعبئة مربوطة بتكليفها');
        $this->assertSame(4, $submission->answers()->count(), 'إقرار + شاهد + توقيعان');

        $signature = $submission->answers()->where('field_id', $sigs[0]->id)->firstOrFail();
        $this->assertTrue($signature->hasFile(), 'التوقيع محفوظ base64 في القاعدة');
        $this->assertSame('image/png', $signature->file_mime);

        // ٩) التكليف أُغلق بالتعبئة
        $assignment->refresh();
        $this->assertSame(FormAssignment::STATUS_COMPLETED, $assignment->status);
        $this->assertNotNull($assignment->completed_at);

        // ١٠) لا تعبئة مرتين
        $this->actingAs($this->fani)->get("/app/forms/{$form->id}/fill")->assertForbidden();

        // ١١) النتائج تعرض الردّ والتوقيع
        $res = $this->actingAs($this->salama)->get("/app/forms/{$form->id}/results");
        $res->assertOk()->assertSee('م. سعد')->assertSee('التوقيع');
        $this->actingAs($this->salama)
            ->get(route('forms.answers.file', [$form, $signature]))->assertOk();

        // ١٢) المتأخرون: تكليف ثانٍ مضت مهلته ← الأمر المجدول يعلّمه ويُشعر
        $this->forms->assignToUsers($form, [$this->mudir->id], now()->subDay()->toDateString(), $this->salama->id);
        $late = $form->assignments()->where('assigned_to_id', $this->mudir->id)->firstOrFail();
        $this->assertSame(FormAssignment::STATUS_PENDING, $late->status);

        $this->artisan('forms:check-overdue')->assertSuccessful();

        $late->refresh();
        $this->assertSame(FormAssignment::STATUS_OVERDUE, $late->status);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->mudir->id, 'type' => 'form.overdue']);

        // ١٣) شاشة المتابعة تُظهر النسبة والمتأخر
        $this->actingAs($this->salama)->get("/app/forms/{$form->id}/tracking")
            ->assertOk()->assertSee('متأخر')->assertSee('50%');
    }

    // ════════════ التوليد من المخاطر ════════════

    public function test_form_type_follows_the_highest_risk_score(): void
    {
        $cases = [
            [1, 2, FormTemplate::TYPE_AWARENESS],              // ٢
            [3, 3, FormTemplate::TYPE_CONFIRMATION],           // ٩
            [4, 3, FormTemplate::TYPE_DECLARATION],            // ١٢
            [5, 5, FormTemplate::TYPE_DECLARATION_WITNESSED],  // ٢٥
        ];

        foreach ($cases as $i => [$severity, $likelihood, $expected]) {
            $risk = $this->risk($severity, $likelihood, 'HZ-06', "خطر {$i}");
            $form = $this->forms->generateFromRisks([$risk->id], "نموذج {$i}", null, $this->salama->id);

            $this->assertSame($expected, $form->form_type, "درجة {$risk->risk_score} تستوجب {$expected}");
        }
    }

    public function test_awareness_form_has_no_signature_field(): void
    {
        $risk = $this->risk(1, 2, 'HZ-06');
        $form = $this->forms->generateFromRisks([$risk->id], 'توعية', null, $this->salama->id);

        $this->assertFalse($form->requiresSignature());
        $this->assertSame(1, $form->fields()->count(), 'الإقرار وحده');
        $this->assertSame(FormField::TYPE_ACKNOWLEDGE, $form->fields()->first()->field_type);
    }

    public function test_generate_uses_the_highest_risk_as_source_and_links_all(): void
    {
        $low  = $this->risk(2, 2, 'HZ-06', 'خطر أدنى');
        $high = $this->risk(5, 4, 'HZ-06', 'خطر أشدّ');

        $form = $this->forms->generateFromRisks([$low->id, $high->id], 'نموذج', null, $this->salama->id);

        $this->assertSame($high->id, $form->source_risk_id);
        $this->assertSame(2, $form->risks()->count());
        // المقدمة تبدأ بالأشدّ
        $this->assertLessThan(
            strpos($form->intro, 'خطر أدنى'),
            strpos($form->intro, 'خطر أشدّ'),
            'المخاطر مرتبة بالأشد أولاً',
        );
    }

    public function test_generate_refuses_empty_risk_selection(): void
    {
        $this->actingAs($this->salama)
            ->post('/app/forms/generate', ['title' => 'بلا مخاطر', 'risk_ids' => []])
            ->assertSessionHasErrors('risk_ids');
    }

    // ════════════ التكليف ════════════

    public function test_assign_by_role_covers_every_active_holder(): void
    {
        $second = $this->user('fani2', 'field_worker');
        $inactive = $this->user('fani3', 'field_worker');
        $inactive->profile->update(['is_active' => false]);

        $form = $this->simpleForm();
        $count = $this->forms->assignToRole($form, 'field_worker', null, $this->salama->id);

        $this->assertSame(2, $count, 'المفعّلان فقط');
        $this->assertEqualsCanonicalizing(
            [$this->fani->id, $second->id],
            $form->assignments()->pluck('assigned_to_id')->all(),
        );
    }

    public function test_assign_by_unit_includes_descendants(): void
    {
        $parent = OrganizationUnit::firstOrFail();
        $child  = OrganizationUnit::create([
            'code' => 'test-child', 'name' => 'قسم فرعي', 'type' => 'section', 'parent_id' => $parent->id,
        ]);

        $inParent = $this->user('u-parent', 'employee', unitId: $parent->id);
        $inChild  = $this->user('u-child', 'employee', unitId: $child->id);

        $form = $this->simpleForm();
        $count = $this->forms->assignToUnit($form, $parent->id, null, $this->salama->id);

        $this->assertSame(2, $count);
        $this->assertEqualsCanonicalizing(
            [$inParent->id, $inChild->id],
            $form->assignments()->pluck('assigned_to_id')->all(),
        );
    }

    public function test_assigning_twice_does_not_duplicate_but_updates_due_date(): void
    {
        $form = $this->simpleForm();

        $this->forms->assignToUsers($form, [$this->fani->id], now()->addDays(2)->toDateString(), $this->salama->id);
        $second = $this->forms->assignToUsers($form, [$this->fani->id], now()->addDays(9)->toDateString(), $this->salama->id);

        $this->assertSame(0, $second, 'لا تكليف ثانٍ لنفس الشخص');
        $this->assertSame(1, $form->assignments()->count());
        $this->assertSame(
            now()->addDays(9)->toDateString(),
            $form->assignments()->first()->due_date->toDateString(),
            'تُحدَّث المهلة فقط',
        );
    }

    public function test_reminder_is_actually_sent_not_just_stamped(): void
    {
        $form = $this->simpleForm();
        $this->forms->assignToUsers($form, [$this->fani->id], null, $this->salama->id);
        $assignment = $form->assignments()->first();

        AppNotification::where('user_id', $this->fani->id)->delete();

        $this->assertTrue($this->forms->remind($assignment));
        $this->assertNotNull($assignment->refresh()->reminded_at);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->fani->id, 'type' => 'form.reminder']);
    }

    public function test_reminder_is_refused_for_completed_assignment(): void
    {
        $form = $this->simpleForm();
        $this->forms->assignToUsers($form, [$this->fani->id], null, $this->salama->id);
        $assignment = $form->assignments()->first();
        $assignment->update(['status' => FormAssignment::STATUS_COMPLETED]);

        $this->assertFalse($this->forms->remind($assignment));
    }

    // ════════════ التعبئة ════════════

    public function test_every_field_type_round_trips(): void
    {
        $form = $this->simpleForm(['form_type' => FormTemplate::TYPE_SURVEY]);
        $fields = [];
        foreach ([
            FormField::TYPE_TEXT     => ['نص', null],
            FormField::TYPE_NUMBER   => ['رقم', null],
            FormField::TYPE_TEXTAREA => ['شرح', null],
            FormField::TYPE_DATE     => ['تاريخ', null],
            FormField::TYPE_SELECT   => ['قائمة', ['أ', 'ب']],
            FormField::TYPE_RADIO    => ['واحد', ['نعم', 'لا']],
            FormField::TYPE_CHECKBOX => ['متعدد', ['خيار١', 'خيار٢', 'خيار٣']],
        ] as $type => [$label, $options]) {
            $fields[$type] = $this->forms->addField($form, [
                'label' => $label, 'field_type' => $type, 'is_required' => false,
                'options' => $options,
            ]);
        }
        $photo = $this->forms->addField($form, ['label' => 'صورة', 'field_type' => FormField::TYPE_PHOTO]);

        $this->forms->assignToUsers($form, [$this->fani->id], null, $this->salama->id);

        $this->actingAs($this->fani)->post("/app/forms/{$form->id}/fill", [
            'fields' => [
                $fields[FormField::TYPE_TEXT]->id     => 'نص حرّ',
                $fields[FormField::TYPE_NUMBER]->id   => '42',
                $fields[FormField::TYPE_TEXTAREA]->id => 'شرح طويل',
                $fields[FormField::TYPE_DATE]->id     => '2026-09-09',
                $fields[FormField::TYPE_SELECT]->id   => 'ب',
                $fields[FormField::TYPE_RADIO]->id    => 'نعم',
                $fields[FormField::TYPE_CHECKBOX]->id => ['خيار١', 'خيار٣'],
            ],
            'photos' => [$photo->id => UploadedFile::fake()->image('site.jpg')],
        ])->assertRedirect();

        $submission = FormSubmission::firstOrFail();
        $byField = $submission->answers->keyBy('field_id');

        $this->assertSame('نص حرّ', $byField[$fields[FormField::TYPE_TEXT]->id]->value);
        $this->assertSame('42', $byField[$fields[FormField::TYPE_NUMBER]->id]->value);
        $this->assertSame('ب', $byField[$fields[FormField::TYPE_SELECT]->id]->value);
        $this->assertSame('خيار١، خيار٣', $byField[$fields[FormField::TYPE_CHECKBOX]->id]->display());
        $this->assertTrue($byField[$photo->id]->hasFile());
    }

    public function test_required_number_and_date_are_validated(): void
    {
        $form = $this->simpleForm();
        $number = $this->forms->addField($form, ['label' => 'عدد', 'field_type' => FormField::TYPE_NUMBER, 'is_required' => true]);
        $this->forms->assignToUsers($form, [$this->fani->id], null, $this->salama->id);

        $this->actingAs($this->fani)
            ->post("/app/forms/{$form->id}/fill", ['fields' => [$number->id => 'ليس رقماً']])
            ->assertSessionHasErrors("fields.{$number->id}");
    }

    public function test_malformed_signature_payload_is_rejected(): void
    {
        $form = $this->simpleForm();
        $sig = $this->forms->addField($form, ['label' => 'توقيع', 'field_type' => FormField::TYPE_SIGNATURE, 'is_required' => false]);
        $this->forms->assignToUsers($form, [$this->fani->id], null, $this->salama->id);

        $this->actingAs($this->fani)->post("/app/forms/{$form->id}/fill", [
            'signatures' => [$sig->id => 'javascript:alert(1)'],
        ])->assertRedirect();

        $submission = FormSubmission::firstOrFail();
        $this->assertSame(0, $submission->answers()->count(), 'توقيع غير صالح لا يُحفظ');
    }

    public function test_fill_is_blocked_when_form_is_inactive(): void
    {
        $form = $this->simpleForm();
        $this->forms->addField($form, ['label' => 'نص', 'field_type' => FormField::TYPE_TEXT]);
        $this->forms->assignToUsers($form, [$this->fani->id], null, $this->salama->id);
        $form->update(['is_active' => false]);

        $this->actingAs($this->fani)->get("/app/forms/{$form->id}/fill")->assertForbidden();
    }

    // ════════════ الصلاحيات والحقول ════════════

    public function test_field_worker_cannot_manage_forms_but_sees_his_own(): void
    {
        $this->actingAs($this->fani)->get('/app/forms')->assertForbidden();
        $this->actingAs($this->fani)->get('/app/forms/create')->assertForbidden();
        $this->actingAs($this->fani)->get('/app/forms/mine')->assertOk();
    }

    public function test_fields_are_locked_once_a_submission_exists(): void
    {
        $form = $this->simpleForm();
        $field = $this->forms->addField($form, ['label' => 'نص', 'field_type' => FormField::TYPE_TEXT]);
        $this->forms->assignToUsers($form, [$this->fani->id], null, $this->salama->id);

        $this->actingAs($this->salama)
            ->post("/app/forms/{$form->id}/fields", ['label' => 'حقل ثانٍ', 'field_type' => 'text'])
            ->assertRedirect();

        $this->actingAs($this->fani)->post("/app/forms/{$form->id}/fill", ['fields' => [$field->id => 'قيمة']]);

        $this->actingAs($this->salama)
            ->post("/app/forms/{$form->id}/fields", ['label' => 'حقل ثالث', 'field_type' => 'text'])
            ->assertForbidden();
    }

    public function test_options_are_normalised_from_lines_and_cleared_for_other_types(): void
    {
        $form = $this->simpleForm();

        $select = $this->forms->addField($form, [
            'label' => 'قائمة', 'field_type' => FormField::TYPE_SELECT,
            'options' => "أ\r\nب\n\n ج ",
        ]);
        $this->assertSame(['أ', 'ب', 'ج'], $select->options);

        $text = $this->forms->addField($form, [
            'label' => 'نص', 'field_type' => FormField::TYPE_TEXT, 'options' => "أ\nب",
        ]);
        $this->assertNull($text->options, 'الأنواع بلا خيارات تُفرَّغ');
    }

    // ════════════ النتائج ════════════

    public function test_results_summary_counts_distribution(): void
    {
        $form = $this->simpleForm();
        $radio = $this->forms->addField($form, [
            'label' => 'هل تحتاج تدريباً؟', 'field_type' => FormField::TYPE_RADIO, 'options' => ['نعم', 'لا'],
        ]);
        $second = $this->user('emp2', 'employee');
        $this->forms->assignToUsers($form, [$this->fani->id, $second->id], null, $this->salama->id);

        $this->actingAs($this->fani)->post("/app/forms/{$form->id}/fill", ['fields' => [$radio->id => 'نعم']]);
        $this->actingAs($second)->post("/app/forms/{$form->id}/fill", ['fields' => [$radio->id => 'نعم']]);

        $summary = $this->forms->resultsSummary($form->refresh());
        $row = collect($summary)->firstWhere('field.id', $radio->id);

        $this->assertSame(2, $row['answered']);
        $this->assertSame(['نعم' => 2], $row['distribution']);
    }

    public function test_csv_export_includes_headers_and_rows(): void
    {
        $form = $this->simpleForm();
        $field = $this->forms->addField($form, ['label' => 'ملاحظاتك', 'field_type' => FormField::TYPE_TEXT]);
        $this->forms->assignToUsers($form, [$this->fani->id], null, $this->salama->id);
        $this->actingAs($this->fani)->post("/app/forms/{$form->id}/fill", ['fields' => [$field->id => 'كل شيء واضح']]);

        $response = $this->actingAs($this->salama)->get("/app/forms/{$form->id}/export");
        $response->assertOk();

        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();

        $this->assertStringContainsString('ملاحظاتك', $csv);
        $this->assertStringContainsString('كل شيء واضح', $csv);
    }

    public function test_answer_file_is_private_to_results_viewers_and_owner(): void
    {
        $form = $this->simpleForm();
        $photo = $this->forms->addField($form, ['label' => 'صورة', 'field_type' => FormField::TYPE_PHOTO]);
        $other = $this->user('emp3', 'employee');
        $this->forms->assignToUsers($form, [$this->fani->id, $other->id], null, $this->salama->id);

        $this->actingAs($this->fani)->post("/app/forms/{$form->id}/fill", [
            'photos' => [$photo->id => UploadedFile::fake()->image('x.jpg')],
        ]);
        $answer = FormSubmission::firstOrFail()->answers()->firstOrFail();

        $this->actingAs($this->fani)->get(route('forms.answers.file', [$form, $answer]))->assertOk();
        $this->actingAs($this->salama)->get(route('forms.answers.file', [$form, $answer]))->assertOk();
        $this->actingAs($other)->get(route('forms.answers.file', [$form, $answer]))->assertForbidden();
    }

    private function simpleForm(array $attrs = []): FormTemplate
    {
        return $this->forms->createForm(array_merge([
            'title' => 'نموذج اختبار', 'form_type' => FormTemplate::TYPE_CUSTOM,
        ], $attrs), $this->salama->id);
    }
}
