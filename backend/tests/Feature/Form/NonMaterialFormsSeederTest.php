<?php

namespace Tests\Feature\Form;

use App\Models\User;
use App\Modules\Form\Models\FormField;
use App\Modules\Form\Models\FormTemplate;
use App\Modules\Form\Services\FormService;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\NonMaterialFormsSeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٢٩ — الخطوة ٢٩-٣ (قرار ٨٧): بذرة القوالب الثمانية من تعريفات الواجهة.
 * البوابة: ٨ قوالب بعدد الأقسام والحقول نفسه في الواجهة، وإعادة البذر لا تكرر ولا تكتب فوق تعديل.
 * الأعداد أدناه عُدّت من `non-material-ohsms-DEMO.html` (الكائن `FORMS`) بقواعد الترجمة في رأس البذرة — لا من الكود.
 */
class NonMaterialFormsSeederTest extends TestCase
{
    use RefreshDatabase;

    /** [أقسام، حقول إجابة] لكل قالب — من الواجهة: fields = قسم + سطر لكل حقل؛ table = حقل؛ mech = قسم + جدول لكل آلية؛ checks = قسم + بند لكل سطر؛ note = قسم؛ sign = لا شيء */
    private const EXPECTED = [
        '٠١' => [6, 25],  // ٤ fields (٥+٢+٣+٣) + جدول + mech (٧) + fields (٤)
        '٠٢' => [6, 19],  // ٤ fields (٥+٣+١+٢) + checks (٢+٦)
        '٠٣' => [2, 6],   // fields (٤) + جدولان + تنبيه
        '٠٤' => [2, 5],   // fields (٤) + تنبيه + جدول
        '٠٥' => [1, 6],   // fields (٤) + جدولان
        '٠٦' => [3, 13],  // ٣ fields (٥+٢+٣) + ٣ جداول ثابتة
        '٠٧' => [3, 8],   // fields (٤) + تنبيه + جدولان + fields (٢)
        '٠٨' => [1, 7],   // fields (٣) + ٤ جداول
    ];

    public function test_seeds_the_eight_templates_with_the_interface_sections_and_fields(): void
    {
        $this->seed(NonMaterialFormsSeeder::class);

        $templates = FormTemplate::whereNotNull('package_code')->orderBy('package_code')->get()->keyBy('package_code');
        $this->assertSame(array_keys(self::EXPECTED), $templates->keys()->all(), 'ثمانية قوالب برموزها ٠١…٠٨');
        $this->assertTrue($templates->every(fn (FormTemplate $t) => $t->fills_as === FormTemplate::FILLS_UNIT && $t->is_active), 'الثمانية كلها تعبئة إدارة ومفعّلة');

        foreach (self::EXPECTED as $code => [$sections, $answers]) {
            $fields = $templates[$code]->fields;
            $this->assertSame($sections, $fields->where('field_type', FormField::TYPE_SECTION)->count(), "أقسام $code");
            $this->assertSame($answers, $fields->filter->hasAnswer()->count(), "حقول $code");
            $this->assertSame(range(1, $fields->count()), $fields->pluck('order')->all(), "ترتيب $code متصل");
            $this->assertTrue($fields->every(fn (FormField $f) => !$f->is_required), "لا حقل إلزامي في $code");
        }
        $this->assertSame(113, FormField::whereIn('form_id', $templates->pluck('id'))->count());

        // ٠١: سبعة جداول آليات بصفوف المراحل الأربع وحالة بثلاثة خيارات، كلها تدخل في الإقرار مع البيئة الداخلية (٤)
        $f01 = $templates['٠١']->fields;
        $mech = $f01->filter(fn (FormField $f) => $f->isTable() && $f->tableDef()['rows'] === ['موثّق', 'معلن', 'مفهوم', 'مطبَّق']);
        $this->assertCount(7, $mech);
        $this->assertStringStartsWith('١ — توثيق خطة تربط دور الفرد', $mech->first()->label);
        $this->assertSame(['منفَّذ', 'منفَّذ جزئياً', 'غير منفَّذ'], $mech->first()->tableDef()['columns'][1]['options']);
        $this->assertFalse($mech->first()->tableDef()['can_add']);
        $this->assertSame(11, $f01->where('feeds_ack', true)->count(), '٧ آليات + ٤ بيئة داخلية');
        $risks = $f01->first(fn (FormField $f) => $f->label === 'المخاطر والفرص على مستوى الإدارة');
        $this->assertSame(['النوع', 'الوصف', 'السبب المحتمل', 'الأثر المتوقع', 'الإجراء المقترح'], array_column($risks->tableDef()['columns'], 'label'));
        $this->assertSame(['خطر', 'فرصة'], $risks->tableDef()['columns'][0]['options']);
        $this->assertTrue($risks->tableDef()['can_add']);
        $this->assertSame(4, $risks->tableDef()['min_rows']);

        // ٠٢: ثمانية بنود اختيار (نعم/جزئياً/لا) وستة حقول تدخل الإقرار؛ ٠٣: زر «فتح بلاغ» محفوظ على سجل المخاطر
        $f02 = $templates['٠٢']->fields;
        $this->assertSame(8, $f02->where('field_type', FormField::TYPE_RADIO)->count());
        $this->assertSame(['نعم', 'جزئياً', 'لا'], $f02->firstWhere('field_type', FormField::TYPE_RADIO)->choiceOptions());
        $this->assertSame(6, $f02->where('feeds_ack', true)->count());
        $this->assertSame(1, $templates['٠٤']->fields->where('feeds_ack', true)->count());
        $this->assertSame(18, FormField::where('feeds_ack', true)->count());
        $f03 = $templates['٠٣']->fields->first(fn (FormField $f) => $f->label === 'سجل المخاطر');
        $this->assertTrue($f03->tableDef()['esc']);
        $this->assertSame(['معرفي', 'نفسي', 'فكري', 'اجتماعي', 'تنظيمي'], $f03->tableDef()['columns'][0]['options']);

        // ٠٦: ثلاثة جداول بصفوف ثابتة من الواجهة
        $f06 = $templates['٠٦']->fields->first(fn (FormField $f) => $f->label === 'الأثر المتوقع');
        $this->assertSame(['على الموظفين', 'على بيئة العمل', 'على الإنتاجية', 'على رؤية المعهد'], $f06->tableDef()['rows']);
        // لا توقيع مرسوم في الحزمة
        $this->assertSame(0, FormField::whereIn('form_id', $templates->pluck('id'))->where('field_type', FormField::TYPE_SIGNATURE)->count());
    }

