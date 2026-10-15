<?php

namespace App\Modules\Form\Support;

use App\Modules\Risk\Models\Risk;

/**
 * قرار ٨٨: البند ٤ «المخاطر والفرص» مرحلته «موثّق» يقرؤها النظام من سجل الإدارة الخاص بلا سؤال —
 * وقائع النظام بجانب الرقم: هل للوحدة أخطار مفعّلة سارية؟ (الفعلي، غير المغلق وغير المرفوض).
 */
final class RegisterFacts
{
    public static function activeRiskCount(?int $unitId): int
    {
        if (!$unitId) return 0;

        return Risk::withoutGlobalScopes()
            ->where('risk_type', 'active')
            ->where('organization_unit_id', $unitId)
            ->inEffect()
            ->count();
    }

    /** @return array{claim: string, evidence: string} */
    public static function documentedRisksClaim(?int $unitId): array
    {
        $n = self::activeRiskCount($unitId);

        return $n > 0
            ? ['claim' => 'yes', 'evidence' => "سجل الإدارة في النظام: {$n} خطراً مفعّلاً"]
            : ['claim' => 'no',  'evidence' => 'لا أخطار مفعّلة في سجل الإدارة'];
    }
}
