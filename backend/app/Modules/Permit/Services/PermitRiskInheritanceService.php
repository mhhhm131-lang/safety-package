<?php

namespace App\Modules\Permit\Services;

use App\Modules\Permit\Models\Permit;
use App\Modules\Permit\Models\PermitType;
use App\Modules\Risk\Models\Risk;

/**
 * مخاطر التصريح تُشتق من مصدرين:
 *
 *   ١. **التصريح الأب** (تسلسل النطاق: مشروع ← تأهيل مقاول ← تصريح تشغيلي ← دخول فرد):
 *      كل مخاطر الأب تُورَّث للابن.
 *   ٢. **مخاطر المكان** — إضافة المعهد بدل مسار «النشاط الاقتصادي» في OHSMS (غير منقول §٢):
 *      المخاطر الفعّالة المسجَّلة على مكان التصريح، وهي سجل المعهد الحقيقي (المرحلة ٢).
 *      تُرتَّب بالدرجة تنازلياً ويؤخذ منها حد أعلى حتى لا تغرق القائمة.
 *
 * تصاريح فئة «عامل» (دخول فرد) لا ترث مخاطر المكان: أهليتها فحص شخصي (تدريب، طبي، كفاءات)
 * تتولاه GateReadinessService، وجرّ كل مخاطر المكان إليها ضجيج بلا معنى.
 */
class PermitRiskInheritanceService
{
    private const PLACE_RISK_LIMIT = 30;

    public function __construct(
        private readonly PermitService|null $permits = null,
    ) {}

    /** يعيد عدد المخاطر المضافة. */
    public function inheritAndDerive(Permit $permit, ?int $userId = null): int
    {
        $riskIds = [];

        if ($permit->parent_permit_id) {
            $riskIds = array_merge($riskIds, $this->parentRiskIds($permit));
        }

        if ($permit->permit_category !== PermitType::CATEGORY_WORKER && $permit->place_id) {
            $riskIds = array_merge($riskIds, $this->placeRiskIds($permit));
        }

        if ($riskIds === []) {
            return 0;
        }

        return $this->attach($permit, array_values(array_unique($riskIds)), $userId);
    }

    /** @return array<int, int> */
    private function parentRiskIds(Permit $permit): array
    {
        return \Illuminate\Support\Facades\DB::table('permit_risks')
            ->where('permit_id', $permit->parent_permit_id)
            ->pluck('risk_id')
            ->all();
    }

    /**
     * مخاطر المكان الفعّالة (سجل الإدارات في المرحلة ٢)، الأشد أولاً.
     *
     * @return array<int, int>
     */
    private function placeRiskIds(Permit $permit): array
    {
        return Risk::query()
            ->where('place_id', $permit->place_id)
            ->where('risk_type', 'active')
            ->whereNotIn('status', ['closed', 'rejected'])
            ->orderByDesc('risk_score')
            ->limit(self::PLACE_RISK_LIMIT)
            ->pluck('id')
            ->all();
    }

    /**
     * إدراج بلا تكرار. لا يستعمل PermitService::attachRisks لتفادي حلقة الاعتماد
     * (PermitService ينشئ هذه الخدمة)، والسلوك نفسه.
     */
    private function attach(Permit $permit, array $riskIds, ?int $userId): int
    {
        $existing = \Illuminate\Support\Facades\DB::table('permit_risks')
            ->where('permit_id', $permit->id)
            ->pluck('risk_id')
            ->flip();

        $rows = [];
        $now = now();
        foreach ($riskIds as $riskId) {
            if ($existing->has($riskId)) {
                continue;
            }
            $rows[] = [
                'permit_id'      => $permit->id,
                'risk_id'        => $riskId,
                'auto_suggested' => true,
                'added_at'       => $now,
                'added_by_id'    => $userId,
                'notes'          => null,
            ];
            $existing[$riskId] = true;
        }

        if ($rows === []) {
            return 0;
        }

        \Illuminate\Support\Facades\DB::table('permit_risks')->insert($rows);

        return count($rows);
    }

    /**
     * النطاق الابن المتوقَّع لنطاق أب — يستعمله المعالج للاقتراح.
     */
    public static function childScopeFor(string $parentScope): ?string
    {
        return match ($parentScope) {
            Permit::SCOPE_PROJECT         => Permit::SCOPE_CONTRACTOR_PRE,
            Permit::SCOPE_CONTRACTOR_PRE  => Permit::SCOPE_CONTRACTOR_POST,
            Permit::SCOPE_CONTRACTOR_POST => Permit::SCOPE_INDIVIDUAL,
            default                       => null,
        };
    }
}