    public function test_reseeding_neither_duplicates_nor_overwrites_screen_edits(): void
    {
        $this->seed(NonMaterialFormsSeeder::class);
        $before = FormField::count();
        $t01 = FormTemplate::where('package_code', '٠١')->firstOrFail();
        $t01->update(['title' => 'بطاقة الإدارة — معدَّلة']);
        $field = $t01->fields()->where('field_type', FormField::TYPE_TEXT)->firstOrFail();
        $field->update(['label' => 'اسم الإدارة (معدَّل)']);
        $t01->fields()->where('label', 'عدد الموظفين')->delete();

        $this->seed(NonMaterialFormsSeeder::class);

        $this->assertSame(8, FormTemplate::whereNotNull('package_code')->count(), 'إعادة البذر كرّرت القوالب');
        $this->assertSame($before - 1, FormField::count(), 'إعادة البذر أعادت حقلاً محذوفاً أو كرّرت');
        $this->assertSame('بطاقة الإدارة — معدَّلة', $t01->fresh()->title);
        $this->assertSame('اسم الإدارة (معدَّل)', $field->fresh()->label);
    }

    /** القوالب تظهر في القائمة وتُفتح للتعبئة بحقولها الجديدة (دخان) */
    public function test_templates_are_listed_and_fillable(): void
    {
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, NonMaterialFormsSeeder::class]);
        $salama = User::create(['username' => 'salama', 'name' => 'مسؤول السلامة', 'password' => '1234']);
        UserProfile::create(['user_id' => $salama->id, 'role' => 'system_admin', 'is_active' => true]);
        $emp = User::create(['username' => 'emp', 'name' => 'موظف', 'password' => '1234']);
        UserProfile::create(['user_id' => $emp->id, 'role' => 'employee', 'is_active' => true]);

        $this->actingAs($salama);
        $list = $this->get(route('forms.index'));
        $list->assertOk();
        foreach (['بطاقة الإدارة', 'بطاقة الدور الوظيفي', 'سجل المخاطر والفرص', 'سجل السياسات والممارسات', 'سجل الإبلاغ والاقتراح', 'بطاقة المبادرة والابتكار', 'مصفوفة الربط المؤسسي', 'لوحة المؤشرات'] as $title) {
            $list->assertSee($title);
        }

        $t01 = FormTemplate::where('package_code', '٠١')->firstOrFail();
        app(FormService::class)->assignToUsers($t01, [$emp->id], null, $salama->id);
        $this->actingAs($emp);
        $this->get(route('forms.fill', $t01))->assertOk()
            ->assertSee('إقرار تنفيذ آليات الهدف الأول')->assertSee('١ — توثيق خطة تربط دور الفرد')
            ->assertSee('وصف البيئة الداخلية للإدارة')->assertSee('البيئة المعرفية');
    }
}
