<?php

namespace App\Core\Traits;

use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\UserProfile;
use Illuminate\Database\Eloquent\Builder;

/**
 * نطاق الرؤية بالوحدة التنظيمية (من OHSMS بلا سياق المشاريع):
 * أدوار الإشراف العام ترى الكل؛ غيرها يرى وحدته وما تحتها + السجلات بلا وحدة.
 * مصحَّح: الذاكرة المؤقتة للملف لكل نسخة متحكم لا static (كانت تتسرب بين الطلبات في الاختبارات).
 */
trait AppliesOrgUnitScope
{
    protected array $globalScopeRoles = ['system_admin', 'system_staff', 'top_management', 'safety_committee', 'safety_coordinator'];

    private array $orgScopeProfileCache = [];

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
        $userId = auth()->id();
        if (!$userId) return null;
        if (!array_key_exists($userId, $this->orgScopeProfileCache)) {
            $this->orgScopeProfileCache[$userId] = UserProfile::where('user_id', $userId)->first();
        }
        return $this->orgScopeProfileCache[$userId];
    }

    protected function isGlobalScopeRole(): bool
    {
        $p = $this->userProfile();
        return $p && in_array($p->role, $this->globalScopeRoles, true);
    }

    /**
     * نطاق الطرف الخارجي (المرحلة ٦ — نظير ContextScopeService في OHSMS): حساب المقاول/مشرف المقاول/المكتب الاستشاري
     * يرى صفوف طرفه فقط (users.external_party_id)؛ بلا طرف يرى لا شيء. بقية الأدوار لا تُقيَّد.
     */
    protected function scopeToExternalParty(Builder $query, string $column = 'external_party_id'): void
    {
        $user = auth()->user();
        if (!$user || !$user->isContractorRole()) return;
        if (!$user->external_party_id) {
            $query->whereRaw('1 = 0');
            return;
        }
        $query->where($column, $user->external_party_id);
    }

    /** يمنع حساب الطرف الخارجي من فتح صف طرف آخر (يُستدعى في show/edit). */
    protected function assertPartyAccess(?int $partyId): void
    {
        $user = auth()->user();
        if ($user && $user->isContractorRole() && (int) $user->external_party_id !== (int) $partyId) {
            abort(403, 'هذا السجل لطرف آخر.');
        }
    }

    protected function contractorPartyId(): ?int
    {
        $user = auth()->user();
        return ($user && $user->isContractorRole()) ? $user->external_party_id : null;
    }
}
