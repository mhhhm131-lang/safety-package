<?php

namespace App\Modules\Risk\Support;

use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Risk\Models\Risk;

/**
 * قرار ٦٩ (٢٠٢٦-٠٩-٣٠، بكلمة المستخدم): من يعتمد الخطر — مصدر واحد للمسار والبطاقة والطابور والتنبيه.
 *   خطر في سجل إدارة (له وحدة)            ← مدير وحدته أو مدير ما فوقها، لا غيرهم.
 *   السجل العام، والخطر بنطاق المعهد كله  ← مسؤول السلامة وحده.
 * المناوب والمدير العام والنواب ولجنة السلامة يرون ولا يعتمدون.
 *
 * تتمة (٢٠٢٦-١٠-٠٣، بكلمته «هم مدراء إدارات وأقسام ولهم دور في النظام» ثم «نعم موافق»): «مدير الوحدة» ليس الأدوار العامة الثلاثة وحدها —
 * مدير الشؤون الإدارية والهندسية ومدير المرافق والصيانة ورئيس الأمن والسلامة مديرون بأدوار خاصة، وكلٌّ يعتمد مخاطر الوحدة المربوط بها حسابه.
 */
final class RiskApproval
{
    /** مديرو الوحدات: من يعتمد مخاطر وحدته وما تحتها — الأدوار العامة الثلاثة، وأدوار المعهد الخاصة التي أصحابها مديرون */
    public const UNIT_MANAGERS = ['branch_manager', 'department_manager', 'section_manager', 'admin_eng_manager', 'facilities_manager', 'security_safety_head'];

    public const GENERAL_APPROVER = 'system_admin';

    /**
     * قرار ٧٠: من يرى سجل الإدارات كله. منسق السلامة ليس منهم — نطاقه نطاق مديره: وحدته وما تحتها
     * (كان في المخاطر دوراً عاماً موروثاً من OHSMS فيرى كل الإدارات ويفعّل لأيها أو «عام»).
     */
    public const REGISTER_WIDE = ['system_admin', 'system_staff', 'top_management', 'safety_committee'];

    /** قرار ٧٠: من يعتمد الخطر إن فعّله بنفسه صار نشطاً فوراً؛ غيره ينتظر المعتمد. $unitId فارغ = نطاق المعهد كله */
    public static function activatesDirectly(User $user, ?int $unitId): bool
    {
        return self::canApprove($user, new Risk(['risk_type' => 'active', 'organization_unit_id' => $unitId]));
    }

    /** خطر عام: السجل العام، أو فعلي بلا وحدة (نطاق المعهد كله) */
    public static function isGeneral(Risk $risk): bool
    {
        return $risk->risk_type !== 'active' || !$risk->organization_unit_id;
    }

    public static function canApprove(User $user, Risk $risk): bool
    {
        $profile = $user->profile;
        if (!$profile || !$profile->is_active) return false;
        if (self::isGeneral($risk)) return $profile->role === self::GENERAL_APPROVER;
        if (!in_array($profile->role, self::UNIT_MANAGERS, true) || !$profile->organization_unit_id) return false;
        return in_array((int) $risk->organization_unit_id, OrganizationUnit::descendantIdsOf($profile->organization_unit_id), true);
    }

    /** حسابات من يعتمدون هذا الخطر — للتنبيه عند رفعه. @return array<int, int> */
    public static function approverIds(Risk $risk): array
    {
        if (self::isGeneral($risk)) {
            return UserProfile::where('role', self::GENERAL_APPROVER)->where('is_active', true)->pluck('user_id')->all();
        }
        // وحدة الخطر وما فوقها حتى الجذر
        $ids = [];
        for ($u = OrganizationUnit::find($risk->organization_unit_id), $n = 0; $u && $n < 10; $u = $u->parent_id ? OrganizationUnit::find($u->parent_id) : null, $n++) {
            $ids[] = $u->id;
        }
        return UserProfile::whereIn('role', self::UNIT_MANAGERS)->whereIn('organization_unit_id', $ids)->where('is_active', true)->pluck('user_id')->all();
    }
}
