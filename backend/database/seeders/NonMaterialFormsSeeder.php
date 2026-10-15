<?php

namespace Database\Seeders;

use App\Modules\Form\Models\FormField;
use App\Modules\Form\Models\FormTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * المرحلة ٢٩ — الخطوة ٢٩-٣ (قرار ٨٧، وثيقة التصميم §٤-٢): القوالب الثمانية للطبقة الثانية من تعريفات الواجهة حرفاً.
 *
 * المصدر `database/data/non-material-forms.json` مستخرَج آلياً من `non-material-ohsms-DEMO.html` (٣١ أغسطس ٢٠٢٦؛ الكائنان `MSTAGE` و`FORMS`)
 * بلا إعادة كتابة. الترجمة إلى حقول الوحدة:
 *   fields  ← قسم بعنوانه، ثم حقل لكل سطر: نص / نص طويل / تاريخ / قائمة.
 *   table   ← حقل جدول بأعمدته (نص / اختيار / ثابت) وصفوفه الثابتة أو عدد صفوفه الابتدائي وزر الإضافة وعلم «فتح بلاغ».
 *   mech    ← قسم بشرح خطَّي الدفاع، ثم جدول لكل آلية بصفوف ثابتة هي المراحل الأربع (الحالة اختيار + آلية الضمان نص).
 *   checks  ← قسم، ثم اختيار واحد لكل بند (نعم / جزئياً / لا).
 *   note    ← قسم «تنبيه» نصه في الشرح.
 *   sign    ← لا يُنقل: بكلمته التوقيع اسم ووقت، واعتمادات الجولة بالاسم والوقت (٢٩-٤).
 * ما وُسم `ack` في الواجهة يُوسم `feeds_ack` هنا ليُولَّد منه الإقرار السري (٢٩-٥).
 * الثمانية كلها تعبئة إدارة (`fills_as = unit`) بكلمته، و٠٢ عن كل موظف (الجولة، ٢٩-٤). لا حقل إلزامي: «لا يُطلب إنتاج وثائق مثالية».
 *
 * يُبذر مرة واحدة بالرمز: قالب موجود برمزه لا يُمسّ ولو عُدّل من الشاشة أو حُذف حقل منه.
 */
class NonMaterialFormsSeeder extends Seeder
{
    public const DATA = __DIR__.'/../data/non-material-forms.json';

    /** ما يكشفه كل نموذج — من وثيقة التصميم §١-١ */
    private const DESCRIPTIONS = [
        '٠١' => 'ماذا تعمل الإدارة وكيف ولماذا، ومخاطرها وفرصها، وهل آليات الهدف الأول السبع موثّقة ومعلنة ومفهومة ومطبَّقة، وبيئتها الداخلية بأبعادها الأربعة.',
        '٠٢' => 'تعبّئها الإدارة عن كل موظف: فهم دوره، وسياساته، ومخاطره وفرصه، وقنوات إبلاغه واقتراحه وتعلّمه.',
        '٠٣' => 'مخاطر الإدارة وفرصها بالبُعد والأولوية والإجراء والمسؤول والحالة.',
        '٠٤' => 'لكل سياسة أو إجراء: ما هو مكتوب وما هو مطبَّق، والفجوة وسببها والإجراء المقترح.',
        '٠٥' => 'بلاغات الإدارة واقتراحاتها بالبُعد والإجراء والقرار والحالة — تكشف هل القناة موجودة وتعمل.',
        '٠٦' => 'تكشف هل نظام المبادرات موجود ومطبَّق ومفهوم: تصنيف المبادرة بالبُعد، ووصفها، وأثرها ومواردها وزمنها.',
        '٠٧' => 'ربط أهداف الإدارة بأهداف المعهد ورؤية ٢٠٣٠، ومصفوفة الأبعاد الأربعة في الموظف والإدارة والمعهد والمستفيد، والفجوات.',
        '٠٨' => 'مؤشرات الأبعاد غير المادية بالمستهدف والفعلي والحالة، واتجاهها ربعياً، والإجراءات المطلوبة.',
    ];

    private const STATUS_OPTIONS = ['منفَّذ', 'منفَّذ جزئياً', 'غير منفَّذ'];
    private const CHECK_OPTIONS  = ['نعم', 'جزئياً', 'لا'];

