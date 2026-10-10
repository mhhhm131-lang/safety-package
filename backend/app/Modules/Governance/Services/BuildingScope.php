<?php

namespace App\Modules\Governance\Services;

use App\Models\User;
use App\Modules\Governance\Models\Place;

/**
 * قرار ٨٤ (بكلمته «طبّق الأفضل في العالم للعزل» ٢٠٢٦-١٠-١٠): العزل بين الفروع في موضع واحد.
 *
 * يجيب سؤالاً واحداً لكل طلب: ما مباني الحساب الحالي؟
 *   - بلا مستخدم (الطابور، البذر، الأمر، النموذج العام للبلاغ): null = بلا قيد.
 *   - من يرى الكل (مسؤول السلامة، القيادة، المديرون الثلاثة، وخانة «يرى كل الفروع» — قرار ٧٩): null = بلا قيد.
 *   - غيرهم: معرّفات مبانيه من `BuildingContext::choices` (مبنى حسابه، أو مباني فرعه لمدير الفرع ومنسقه — قرار ٨١).
 *
 * يقرؤه `BelongsToBuilding` في كل استعلام على نموذج له مبنى، وحارس ربط المعرّف في الرابط، و`EmergencyBuilding` نفسه.
 * الجواب يُحسب مرة في الطلب (كاش في الحاوية) لأن النطاق يُسأل مع كل استعلام.
 */
final class BuildingScope
{
    private const KEY = 'ipa.building-scope.';

    /** معرّفات مباني الحساب الحالي، أو null = بلا قيد. @return int[]|null */
    public static function buildingIds(): ?array
    {
        $user = self::user();
        if (!$user) return null;
        $key = self::KEY.'b.'.$user->id;
        if (app()->bound($key)) return app($key)['ids'];
        $p = $user->profile;
        $ids = ($p && $p->is_active && ScopeService::seesAllFor($p))
            ? null
            : BuildingContext::choices($user)->pluck('id')->map(fn ($i) => (int) $i)->all();
        app()->instance($key, ['ids' => $ids]);
        return $ids;
    }

    /** معرّفات أماكن مباني الحساب الحالي (للنماذج المرتبطة بمكان لا بمبنى، كالتصريح)، أو null = بلا قيد. @return int[]|null */
    public static function placeIds(): ?array
    {
        $ids = self::buildingIds();
        if ($ids === null) return null;
        $user = self::user();
        $key = self::KEY.'p.'.($user?->id ?? 0);
        if (app()->bound($key)) return app($key)['ids'];
        $places = Place::whereIn('building_id', $ids)->pluck('id')->map(fn ($i) => (int) $i)->all();
        app()->instance($key, ['ids' => $places]);
        return $places;
    }

    /** هل هذا المبنى من مباني الحساب؟ (ما بلا مبنى عامٌّ حيث يقرّره النموذج) */
    public static function allowsBuilding(?int $buildingId, bool $nullIsGeneral = false): bool
    {
        $ids = self::buildingIds();
        if ($ids === null) return true;
        if ($buildingId === null) return $nullIsGeneral;
        return in_array((int) $buildingId, $ids, true);
    }

    /** هل هذا المكان في مباني الحساب؟ */
    public static function allowsPlace(?int $placeId, bool $nullIsGeneral = false): bool
    {
        $ids = self::placeIds();
        if ($ids === null) return true;
        if ($placeId === null) return $nullIsGeneral;
        return in_array((int) $placeId, $ids, true);
    }

    /** يمسح الجواب المحفوظ — بعد تغيير حساب أو مبناه داخل الطلب نفسه (الاختبارات غالباً) */
    public static function forget(?User $user = null): void
    {
        $user ??= self::user();
        if (!$user) return;
        foreach (['b.', 'p.'] as $k) {
            $key = self::KEY.$k.$user->id;
            if (app()->bound($key)) app()->forgetInstance($key);
        }
    }

    private static function user(): ?User
    {
        try {
            return auth()->user();
        } catch (\Throwable) {
            return null;
        }
    }
}
