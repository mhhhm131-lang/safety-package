<?php

namespace Tests\Feature\Form;

use App\Models\User;
use App\Modules\Form\Models\FormAnswer;
use App\Modules\Form\Models\FormField;
use App\Modules\Form\Models\FormSubmission;
use App\Modules\Form\Models\FormTemplate;
use App\Modules\Form\Services\FormService;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٢٩ — الخطوة ٢٩-١ (قرار ٨٧، وثيقة التصميم §٣ و§٤-١): أنواع الحقول الثلاثة التي تحتاجها النماذج الثمانية ولا توجد في الوحدة:
 *   `section`  عنوان قسم بلا إجابة.
 *   `table`    جدول بأعمدة (نص / اختيار / ثابت) وصفوف تُضاف، أو صفوف ثابتة (الآليات × المراحل)؛ الإجابة صفوف JSON.
 *   `ack_item` بند إقرار ثلاثي: صحيح؟ أفهمه؟ مطبَّق؟ وخانة «ما الواقع» إلزامية عند أي «لا»؛ الإجابة JSON.
 * البوابة: قالب بحقل جدول يُعبَّأ بصفين ويُقرأ كما أُدخل؛ وبند إقرار يرفض «لا» بلا ملاحظة.
 */
class FormFieldTypesTest extends TestCase
{
    use RefreshDatabase;