    public function run(): void
    {
        $data = json_decode((string) file_get_contents(self::DATA), true);
        $stages = $data['MSTAGE'];

        foreach ($data['FORMS'] as $form) {
            if (FormTemplate::where('package_code', $form['code'])->exists()) {
                continue; // بُذر من قبل — لا يُكتب فوق ما عُدّل من الشاشة
            }
            DB::transaction(function () use ($form, $stages) {
                $template = FormTemplate::create([
                    'title'        => $form['name'],
                    'description'  => self::DESCRIPTIONS[$form['code']] ?? null,
                    'form_type'    => FormTemplate::TYPE_CUSTOM,
                    'package_code' => $form['code'],
                    'fills_as'     => FormTemplate::FILLS_UNIT,
                    'is_active'    => true,
                ]);
                $order = 0;
                foreach ($form['sec'] as $sec) {
                    foreach (self::fieldsFor($sec, $stages) as $field) {
                        $field['form_id'] = $template->id;
                        $field['order'] = ++$order;
                        FormField::create($field);
                    }
                }
            });
        }
    }

    /** حقول قسم واحد من الواجهة بترتيبها. @return array<int, array<string, mixed>> */
    public static function fieldsFor(array $sec, array $stages): array
    {
        $ack = (bool) ($sec['ack'] ?? false);
        $out = [];

        switch ($sec['t']) {
            case 'fields':
                $out[] = ['label' => $sec['title'], 'field_type' => FormField::TYPE_SECTION];
                foreach ($sec['rows'] as $row) {
                    [$label, $kind] = $row;
                    $type = match ($kind) {
                        'area' => FormField::TYPE_TEXTAREA,
                        'date' => FormField::TYPE_DATE,
                        'sel'  => FormField::TYPE_SELECT,
                        default => FormField::TYPE_TEXT,
                    };
                    $out[] = ['label' => $label, 'field_type' => $type, 'feeds_ack' => $ack,
                        'options' => $kind === 'sel' ? array_values($row[2] ?? []) : null];
                }
                break;

            case 'table':
                $out[] = ['label' => $sec['title'], 'field_type' => FormField::TYPE_TABLE, 'feeds_ack' => $ack,
                    'options' => self::tableDef($sec['cols'], $sec['fixed'] ?? [], (bool) ($sec['add'] ?? false), (int) ($sec['n'] ?? 2), (bool) ($sec['esc'] ?? false))];
                break;

            case 'mech':
                $out[] = ['label' => $sec['title'], 'field_type' => FormField::TYPE_SECTION,
                    'help_text' => 'خط الدفاع الأول — ضمان الإدارة. لكل آلية أربع مراحل: موثّق ثم معلن ثم مفهوم ثم مطبَّق. والإدارة تُقرّ بحالة كل مرحلة وبآلية ضمانها — لا بمجرد وقوعها. ويقابله خط الدفاع الثاني: إقرار الموظف السري بالمراحل نفسها.'];
                $hints = implode(' · ', array_map(fn ($s) => $s['n'].': '.$s['p'], $stages));
                foreach (array_values($sec['items']) as $i => $item) {
                    $out[] = ['label' => self::arabicNumber($i + 1).' — '.$item, 'field_type' => FormField::TYPE_TABLE, 'feeds_ack' => $ack,
                        'help_text' => $hints,
                        'options' => [
                            'columns' => [
                                ['label' => 'المرحلة', 'type' => 'fixed'],
                                ['label' => 'الحالة', 'type' => 'select', 'options' => self::STATUS_OPTIONS],
                                ['label' => 'آلية الضمان — ما الذي يضمنها؟', 'type' => 'text'],
                            ],
                            'rows'    => array_column($stages, 'n'),
                            'can_add' => false,
                        ]];
                }
                break;

            case 'checks':
                $out[] = ['label' => $sec['title'], 'field_type' => FormField::TYPE_SECTION];
                foreach ($sec['items'] as $item) {
                    $out[] = ['label' => $item, 'field_type' => FormField::TYPE_RADIO, 'feeds_ack' => $ack, 'options' => self::CHECK_OPTIONS];
                }
                break;

            case 'note':
                $out[] = ['label' => 'تنبيه', 'field_type' => FormField::TYPE_SECTION, 'help_text' => $sec['text']];
                break;

            case 'sign':
            default:
                break; // التوقيع اسم ووقت — لا حقل
        }

        return $out;
    }

    /** تعريف جدول من أعمدة الواجهة `{l, t: fixed|sel|text, o}` */
    private static function tableDef(array $cols, array $fixed, bool $add, int $n, bool $esc): array
    {
        $columns = [];
        foreach ($cols as $c) {
            $type = match ($c['t'] ?? 'text') { 'fixed' => 'fixed', 'sel' => 'select', default => 'text' };
            $columns[] = ['label' => $c['l'], 'type' => $type, 'options' => array_values($c['o'] ?? [])];
        }
        $rows = array_values(array_filter(array_map('strval', $fixed), fn ($r) => $r !== ''));

        return ['columns' => $columns, 'rows' => $rows, 'can_add' => $rows === [] ? $add : false, 'min_rows' => $rows === [] ? max(1, $n) : count($rows), 'esc' => $esc];
    }

    private static function arabicNumber(int $n): string
    {
        return strtr((string) $n, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);
    }
}
