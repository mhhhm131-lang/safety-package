<?php

namespace App\Modules\Incident\Services;

use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Incident\Models\Incident;
use Illuminate\Database\Eloquent\Builder;

/**
 * من يرى أي بلاغ (من OHSMS بلا tenant/project/external_party).
 * إضافة المعهد: المكان — منسق أو فني مربوط بمكان يرى بلاغات مكانه، والمعيَّن (منسق/فني) يرى بلاغه دائماً
 * (OHSMS كان يفحص actor/assigned_to/executor فقط فلا يرى الفني المعيَّن بلاغه — أُصلح).
 */
class IncidentVisibilityService
{
    private const MANAGEMENT_ROLES = ['top_management', 'safety_committee', 'branch_manager', 'department_manager', 'section_manager', 'security_safety_head']; // قرار ٢٠٢٦-٠٩-٠٨: رئيس الأمن والسلامة كمدير إدارة

    /** الأدوار المعهدية المضافة: اطلاع على الكل (مؤقت حتى يقرر المستخدم — BACKEND.md الفجوة ٩). */
    private const INSTITUTE_VIEW_ALL = ['top_management', 'safety_committee', 'admin_eng_manager', 'facilities_manager', 'support_team'];

    public function canView(Incident $incident, int $userId): bool
    {
        $profile = $this->profile($userId);
        if (!$profile) return false;

        if (in_array($profile->role, ['system_admin', 'system_staff'], true)) return true;
        if (in_array($profile->role, self::INSTITUTE_VIEW_ALL, true)) return true;
        if ($profile->role === 'safety_coordinator' && !$profile->organization_unit_id && !$profile->place_id) return true;

        if ($this->involved($incident, $userId)) return true;

        if ($profile->place_id && in_array($profile->role, ['safety_coordinator', 'field_worker'], true)
            && $incident->place_id === $profile->place_id) {
            return true;
        }
        if ($profile->organization_unit_id && (in_array($profile->role, self::MANAGEMENT_ROLES, true) || $profile->role === 'safety_coordinator')) {
            $allowed = OrganizationUnit::descendantIdsOf($profile->organization_unit_id);
            if (in_array($incident->organization_unit_id, $allowed, true)) return true;
        }
        return false;
    }

    public function getVisibleIncidents(int $userId): Builder
    {
        $profile = $this->profile($userId);
        $query = Incident::query();
        if (!$profile) return $query->whereRaw('1 = 0');

        if (in_array($profile->role, ['system_admin', 'system_staff'], true)) return $query;
        if (in_array($profile->role, self::INSTITUTE_VIEW_ALL, true)) return $query;
        if ($profile->role === 'safety_coordinator' && !$profile->organization_unit_id && !$profile->place_id) return $query;

        $unitIds = ($profile->organization_unit_id && (in_array($profile->role, self::MANAGEMENT_ROLES, true) || $profile->role === 'safety_coordinator'))
            ? OrganizationUnit::descendantIdsOf($profile->organization_unit_id) : [];
        $placeId = ($profile->place_id && in_array($profile->role, ['safety_coordinator', 'field_worker'], true)) ? $profile->place_id : null;

        return $query->where(function (Builder $q) use ($userId, $unitIds, $placeId) {
            $q->where('actor_id', $userId)
                ->orWhere('assigned_to_id', $userId)
                ->orWhere('executor_id', $userId)
                ->orWhere('incident_coordinator_id', $userId)
                ->orWhere('incident_field_team_id', $userId);
            if ($unitIds) $q->orWhereIn('organization_unit_id', $unitIds);
            if ($placeId) $q->orWhere('place_id', $placeId);
        });
    }

    private function involved(Incident $i, int $userId): bool
    {
        return $i->actor_id === $userId || $i->assigned_to_id === $userId || $i->executor_id === $userId
            || $i->incident_coordinator_id === $userId || $i->incident_field_team_id === $userId;
    }

    private function profile(int $userId): ?UserProfile
    {
        return UserProfile::where('user_id', $userId)->where('is_active', true)->first();
    }
}
