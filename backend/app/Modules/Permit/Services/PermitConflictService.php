<?php

namespace App\Modules\Permit\Services;

use App\Modules\Permit\Models\Permit;
use App\Modules\Permit\Models\PermitType;
use App\Modules\Permit\Models\PermitTypeConflictRule;

/**
 * فحصان قبل اعتماد التصريح أو تفعيله:
 *   ١. سعة المكان — مجموع العمال والمعدات في التصاريح المتزامنة لا يتجاوز حد المكان (إن حُدّد).
 *   ٢. تعارض نوعي العمل — قاعدة «مانع» تمنع تزامن نوعين في المكان نفسه (أو مطلقاً إن كانت عامة).
 *
 * تصاريح التأهيل تقييم إداري لا عمل ميداني: لا تشغل سعة ولا تتعارض.
 */
class PermitConflictService
{
    /** @return array{has_blocks: bool, conflicts: array<int, array{type: string, severity: string, message: string}>} */
    public function check(Permit $permit): array
    {
        if ($permit->permit_category === PermitType::CATEGORY_QUALIFICATION) {
            return ['has_blocks' => false, 'conflicts' => []];
        }

        $conflicts = array_merge(
            $permit->place_id ? $this->checkPlaceCapacity($permit) : [],
            $this->checkTypeConflicts($permit),
        );

        return [
            'has_blocks' => collect($conflicts)->where('severity', PermitTypeConflictRule::SEVERITY_BLOCK)->isNotEmpty(),
            'conflicts'  => $conflicts,
        ];
    }

    // ── سعة المكان ──

    private function checkPlaceCapacity(Permit $permit): array
    {
        $place = $permit->place ?: $permit->place()->first();
        if (!$place || (!$place->max_workers && !$place->max_equipment)) {
            return []; // بلا حد مُدخَل = بلا فحص سعة (الأرقام قرار المستخدم)
        }

        $overlapping = $this->overlappingQuery($permit)->get(['workers_count', 'equipment_count']);
        $conflicts = [];

        if ($place->max_workers) {
            $total = $overlapping->sum('workers_count') + (int) $permit->workers_count;
            if ($total > $place->max_workers) {
                $conflicts[] = [
                    'type'     => 'capacity',
                    'severity' => PermitTypeConflictRule::SEVERITY_BLOCK,
                    'message'  => "المكان «{$place->name}» سيتجاوز حد العمال ({$place->max_workers}). المجموع بهذا التصريح: {$total}.",
                ];
            }
        }

        if ($place->max_equipment) {
            $total = $overlapping->sum('equipment_count') + (int) $permit->equipment_count;
            if ($total > $place->max_equipment) {
                $conflicts[] = [
                    'type'     => 'capacity',
                    'severity' => PermitTypeConflictRule::SEVERITY_BLOCK,
                    'message'  => "المكان «{$place->name}» سيتجاوز حد المعدات ({$place->max_equipment}). المجموع بهذا التصريح: {$total}.",
                ];
            }
        }

        return $conflicts;
    }

    // ── تعارض الأنواع ──

    private function checkTypeConflicts(Permit $permit): array
    {
        if (!$permit->permit_type_id) {
            return [];
        }

        $rules = PermitTypeConflictRule::where('is_active', true)
            ->where(fn ($q) => $q
                ->where('permit_type_a_id', $permit->permit_type_id)
                ->orWhere('permit_type_b_id', $permit->permit_type_id))
            ->where(fn ($q) => $q->whereNull('place_id')->orWhere('place_id', $permit->place_id))
            ->get();

        if ($rules->isEmpty()) {
            return [];
        }

        $opposingIds = $rules
            ->map(fn ($r) => $r->permit_type_a_id === $permit->permit_type_id ? $r->permit_type_b_id : $r->permit_type_a_id)
            ->unique()->values()->all();

        $clashing = $this->overlappingQuery($permit)
            ->whereIn('permit_type_id', $opposingIds)
            ->with('type')
            ->get();

        $conflicts = [];
        foreach ($clashing as $clash) {
            $rule = $rules->first(fn ($r) => in_array($clash->permit_type_id, [$r->permit_type_a_id, $r->permit_type_b_id], true));
            $conflicts[] = [
                'type'     => 'work_type',
                'severity' => $rule?->severity ?? PermitTypeConflictRule::SEVERITY_BLOCK,
                'message'  => "تعارض مع التصريح «{$clash->code}» ({$clash->type?->name})"
                    .($rule?->reason ? ": {$rule->reason}" : '.'),
            ];
        }

        return $conflicts;
    }

