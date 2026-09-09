<?php

namespace Database\Seeders;

use App\Modules\Permit\Models\PermitType;
use App\Modules\Permit\Models\RiskRequiredPermitType;
use App\Modules\Risk\Models\RiskCategory;
use Illuminate\Database\Seeder;

/**
 * ربط فئات المخاطر التسع في كتاب المعهد بأنواع التصاريح التي تستلزمها.
 * يجيب سؤال المعالج: «هذه المخاطر مختارة — أي تصريح يلزم؟» بلا حفظ الكتالوج.
 *
 * منقول من OHSMS بأسماء فئات كتابنا نفسها (البذرة في RiskBookSeeder).
 * الشرط `severity_ge_N` يُقاس بشدة الخطر (١–٥).
 */
class RiskRequiredPermitTypesSeeder extends Seeder
{
    public function run(): void
    {
        $types = PermitType::pluck('id', 'code');
        $categories = RiskCategory::pluck('id', 'name');

        $mappings = [
            'المخاطر الميكانيكية' => [
                ['loto', RiskRequiredPermitType::COND_SEVERITY_GE_3],
                ['work_permit', RiskRequiredPermitType::COND_ALWAYS],
                ['crane_operations', RiskRequiredPermitType::COND_SEVERITY_GE_4],
            ],
            'المخاطر الكهربائية' => [
                ['electrical_work', RiskRequiredPermitType::COND_ALWAYS],
                ['loto', RiskRequiredPermitType::COND_SEVERITY_GE_3],
            ],
            'مخاطر الحريق' => [
                ['hot_work', RiskRequiredPermitType::COND_ALWAYS],
            ],
            'المخاطر الكيميائية' => [
                ['hazmat_handling', RiskRequiredPermitType::COND_ALWAYS],
                ['hazmat_transport', RiskRequiredPermitType::COND_SEVERITY_GE_3],
            ],
            'المخاطر الفيزيائية' => [
                ['confined_space', RiskRequiredPermitType::COND_SEVERITY_GE_4],
                ['work_at_height', RiskRequiredPermitType::COND_SEVERITY_GE_3],
            ],
            'المخاطر البيولوجية' => [
                ['work_permit', RiskRequiredPermitType::COND_ALWAYS],
            ],
            'المخاطر البشرية' => [
                ['work_permit', RiskRequiredPermitType::COND_ALWAYS],
            ],
            // الإدارية والتنظيمية والنفسية والاجتماعية: لا تصريح آلي — معالجتها إجرائية.
        ];

        foreach ($mappings as $categoryName => $rules) {
            $categoryId = $categories[$categoryName] ?? null;
            if (!$categoryId) {
                continue;
            }
            foreach ($rules as [$typeCode, $condition]) {
                if (!isset($types[$typeCode])) {
                    continue;
                }
                RiskRequiredPermitType::updateOrCreate(
                    ['risk_category_id' => $categoryId, 'permit_type_id' => $types[$typeCode], 'risk_id' => null],
                    ['is_mandatory' => true, 'triggering_condition' => $condition],
                );
            }
        }
    }
}
