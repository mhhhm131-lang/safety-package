<?php

namespace App\Modules\Form\Support;

use Illuminate\Support\Facades\DB;

/**
 * ٢٩-٠: ملء الإدارة والمبنى للتكليفات والتعبئات القائمة بلا مبنى — من حساب صاحبها:
 * وحدته، ومبنى فرع وحدته في الهيكل (رأسها الذي يشير إليه مبنى)، وإلا مبنى حسابه أو مكانه، وإلا المبنى الرئيسي.
 * يستدعيه ترحيل `2026_10_14_100001` ويُختبر وحده في `FormIsolationTest` بصفوف خام.
 */
final class OwnerScopeBackfill
{
    /** يعيد عدد الصفوف التي مُلئت */
    public static function run(): int
    {
        $main = DB::table('emergency_buildings')->orderBy('id')->value('id');
        $units = DB::table('organization_units')->get(['id', 'parent_id', 'unit_type', 'place_id'])->keyBy('id');
        $buildingByHead = DB::table('emergency_buildings')->whereNotNull('branch_unit_id')->orderBy('id')->get(['id', 'branch_unit_id'])
            ->groupBy('branch_unit_id')->map(fn ($g) => (int) $g->first()->id);
        $placeBuilding = DB::table('places')->pluck('building_id', 'id');

        $unitBuilding = function (?int $unitId) use ($units, $buildingByHead, $placeBuilding): ?int {
            if (!$unitId || !$units->has($unitId)) return null;
            for ($u = $units->get($unitId), $n = 0; $u && $n < 12; $n++) {
                if ($u->parent_id === null || $u->unit_type === 'region') {
                    $b = $buildingByHead->get((int) $u->id);
                    if ($b) return $b;
                    break;
                }
                $u = $units->get($u->parent_id);
            }
            $p = $units->get($unitId)->place_id;
            return $p && $placeBuilding->get($p) ? (int) $placeBuilding->get($p) : null;
        };

        $profiles = DB::table('user_profiles')->get(['user_id', 'organization_unit_id', 'building_id', 'place_id'])->keyBy('user_id');
        $scopeOf = function (?int $userId) use ($profiles, $unitBuilding, $placeBuilding, $main): array {
            $p = $userId ? $profiles->get($userId) : null;
            $unit = $p?->organization_unit_id ? (int) $p->organization_unit_id : null;
            $building = $unitBuilding($unit)
                ?? ($p?->building_id ? (int) $p->building_id : null)
                ?? ($p?->place_id && $placeBuilding->get($p->place_id) ? (int) $placeBuilding->get($p->place_id) : null)
                ?? ($main ? (int) $main : null);
            return ['building_id' => $building, 'organization_unit_id' => $unit];
        };

        $n = 0;
        foreach (['form_assignments' => 'assigned_to_id', 'form_submissions' => 'submitted_by_id'] as $table => $col) {
            foreach (DB::table($table)->whereNull('building_id')->get(['id', $col]) as $row) {
                $uid = $row->{$col} === null ? null : (int) $row->{$col};
                $n += DB::table($table)->where('id', $row->id)->update($scopeOf($uid));
            }
        }

        return $n;
    }
}