    /**
     * التصاريح المعتمدة أو النشطة التي تتقاطع زمنياً مع هذا التصريح في المكان نفسه.
     * بلا نافذة زمنية على أحد الطرفين: يُعدّ التقاطع قائماً (الأحوط).
     */
    private function overlappingQuery(Permit $permit): \Illuminate\Database\Eloquent\Builder
    {
        $query = Permit::query()
            ->whereIn('status', [Permit::STATUS_APPROVED, Permit::STATUS_ACTIVE])
            ->where('id', '!=', $permit->id);

        if ($permit->place_id) {
            $query->where('place_id', $permit->place_id);
        }

        if ($permit->starts_at && $permit->expires_at) {
            $query->where(fn ($q) => $q
                ->whereNull('starts_at')->orWhere('starts_at', '<', $permit->expires_at))
                ->where(fn ($q) => $q
                    ->whereNull('expires_at')->orWhere('expires_at', '>', $permit->starts_at));
        }

        return $query;
    }

    /**
     * لقطة سعة مكان الآن — للوحة التحكم وشاشة سعة الأماكن.
     *
     * @return array{place_id: int, name: string, code: string, max_workers: ?int, max_equipment: ?int,
     *               active_workers: int, active_equipment: int, workers_pct: ?int, equipment_pct: ?int, active_permits: int}
     */
    public function placeSnapshot(\App\Modules\Governance\Models\Place $place): array
    {
        $now = now();
        $rows = Permit::query()
            ->where('place_id', $place->id)
            ->whereIn('status', [Permit::STATUS_APPROVED, Permit::STATUS_ACTIVE])
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', $now))
            ->get(['workers_count', 'equipment_count']);

        $workers   = (int) $rows->sum('workers_count');
        $equipment = (int) $rows->sum('equipment_count');

        return [
            'place_id'         => $place->id,
            'name'             => $place->name,
            'code'             => $place->code,
            'max_workers'      => $place->max_workers,
            'max_equipment'    => $place->max_equipment,
            'active_workers'   => $workers,
            'active_equipment' => $equipment,
            'workers_pct'      => $place->max_workers ? (int) round($workers / $place->max_workers * 100) : null,
            'equipment_pct'    => $place->max_equipment ? (int) round($equipment / $place->max_equipment * 100) : null,
            'active_permits'   => $rows->count(),
        ];
    }

    /**
     * كل التعارضات القائمة الآن بين تصريحين نشطين — للوحة التحكم.
     *
     * @return array<int, array{permit_a: string, permit_b: string, place: string, severity: string, reason: ?string}>
     */
    public function activeConflicts(int $limit = 20): array
    {
        $rules = PermitTypeConflictRule::where('is_active', true)->with(['permitTypeA', 'permitTypeB', 'place'])->get();
        if ($rules->isEmpty()) {
            return [];
        }

        $now = now();
        $live = Permit::query()
            ->whereIn('status', [Permit::STATUS_APPROVED, Permit::STATUS_ACTIVE])
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', $now))
            ->with('place')
            ->get(['id', 'code', 'permit_type_id', 'place_id']);

        $out = [];
        foreach ($rules as $rule) {
            $as = $live->where('permit_type_id', $rule->permit_type_a_id);
            $bs = $live->where('permit_type_id', $rule->permit_type_b_id);
            foreach ($as as $a) {
                foreach ($bs as $b) {
                    if ($a->id === $b->id) {
                        continue;
                    }
                    // قاعدة مقيَّدة بمكان: الطرفان فيه. قاعدة عامة: الطرفان في المكان نفسه.
                    if ($rule->place_id) {
                        if ($a->place_id !== $rule->place_id || $b->place_id !== $rule->place_id) {
                            continue;
                        }
                    } elseif ($a->place_id !== $b->place_id || $a->place_id === null) {
                        continue;
                    }
                    $out[] = [
                        'permit_a' => $a->code,
                        'permit_b' => $b->code,
                        'place'    => $a->place?->name ?? '—',
                        'severity' => $rule->severity,
                        'reason'   => $rule->reason,
                    ];
                    if (count($out) >= $limit) {
                        return $out;
                    }
                }
            }
        }

        return $out;
    }
}
