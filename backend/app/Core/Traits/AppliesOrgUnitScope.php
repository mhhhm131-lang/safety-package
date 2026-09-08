<?php

namespace App\Core\Traits;

use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\UserProfile;
use Illuminate\Database\Eloquent\Builder;

/**
 * نطاق الرؤية بالوحدة التنظيمية (من OHSMS بلا سياق المشاريع):
 * أدوار الإشراف العام ترى الكل؛ غيرها يرى وحدته وما تحتها + السجلات بلا وحدة.
 */
trait AppliesOrgUnitScope
{
    protected array $globalScopeRoles = ['system_admin', 'system_staff', 'top_management', 'safety_committee', 'safety_coordinator'];

    protected function scopeToUserOrgUnit(Builder $query, string $column = 'organization_unit_id'): void
    {
        $profile = $this->userProfile();
        if (!$profile) {
            $query->whereNull($column);
            return;
        }
        if (in_array($profile->role, $this->globalScopeRoles, true)) {
            return;
        }
        if (!$profile->organization_unit_id) {
            $query->whereNull($column);
            return;
        }
        $allowed = OrganizationUnit::descendantIdsOf($profile->organization_unit_id);
        $query->where(fn (Builder $q) => $q->whereNull($column)->orWhereIn($column, $allowed));
    }

    protected function userProfile(): ?UserProfile
    {
        static $cache = [];
        $userId = auth()->id();
        if ($userId && !array_key_exists($userId, $cache)) {
            $cache[$userId] = UserProfile::where('user_id', $userId)->first();
        }
        return $userId ? $cache[$userId] : null;
    }

    protected function isGlobalScopeRole(): bool
    {
        $p = $this->userProfile();
        return $p && in_array($p->role, $this->globalScopeRoles, true);
    }
}
