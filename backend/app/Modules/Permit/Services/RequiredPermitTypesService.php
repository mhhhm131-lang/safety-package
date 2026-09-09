<?php

namespace App\Modules\Permit\Services;

use App\Modules\Permit\Models\Permit;
use App\Modules\Permit\Models\PermitType;
use App\Modules\Permit\Models\RiskRequiredPermitType;
use App\Modules\Risk\Models\Risk;
use Illuminate\Support\Collection;

/**
 * «ما التصاريح التي تستلزمها هذه المخاطر؟» — يقرأ `risk_required_permit_types`.
 *
 * يُستعمل في موضعين:
 *   - عند اختيار المكان في المعالج: يقترح أنواع التصاريح اللازمة لمخاطره الفعّالة.
 *   - في صفحة التصريح: يبيّن ما إن كان يلزم تصريح آخر مع هذا التصريح (تصريح مسبق).
 *
 * الشرط `severity_ge_N` يُقاس بشدة الخطر (١–٥)، و`requires_trade` بمهن التصريح.
 * (في OHSMS كان المسار من «النشاط الاقتصادي»؛ عندنا من مخاطر المكان الفعّالة — سجل المعهد.)
 */
class RequiredPermitTypesService
{
    /**
     * أنواع التصاريح التي تستلزمها مخاطر مكان.
     *
     * @param array<int> $tradeIds
     * @return Collection<int, array{type: PermitType, mandatory: bool, triggered_by: array<int, string>}>
     */
    public function forPlace(int $placeId, array $tradeIds = []): Collection
    {
        $risks = Risk::query()
            ->where('place_id', $placeId)
            ->where('risk_type', 'active')
            ->whereNotIn('status', ['closed', 'rejected'])
            ->get(['id', 'title', 'category_id', 'severity']);

        return $this->forRisks($risks, $tradeIds);
    }

    /** أنواع التصاريح التي تستلزمها مخاطر تصريح قائم. */
    public function forPermit(Permit $permit): Collection
    {
        $risks = $permit->risks()->get(['risks.id', 'risks.title', 'risks.category_id', 'risks.severity']);
        $tradeIds = $permit->trades()->pluck('trades.id')->all();

        return $this->forRisks($risks, $tradeIds)
            // النوع الحالي ليس «تصريحاً مطلوباً إضافياً».
            ->reject(fn ($row) => $row['type']->id === $permit->permit_type_id)
            ->values();
    }

    /**
     * @param Collection<int, Risk> $risks
     * @param array<int> $tradeIds
     * @return Collection<int, array{type: PermitType, mandatory: bool, triggered_by: array<int, string>}>
     */
    public function forRisks(Collection $risks, array $tradeIds = []): Collection
    {
        if ($risks->isEmpty()) {
            return collect();
        }

        $categoryIds = $risks->pluck('category_id')->filter()->unique()->all();
        $riskIds     = $risks->pluck('id')->all();

        $mappings = RiskRequiredPermitType::query()
            ->where(fn ($q) => $q->whereIn('risk_category_id', $categoryIds)->orWhereIn('risk_id', $riskIds))
            ->with('permitType')
            ->get();

        if ($mappings->isEmpty()) {
            return collect();
        }

        $byId       = $risks->keyBy('id');
        $byCategory = $risks->groupBy('category_id');
        $out        = [];

        foreach ($mappings as $map) {
            $type = $map->permitType;
            if (!$type || !$type->is_active) {
                continue;
            }

            $matching = $map->risk_id
                ? collect([$byId->get($map->risk_id)])->filter()
                : $byCategory->get($map->risk_category_id, collect());

            if ($matching->isEmpty() || !$this->conditionMatches($map, $matching, $tradeIds)) {
                continue;
            }

            $out[$type->id] ??= ['type' => $type, 'mandatory' => false, 'triggered_by' => []];
            $out[$type->id]['mandatory'] = $out[$type->id]['mandatory'] || $map->is_mandatory;
            foreach ($matching as $risk) {
                $out[$type->id]['triggered_by'][] = $risk->title;
            }
            $out[$type->id]['triggered_by'] = array_values(array_unique($out[$type->id]['triggered_by']));
        }

        return collect(array_values($out));
    }

    /** @param Collection<int, Risk> $risks */
    private function conditionMatches(RiskRequiredPermitType $map, Collection $risks, array $tradeIds): bool
    {
        return match ($map->triggering_condition) {
            RiskRequiredPermitType::COND_ALWAYS         => true,
            RiskRequiredPermitType::COND_SEVERITY_GE_2  => $risks->max('severity') >= 2,
            RiskRequiredPermitType::COND_SEVERITY_GE_3  => $risks->max('severity') >= 3,
            RiskRequiredPermitType::COND_SEVERITY_GE_4  => $risks->max('severity') >= 4,
            RiskRequiredPermitType::COND_SEVERITY_GE_5  => $risks->max('severity') >= 5,
            RiskRequiredPermitType::COND_REQUIRES_TRADE => $map->trade_id && in_array($map->trade_id, $tradeIds, true),
            default                                     => false,
        };
    }
}
