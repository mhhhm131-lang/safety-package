<?php

namespace Database\Seeders;

use App\Modules\Permit\Models\PermitType;
use App\Modules\Permit\Models\QualificationChecklistItem;
use Illuminate\Database\Seeder;

/**
 * بنود تأهيل الأنواع الثلاثة (قبل التعاقد، قبل بدء العمل، الوحدة الداخلية) — منقولة من OHSMS.
 * غير تكرارية: المفتاح (النوع + نص البند).
 */
class QualificationChecklistSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->catalog() as $typeCode => $items) {
            $type = PermitType::where('code', $typeCode)->first();
            if (!$type) {
                continue;
            }
            foreach ($items as $sort => $item) {
                QualificationChecklistItem::updateOrCreate(
                    ['permit_type_id' => $type->id, 'description_ar' => $item['description_ar']],
                    $item + ['permit_type_id' => $type->id, 'sort_order' => $sort],
                );
            }
        }
    }

    private function catalog(): array
    {
        return [
            'contractor_pre_qualification' => [
                ['phase' => 'preventive', 'evidence_type' => 'document', 'is_mandatory' => true,
                    'description_ar' => 'التحقق من سريان السجل التجاري ومطابقة نشاطه للعمل المطلوب'],
                ['phase' => 'preventive', 'evidence_type' => 'document', 'is_mandatory' => true,
                    'standard_reference' => 'ISO 45001:2018 §8.1.4',
                    'description_ar' => 'التحقق من تأمين العمال ضد إصابات العمل (ساري وبتغطية كافية)'],
                ['phase' => 'preventive', 'evidence_type' => 'document', 'is_mandatory' => true,
                    'standard_reference' => 'ISO 45001:2018 §9.1',
                    'description_ar' => 'مراجعة سجل الحوادث والإصابات للسنوات الثلاث الماضية'],
                ['phase' => 'preventive', 'evidence_type' => 'document', 'is_mandatory' => true,
                    'standard_reference' => 'ISO 45001:2018 §5.2',
                    'description_ar' => 'الاطلاع على سياسة السلامة والصحة والبيئة لدى المقاول'],
                ['phase' => 'preventive', 'evidence_type' => 'check', 'is_mandatory' => true,
                    'description_ar' => 'التحقق من أن تصنيف المقاول يغطي نوع العمل المطلوب'],
                ['phase' => 'preventive', 'evidence_type' => 'document', 'is_mandatory' => false,
                    'description_ar' => 'التحقق من التسجيل في التأمينات الاجتماعية'],
                ['phase' => 'operational', 'evidence_type' => 'signature', 'is_mandatory' => true,
                    'description_ar' => 'توقيع اتفاقية السلامة والسلوك في المعهد قبل الدخول'],
                ['phase' => 'operational', 'evidence_type' => 'check', 'is_mandatory' => true,
                    'description_ar' => 'تسمية ممثل سلامة من المقاول للتواصل مع مسؤول السلامة'],
                ['phase' => 'response', 'evidence_type' => 'document', 'is_mandatory' => true,
                    'description_ar' => 'تسليم خطة الطوارئ الخاصة بالمقاول واعتمادها'],
            ],

            'contractor_post_qualification' => [
                ['phase' => 'preventive', 'evidence_type' => 'document', 'is_mandatory' => true,
                    'description_ar' => 'تسجيل قائمة العمال كاملة بالهويات الوطنية أو الإقامات'],
                ['phase' => 'preventive', 'evidence_type' => 'document', 'is_mandatory' => true,
                    'standard_reference' => 'ISO 45001:2018 §7.2',
                    'description_ar' => 'التحقق من شهادات الكفاءة المهنية لكل عامل بحسب مهنته'],
                ['phase' => 'preventive', 'evidence_type' => 'document', 'is_mandatory' => true,
                    'description_ar' => 'التحقق من شهادات السلامة الإلزامية (ارتفاع، حريق، إسعافات) لكل عامل'],
                ['phase' => 'preventive', 'evidence_type' => 'signature', 'is_mandatory' => true,
                    'description_ar' => 'إتمام التعريف بالسلامة في المعهد لجميع العمال قبل أول دخول'],
                ['phase' => 'preventive', 'evidence_type' => 'document', 'is_mandatory' => false,
                    'description_ar' => 'التحقق من الفحص الطبي حيث يستلزمه نوع العمل'],
                ['phase' => 'operational', 'evidence_type' => 'check', 'is_mandatory' => true,
                    'standard_reference' => 'SBC 801',
                    'description_ar' => 'فحص المعدات والأدوات عند دخولها والتحقق من شهادات فحصها'],
                ['phase' => 'operational', 'evidence_type' => 'check', 'is_mandatory' => true,
                    'description_ar' => 'تحديد مكان عمل المقاول وساعات التشغيل والمسالك المسموحة'],
                ['phase' => 'operational', 'evidence_type' => 'check', 'is_mandatory' => true,
                    'description_ar' => 'التحقق من توفر معدات الوقاية الشخصية الكاملة وصلاحيتها لكل عامل'],
                ['phase' => 'response', 'evidence_type' => 'check', 'is_mandatory' => true,
                    'description_ar' => 'التحقق من معرفة العمال بمسارات الإخلاء ونقاط التجمع في المعهد'],
                ['phase' => 'response', 'evidence_type' => 'check', 'is_mandatory' => true,
                    'description_ar' => 'التحقق من وجود مسعف معتمد ضمن طاقم المقاول'],
            ],

            'internal_unit_qualification' => [
                ['phase' => 'preventive', 'evidence_type' => 'document', 'is_mandatory' => true,
                    'description_ar' => 'مراجعة أسماء الفريق وشهاداتهم المهنية'],
                ['phase' => 'preventive', 'evidence_type' => 'document', 'is_mandatory' => true,
                    'description_ar' => 'التحقق من إتمام التدريبات الإلزامية للوحدة في السنة الحالية'],
                ['phase' => 'operational', 'evidence_type' => 'check', 'is_mandatory' => true,
                    'description_ar' => 'تسمية مشرف سلامة معتمد للوحدة'],
                ['phase' => 'operational', 'evidence_type' => 'signature', 'is_mandatory' => true,
                    'description_ar' => 'مراجعة إجراءات التشغيل الموحدة للأنشطة عالية الخطورة واعتمادها'],
                ['phase' => 'response', 'evidence_type' => 'document', 'is_mandatory' => true,
                    'description_ar' => 'التحقق من أن خطة استجابة المكان محدَّثة ومعروفة للوحدة'],
            ],
        ];
    }
}