    private User $salama;
    private User $emp;
    private FormTemplate $form;
    private FormService $forms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class]);
        $this->salama = $this->user('salama', 'system_admin');
        $this->emp    = $this->user('emp', 'employee');
        $this->forms  = app(FormService::class);
        $this->form   = FormTemplate::create(['title' => 'بطاقة الإدارة', 'form_type' => FormTemplate::TYPE_SURVEY, 'created_by_id' => $this->salama->id]);
    }

    private function user(string $username, string $role): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234']);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true]);
        return $u;
    }

    private const FREE_TABLE  = "الخطر | نص\nالبُعد | اختيار | معرفي، نفسي، فكري";
    private const FIXED_TABLE = "الآلية | ثابت\nموثّق | اختيار | نعم، جزئياً، لا\nالدليل | نص\n+ التخطيط\n+ السياسات";

    /** @return array{section: FormField, free: FormField, fixed: FormField, ack: FormField} */
    private function buildFields(): array
    {
        return [
            'section' => $this->forms->addField($this->form, ['label' => 'أولاً: المخاطر والفرص', 'field_type' => FormField::TYPE_SECTION, 'is_required' => true]),
            'free'    => $this->forms->addField($this->form, ['label' => 'جدول المخاطر', 'field_type' => FormField::TYPE_TABLE, 'options' => self::FREE_TABLE, 'is_required' => true]),
            'fixed'   => $this->forms->addField($this->form, ['label' => 'آليات الهدف الأول', 'field_type' => FormField::TYPE_TABLE, 'options' => self::FIXED_TABLE, 'is_required' => true]),
            'ack'     => $this->forms->addField($this->form, ['label' => 'الإدارة وثّقت خطتها', 'field_type' => FormField::TYPE_ACK_ITEM, 'is_required' => true]),
        ];
    }

    /** البناء من الشاشة: الأنواع الثلاثة تُقبل، وتعريف الجدول يُقرأ من سطور الشاشة إلى أعمدة وصفوف */
    public function test_builder_accepts_the_three_types_and_parses_the_table_definition(): void
    {
        $this->actingAs($this->salama);
        $this->post(route('forms.fields.store', $this->form), ['label' => 'أولاً', 'field_type' => 'section', 'is_required' => '1'])->assertSessionHasNoErrors();
        $this->post(route('forms.fields.store', $this->form), ['label' => 'جدول المخاطر', 'field_type' => 'table', 'options' => self::FREE_TABLE, 'is_required' => '1'])->assertSessionHasNoErrors();
        $this->post(route('forms.fields.store', $this->form), ['label' => 'الآليات', 'field_type' => 'table', 'options' => self::FIXED_TABLE])->assertSessionHasNoErrors();
        $this->post(route('forms.fields.store', $this->form), ['label' => 'بند', 'field_type' => 'ack_item', 'is_required' => '1'])->assertSessionHasNoErrors();
        $this->post(route('forms.fields.store', $this->form), ['label' => 'جدول بلا أعمدة', 'field_type' => 'table', 'options' => ''])->assertSessionHasErrors('options');

        $fields = $this->form->fields()->get()->keyBy('label');
        $this->assertFalse($fields['أولاً']->is_required, 'القسم بلا إجابة فلا يكون إلزامياً');
        $this->assertNull($fields['أولاً']->options);

        $free = $fields['جدول المخاطر']->tableDef();
        $this->assertSame(['الخطر', 'البُعد'], array_column($free['columns'], 'label'));
        $this->assertSame(['text', 'select'], array_column($free['columns'], 'type'));
        $this->assertSame(['معرفي', 'نفسي', 'فكري'], $free['columns'][1]['options']);
        $this->assertSame([], $free['rows']);
        $this->assertTrue($free['can_add']);

        $fixed = $fields['الآليات']->tableDef();
        $this->assertSame(['fixed', 'select', 'text'], array_column($fixed['columns'], 'type'));
        $this->assertSame(['التخطيط', 'السياسات'], $fixed['rows']);
        $this->assertFalse($fixed['can_add']);

        // صفحة النموذج تعرض تعريف الجدول مقروءاً لا JSON
        $this->get(route('forms.show', $this->form))->assertOk()->assertSee('الآلية')->assertSee('التخطيط')->assertDontSee('"columns"');
    }

    /** البوابة: جدول يُعبَّأ بصفين ويُقرأ كما أُدخل، وبند الإقرار يرفض «لا» بلا ملاحظة ثم يقبلها بها */
    public function test_fill_round_trips_table_rows_and_ack_item_and_requires_note_on_any_no(): void
    {
        $f = $this->buildFields();
        $this->actingAs($this->salama);
        $this->forms->assignToUsers($this->form, [$this->emp->id], null, $this->salama->id);

        $this->actingAs($this->emp);
        $page = $this->get(route('forms.fill', $this->form));
        $page->assertOk()->assertSee('أولاً: المخاطر والفرص')->assertSee('الخطر')->assertSee('البُعد')->assertSee('التخطيط')
            ->assertSee('صحيح؟')->assertSee('أفهمه؟')->assertSee('مطبَّق؟')->assertSee('ما الواقع؟');

        $payload = [
            'fields' => [
                $f['free']->id  => [['مقبس يشرر في الممر', 'نفسي'], ['غياب وصف الدور', 'معرفي']],
                $f['fixed']->id => [['التخطيط', 'نعم', 'خطة ٢٠٢٦ معتمدة'], ['السياسات', 'جزئياً', '']],
                $f['ack']->id   => ['ok' => 'نعم', 'und' => 'لا', 'app' => 'نعم', 'note' => ''],
            ],
        ];
        $this->post(route('forms.submit', $this->form), $payload)->assertSessionHasErrors("fields.{$f['ack']->id}.note");
        $this->assertSame(0, FormSubmission::withoutGlobalScope('building')->count(), 'رُفضت ثم حُفظت');

        $payload['fields'][$f['ack']->id]['note'] = 'لا أعرف من يملك الخطة';
        $this->post(route('forms.submit', $this->form), $payload)->assertSessionHasNoErrors()->assertRedirect();

        $submission = FormSubmission::withoutGlobalScope('building')->firstOrFail();
        $by = $submission->answers->keyBy('field_id');
        $this->assertFalse($by->has($f['section']->id), 'القسم لا إجابة له');
        $this->assertSame([['مقبس يشرر في الممر', 'نفسي'], ['غياب وصف الدور', 'معرفي']], $by[$f['free']->id]->tableRows());
        $this->assertSame([['التخطيط', 'نعم', 'خطة ٢٠٢٦ معتمدة'], ['السياسات', 'جزئياً', '']], $by[$f['fixed']->id]->tableRows(), 'الخلية الثابتة من التعريف لا من المرسِل');
        $this->assertSame(['ok' => 'نعم', 'und' => 'لا', 'app' => 'نعم', 'note' => 'لا أعرف من يملك الخطة'], $by[$f['ack']->id]->ackValue());
        $this->assertStringContainsString('أفهمه: لا', $by[$f['ack']->id]->display());
        $this->assertStringContainsString('مقبس يشرر في الممر | نفسي', $by[$f['free']->id]->display());
    }

    /** جدول إلزامي بلا صف واحد مملوء يُرفض، والجدول الثابت الإلزامي يطلب اختياراً لكل صف */
    public function test_required_tables_reject_empty_rows_and_unanswered_fixed_rows(): void
    {
        $f = $this->buildFields();
        $this->actingAs($this->salama);
        $this->forms->assignToUsers($this->form, [$this->emp->id], null, $this->salama->id);
        $this->actingAs($this->emp);

        $r = $this->post(route('forms.submit', $this->form), ['fields' => [
            $f['free']->id  => [['', ''], ['', '']],
            $f['fixed']->id => [['التخطيط', '', ''], ['السياسات', 'نعم', '']],
            $f['ack']->id   => ['ok' => 'نعم', 'und' => 'نعم', 'app' => 'نعم', 'note' => ''],
        ]]);
        $r->assertSessionHasErrors(["fields.{$f['free']->id}", "fields.{$f['fixed']->id}"]);
        $r->assertSessionDoesntHaveErrors("fields.{$f['ack']->id}.note");

        // بند الإقرار الإلزامي بلا إجابة عن سؤال من الثلاثة يُرفض
        $this->post(route('forms.submit', $this->form), ['fields' => [
            $f['free']->id  => [['خطر', 'معرفي']],
            $f['fixed']->id => [['التخطيط', 'نعم', ''], ['السياسات', 'لا', '']],
            $f['ack']->id   => ['ok' => 'نعم', 'und' => '', 'app' => 'نعم', 'note' => ''],
        ]])->assertSessionHasErrors("fields.{$f['ack']->id}");
    }

    /** النتائج والتصدير يعرضان خلايا الجدول وإجابة البند وملاحظتها، والملخص يعدّ «لا» لكل سؤال */
    public function test_results_summary_and_export_show_table_cells_and_ack_answers(): void
    {
        $f = $this->buildFields();
        $this->actingAs($this->salama);
        $this->forms->assignToUsers($this->form, [$this->emp->id], null, $this->salama->id);
        $this->actingAs($this->emp);
        $this->forms->submit($this->form, $this->emp->id, [
            $f['free']->id  => [['مقبس يشرر في الممر', 'نفسي']],
            $f['fixed']->id => [['التخطيط', 'لا', 'لا خطة'], ['السياسات', 'نعم', 'سجل السياسات']],
            $f['ack']->id   => ['ok' => 'لا', 'und' => 'نعم', 'app' => 'لا', 'note' => 'الخطة غير معلنة'],
        ]);

        $this->actingAs($this->salama);
        $page = $this->get(route('forms.results', $this->form));
        $page->assertOk()->assertSee('مقبس يشرر في الممر')->assertSee('لا خطة')->assertSee('الخطة غير معلنة')->assertDontSee('"ok"');

        $summary = collect($this->forms->resultsSummary($this->form, $this->salama))->keyBy(fn ($r) => $r['field']->id);
        $this->assertFalse($summary->has($f['section']->id), 'القسم خارج الملخص');
        $this->assertSame(1, $summary[$f['ack']->id]['distribution']['صحيح — لا']);
        $this->assertSame(1, $summary[$f['ack']->id]['distribution']['أفهمه — نعم']);
        $this->assertSame(1, $summary[$f['ack']->id]['distribution']['مطبَّق — لا']);
        $this->assertSame(1, $summary[$f['free']->id]['answered']);

        $csv = $this->get(route('forms.export', $this->form))->assertOk()->streamedContent();
        $this->assertStringContainsString('مقبس يشرر في الممر | نفسي', $csv);
        $this->assertStringContainsString('الخطة غير معلنة', $csv);
        $this->assertStringNotContainsString('أولاً: المخاطر والفرص', $csv, 'القسم لا عمود له في التصدير');
    }
}
