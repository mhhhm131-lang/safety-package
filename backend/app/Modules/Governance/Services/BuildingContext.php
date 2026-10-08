<?php

namespace App\Modules\Governance\Services;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Governance\Models\OrganizationUnit;
use Illuminate\Support\Collection;

/**
 * ٢٨-٣ (قرار ٧٨): مبنى الجلسة — الوثائق التشغيلية (نماذج الفحص، ملف المكان، الهيكل في اللوحة، بلاغات الشاغلين للفني)
 * تُقرأ وتُكتب داخل مبنى واحد:
 *   - من له مبنى واحد (الفني، الموظف، مدير الإدارة…): مبنى حسابه، وإلا مبنى مكانه، وإلا الملز.
 *   - من يرى الكل (مسؤول السلامة، المناوب، القيادة، المديرون الثلاثة): مبدّل «المبنى» في الشريط، والافتراض مبنى حسابه.
 *   - مدير الفرع: مباني فرعه.
 * صفحات المعهد لا تعرف المبنى؛ الخادم يقدّم لها وثائق مبنى الجلسة (ipa-store.js يحمل `b` ويمسح المحلي عند تغيّره).
 */
final class BuildingContext
{
    private const KEY = 'ipa.building';

    /** المباني التي يحق للحساب العمل فيها، الملز أولاً */
    public static function choices(?User $user): Collection
    {
        if (!$user) return collect([EmergencyBuilding::mainOrCreate()]);
        $p = $user->profile;
        $role = $user->role();
        $active = $p && $p->is_active;
        if ($active && ScopeService::seesAll($role)) {
            return EmergencyBuilding::query()->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [EmergencyBuilding::MAIN_CODE])->orderBy('id')->get();
        }
        if ($active && $role === 'branch_manager') {
            $unitIds = $p->organization_unit_id ? OrganizationUnit::descendantIdsOf($p->organization_unit_id) : [];
            $own = $p->myBuilding();
            $list = EmergencyBuilding::query()->where(function ($q) use ($unitIds, $own) {
                $q->whereRaw('1 = 0');
                if ($unitIds) $q->orWhereIn('branch_unit_id', $unitIds);
                if ($own) {
                    $q->orWhere('id', $own->id);
                    if ($own->branch !== null) $q->orWhere('branch', $own->branch);
                }
            })->orderBy('id')->get();
            if ($list->isNotEmpty()) return $list;
        }
        return collect([$p?->myBuilding() ?? EmergencyBuilding::mainOrCreate()]);
    }

    public static function canSwitch(?User $user): bool
    {
        return self::choices($user)->count() > 1;
    }

    /** مبنى الجلسة: ما اختاره من الشريط إن كان من مبانيه، وإلا مبنى حسابه، وإلا أول مبانيه */
    public static function current(?User $user = null): EmergencyBuilding
    {
        $user ??= self::authUser();
        $choices = self::choices($user);
        $sel = $user ? self::selected() : 0;
        if ($sel && ($b = $choices->firstWhere('id', $sel))) return $b;
        $own = $user?->profile?->myBuilding();
        return ($own && $choices->contains('id', $own->id)) ? $own : $choices->first();
    }

    public static function id(?User $user = null): int
    {
        return self::current($user)->id;
    }

    /** اختيار مبنى من الشريط (أو ‎?b=‎ على المخزن) — false إن لم يكن من مبانيه */
    public static function set(?User $user, int $buildingId): bool
    {
        $user ??= self::authUser();
        if (!self::choices($user)->contains('id', $buildingId)) return false;
        if (self::hasSession()) session([self::KEY => $buildingId]);
        return true;
    }

    private static function selected(): int
    {
        return self::hasSession() ? (int) session(self::KEY, 0) : 0;
    }

    private static function hasSession(): bool
    {
        try {
            return app()->bound('session') && app('request')->hasSession();
        } catch (\Throwable) {
            return false;
        }
    }

    private static function authUser(): ?User
    {
        try {
            return auth()->user();
        } catch (\Throwable) {
            return null;
        }
    }
}
