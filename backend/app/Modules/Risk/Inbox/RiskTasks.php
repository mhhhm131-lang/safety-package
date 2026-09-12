<?php

namespace App\Modules\Risk\Inbox;

use App\Core\Inbox\Task;
use App\Core\Inbox\TaskSource;
use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Risk\Models\Risk;
use Illuminate\Support\Collection;

/** طابور اعتماد المخاطر (RiskController::approvalQueue) بصيغة مهام: خطر بانتظار الاعتماد ← «اعتمد» / «التفاصيل». */
class RiskTasks implements TaskSource
{
    /** نطاق AppliesOrgUnitScope نفسه: أدوار الإشراف العام ترى الكل؛ غيرها وحدته وما تحتها + بلا وحدة. */
    private const GLOBAL = ['system_admin', 'system_staff', 'top_management', 'safety_committee', 'safety_coordinator'];

    public function tasksFor(User $user): Collection
    {
        $profile = UserProfile::where('user_id', $user->id)->first();
        if (!$profile || !PermissionRegistry::hasPermission($profile->role, 'risk.approve')) return collect();

        $q = Risk::where('status', 'pending_approval')->with('organizationUnit');
        if (!in_array($profile->role, self::GLOBAL, true)) {
            if (!$profile->organization_unit_id) {
                $q->whereNull('organization_unit_id');
            } else {
                $allowed = OrganizationUnit::descendantIdsOf($profile->organization_unit_id);
                $q->where(fn ($w) => $w->whereNull('organization_unit_id')->orWhereIn('organization_unit_id', $allowed));
            }
        }
        return $q->get()->map(fn (Risk $r) => new Task(
            key: "risk:{$r->id}:approve",
            module: 'المخاطر',
            question: 'خطر «'.$r->title.'»'.($r->organizationUnit ? ' لإدارة '.$r->organizationUnit->name : '').' ينتظر اعتمادك',
            primary: ['label' => 'اعتمد', 'url' => route('risk.approve', $r), 'method' => 'POST'],
            secondary: ['label' => 'التفاصيل', 'url' => route('risk.show', $r)],
            detailsUrl: route('risk.show', $r),
            createdAt: $r->created_at,
        ));
    }
}
