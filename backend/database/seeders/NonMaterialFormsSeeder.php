<?php

namespace Database\Seeders;

use App\Modules\Form\Models\FormField;
use App\Modules\Form\Models\FormTemplate;
use App\Modules\Form\Support\NonMaterialPackage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * قرار ٨٨ (٢٠٢٦-١٠-١٥): حزمة الطبقة الثانية = قالب واحد «بطاقة الإدارة» (`package_code = card`، تعبئة إدارة).
 *   سبعة بنود (`NonMaterialPackage::ITEMS`) كلٌّ قسمٌ بعنوانه ونتيجته في الشرح، ثم أربع مراحل (`STAGES`) كلٌّ حقل «ادعاء بدليل»:
 *   نعم مع «أرني» (سطر، أو وثيقة في «موثّق») أو لا. و«موثّق» في بند المخاطر يقرؤه النظام من سجل الإدارة (`from_register`).
 *   كل حقول الادعاء تغذّي إقرار الموظف (`feeds_ack`) الذي يُولَّد في ٢٩-٥ من البنود التي قالت فيها الإدارة نعم.
 *
 * القوالب الثمانية (٠١…٠٨ من ٢٩-٣ الأولى) تُحذف بحقولها وتكليفاتها وتعبئاتها — بكلمته، ولم تُعبَّأ تعبئة حقيقية.
 * يُبذر مرة واحدة بالرمز: قالب موجود برمزه لا يُمسّ ولو عُدّل من الشاشة.
 */
class NonMaterialFormsSeeder extends Seeder
{
    public const EIGHT = ['٠١', '٠٢', '٠٣', '٠٤', '٠٥', '٠٦', '٠٧', '٠٨'];

    public function run(): void
    {
        foreach (FormTemplate::whereIn('package_code', self::EIGHT)->get() as $old) {
            $old->delete(); // الحقول والتكليفات والتعبئات تتبعه (FK cascade)
        }

        if (FormTemplate::where('package_code', NonMaterialPackage::CODE)->exists()) {
            return;
        }

        DB::transaction(function () {
            $template = FormTemplate::create([
                'title'        => NonMaterialPackage::TITLE,
                'description'  => NonMaterialPackage::DESCRIPTION,
                'form_type'    => FormTemplate::TYPE_CUSTOM,
                'package_code' => NonMaterialPackage::CODE,
                'fills_as'     => FormTemplate::FILLS_UNIT,
                'is_active'    => true,
            ]);
            $order = 0;
            foreach (self::fields() as $field) {
                $field['form_id'] = $template->id;
                $field['order'] = ++$order;
                FormField::create($field);
            }
        });
    }

    /** حقول القالب بترتيبها: قسم لكل بند ثم ادعاء لكل مرحلة. @return array<int, array<string, mixed>> */
    public static function fields(): array
    {
        $out = [];
        foreach (NonMaterialPackage::ITEMS as $i => $item) {
            $out[] = ['label' => $item['title'], 'field_type' => FormField::TYPE_SECTION, 'help_text' => 'لماذا؟ '.$item['why']];
            foreach (NonMaterialPackage::STAGES as $stage) {
                $fromRegister = $item['key'] === NonMaterialPackage::FROM_REGISTER['item'] && $stage['key'] === NonMaterialPackage::FROM_REGISTER['stage'];
                $out[] = [
                    'label'       => $stage['name'],
                    'help_text'   => $stage['question'],
                    'field_type'  => FormField::TYPE_CLAIM,
                    'is_required' => !$fromRegister,
                    'feeds_ack'   => true,
                    'options'     => ['item' => $item['key'], 'stage' => $stage['key'], 'hint' => $stage['hint'], 'attach' => $stage['attach'], 'from_register' => $fromRegister],
                ];
            }
        }

        return $out;
    }
}
