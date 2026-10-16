<?php

namespace App\Modules\Governance\Support;

use App\Models\User;
use App\Modules\Emergency\Services\PlaceProfile as P;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Services\BuildingContext;
use App\Modules\Governance\Services\ScopeService;

/**
 * من يعدّل «كلام المكان» (المرحلة ٣٠، قرار ٨٩): منسق المكان ومديره، ومسؤول السلامة يرى الكل.
 * القراءة لمن المكان في مبانيه (قرار ٨٣ — `PlaceFileController::guardBuilding`)؛ الكتابة هنا:
 *   مسؤول السلامة والمناوب ومدير الشؤون الإدارية والهندسية (من يحرّر الخطتين أصلاً) ← كل مكان؛
 *   مدير الفرع ومنسق سلامة الفرع (قرار ٨١) ← أماكن مباني فرعه؛ مدير المرافق والصيانة ← أماكن مبناه؛
 *   منسق السلامة ومدير الإدارة ورئيس القسم ← المكان الذي في حسابه أو في إدارته.
 * المرحلتان ٣٠-٢ و٣٠-٣ تقرآن القاعدة نفسها.
 */
final class PlaceWordsAccess
{
    public const EDITORS = 'منسق المكان ومديره، ومدير المرافق والصيانة، ومسؤول السلامة';

    public static function canEdit(User $user, Place $place): bool
    {
        $p = $user->profile;
        if (!$p || !$p->is_active) return false;
        if (P::canPlans($user)) return true;
        if (!BuildingContext::choices($user)->contains('id', (int) $place->building_id)) return false;
        $role = $user->role();
        if (in_array($role, ['branch_manager', 'facilities_manager', 'system_staff'], true)) return true;
        if (ScopeService::isBranchCoordinator($p)) return true;
        if (in_array($role, ['safety_coordinator', 'department_manager', 'section_manager'], true)) {
            return (int) $p->place_id === (int) $place->id || (int) ($p->organizationUnit?->place_id ?? 0) === (int) $place->id;
        }
        return false;
    }

    /** سجل «عدّله فلان في كذا يوم كذا»: مسؤول السلامة ومن يرى الكل، ومن يحرّر الخطتين. */
    public static function seesLog(User $user): bool
    {
        return P::canPlans($user) || ScopeService::seesAllFor($user->profile);
    }
}
