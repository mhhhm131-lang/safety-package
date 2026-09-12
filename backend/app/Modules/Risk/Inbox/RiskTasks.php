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
        if (!$profile) return collect();
        $out = collect();

        // ١١-٣: اقتراح النظام — مدير إدارة يملك التفعيل وإدارته بلا مخاطر مفعّلة ← يبدأ من كتاب المعهد (لا تُفعَّل شيء عنه)
        if ($profile->organization_unit_id && PermissionRegistry::hasPermission($profile->role, 'risk.activate')
            && !in_array($profile->role, self::GLOBAL, true)
            && !Risk::where('risk_type', 'active')->where('organization_unit_id', $profile->organization_unit_id)->exists()) {
            $unit = OrganizationUnit::find($profile->organization_unit_id);
            $out->push(new Task(
                key: "risk:suggest:{$profile->organization_unit_id}", module: 'المخاطر',
                question: 'إدارة «'.($unit?->name ?? '').'» بلا مخاطر مفعّلة — اختر أخطارها من سجل المعهد',
                primary: ['label' => 'ابدأ من السجل', 'url' => route('risk.reference.index')],
                detailsUrl: route('risk.reference.index'),
            ));
        }
        if (!PermissionRegistry::hasPermission($profile->role, 'risk.approve')) return $out;

        $q = Risk::where('status', 'pending_approval')->with('organizationUnit');
        if (!in_array($profile->role, self::GLOBAL, true)) {
            if (!$profile->organization_unit_id) {
                $q->whereNull('organization_unit_id');
            } else {
                $allowed = OrganizationUnit::descendantIdsOf($profile->organization_unit_id);
                $q->where(fn ($w) => $w->whereNull('organization_unit_id')->orWhereIn('organization_unit_id', $allowed));
            }
        }
        return $out->merge($q->get()->map(fn (Risk $r) => new Task(
            key: "risk:{$r->id}:approve",
            module: 'المخاطر',
            question: 'خطر «'.$r->title.'»'.($r->organizationUnit ? ' لإدارة '.$r->organizationUnit->name : '').' ينتظر اعتمادك',
            primary: ['label' => 'اعتمد', 'url' => route('risk.approve', $r), 'method' => 'POST'],
            secondary: ['label' => 'التفاصيل', 'url' => route('risk.show', $r)],
            detailsUrl: route('risk.show', $r),
            createdAt: $r->created_at,
        )));
    }
}
