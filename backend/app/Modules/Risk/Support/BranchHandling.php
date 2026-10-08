<?php

namespace App\Modules\Risk\Support;

use App\Core\Services\NotificationService;
use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Risk\Models\Risk;
use Illuminate\Support\Collection;

/**
 * قرار ٨٠: من يبدّل الإدارة المعالجة في نسخة الفرع ومن يعتمد، وأي إدارات يُختار منها.
 *   يقترح: منسق سلامة الفرع (حسابه على وحدة الفرع نفسها)، ومسؤول السلامة.
 *   يعتمد: مدير الفرع (حسابه على وحدة الفرع أو ما فوقها)، ومسؤول السلامة.
 *   الاختيار: وحدات الفرع (فرعه وما تحته بلا فروع أخرى).
 */
final class BranchHandling
{
    public static function inBranch(Risk $r): bool
    {
        return $r->risk_type === 'active' && $r->branch_unit_id !== null;
    }

    /** هل النسخة في المركز الرئيسي (فرع الملز)؟ هناك العام هو الحكم ولا بديل */
    public static function isMainCenter(Risk $r): bool
    {
        $main = EmergencyBuilding::main()?->branch_unit_id;
        return $main !== null && (int) $r->branch_unit_id === (int) $main;
    }

    public static function canPropose(User $user, Risk $r): bool
    {
        $p = $user->profile;
        if (!$p || !$p->is_active || !self::inBranch($r) || self::isMainCenter($r)) return false;
        if ($p->role === RiskApproval::GENERAL_APPROVER) return true;
        if (self::canApprove($user, $r)) return true;
        return $p->role === 'safety_coordinator' && (int) $p->organization_unit_id === (int) $r->branch_unit_id;
    }

    public static function canApprove(User $user, Risk $r): bool
    {
        $p = $user->profile;
        if (!$p || !$p->is_active || !self::inBranch($r) || self::isMainCenter($r)) return false;
        if ($p->role === RiskApproval::GENERAL_APPROVER) return true;
        if ($p->role !== 'branch_manager' || !$p->organization_unit_id) return false;
        return in_array((int) $r->branch_unit_id, OrganizationUnit::descendantIdsOf($p->organization_unit_id), true);
    }

    /** إدارات الفرع التي يُختار منها البديل: فرع النسخة وما تحته بلا فروع أخرى متداخلة */
    public static function unitChoices(Risk $r): Collection
    {
        if (!$r->branch_unit_id) return collect();
        $ids = OrganizationUnit::descendantIdsOf($r->branch_unit_id);
        $nested = OrganizationUnit::whereIn('id', $ids)->where('id', '!=', $r->branch_unit_id)->where('unit_type', 'region')->pluck('id');
        foreach ($nested as $n) $ids = array_diff($ids, OrganizationUnit::descendantIdsOf((int) $n));
        return OrganizationUnit::whereIn('id', $ids)->where('is_active', true)->orderBy('order')->orderBy('id')->get(['id', 'name', 'parent_id']);
    }

    /** مديرو الفرع (الوحدة وما فوقها) ومسؤول السلامة — يُنبَّهون بالبديل المقترح */
    public static function notifyApprovers(Risk $r, User $by): void
    {
        $ids = [];
        for ($u = OrganizationUnit::find($r->branch_unit_id), $n = 0; $u && $n < 10; $u = $u->parent_id ? OrganizationUnit::find($u->parent_id) : null, $n++) $ids[] = $u->id;
        $users = UserProfile::where('is_active', true)->where(fn ($q) => $q->where('role', RiskApproval::GENERAL_APPROVER)
            ->orWhere(fn ($w) => $w->where('role', 'branch_manager')->whereIn('organization_unit_id', $ids)))->pluck('user_id');
        $inbox = app(NotificationService::class);
        foreach ($users as $uid) {
            try {
                $inbox->create((int) $uid, 'risk.handling_override', 'بديل إدارة معالجة ينتظر اعتمادك',
                    $by->name.' اقترح «'.$r->handling_unit_name.'» إدارةً معالجة لـ«'.$r->title.'» في «'.($r->branchUnit?->name ?? 'الفرع').'»', route('risk.show', $r, false));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
