<?php

namespace App\Modules\Incident\Services;

use App\Core\Permissions\PermissionRegistry;
use App\Modules\Emergency\Services\PlaceProfile;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Governance\Services\ScopeService;
use App\Modules\Incident\Models\Incident;
use Illuminate\Database\Eloquent\Builder;

/**
 * من يرى أي بلاغ (من OHSMS بلا tenant/project/external_party) — البلاغ يراه من يعنيه (قرارا ٥٤ و٥٥).
 *
 * ٢١-٦: قاعدة واحدة تُبنى مرة (`rule()`) ويقرؤها الفتح والقائمة معاً فلا يختلفان:
 *   الكل      مسؤول السلامة، المناوب، الإدارة العليا، لجنة السلامة، ومنسق سلامة بلا وحدة ولا مكان.
 *   المعنيّ   المبلّغ بحسابه، والمنسق والمعالج المسمّيان، ومن أُحيل إليه — دائماً وأياً كان الدور.
 *   الإدارة   مدير الفرع/الإدارة/القسم ومدير الشؤون ومدير المرافق ورئيس الأمن ومنسق السلامة: بلاغات وحدة حسابه وما تحتها.
 *   المكان    مدير الفرع: أماكن مباني فرعه (`ScopeService`). الفني: الأماكن التي يغطيها. منسق السلامة: مكان حسابه.
 *             **عدا المكاتب الإدارية** (HZ-06): الإدارات كلها فيها، فالرؤية هناك بالإدارة لا بالمكان — وإلا رأى فني المكاتب
 *             أو منسق إدارة بلاغَ إدارة أخرى. مدير الفرع مستثنى من هذا الاستثناء: هو فوق إدارات فرعه كلها.
 */
class IncidentVisibilityService
{
    private const MANAGEMENT_ROLES = ['top_management', 'safety_committee', 'branch_manager', 'department_manager', 'section_manager', 'security_safety_head', 'admin_eng_manager', 'facilities_manager']; // قرار ٢٠٢٦-٠٩-٠٨: الثلاثة كمديري إدارات

    private const INSTITUTE_VIEW_ALL = ['top_management', 'safety_committee']; // قرار ٥٥ (٢٠٢٦-٠٩-٢١): فريق الإسناد خرج — لا يرى إلا بلاغاً أُحيل إليه أو بلّغ عنه

    public function canView(Incident $incident, int $userId): bool
    {
        $rule = $this->rule($userId);
        if ($rule === null) return false;
        if ($rule['all'] || $this->involved($incident, $userId)) return true;
        if ($incident->organization_unit_id && in_array((int) $incident->organization_unit_id, $rule['units'], true)) return true;
        return $incident->place_id && in_array((int) $incident->place_id, $rule['places'], true);
    }

    public function getVisibleIncidents(int $userId): Builder
    {
        $rule = $this->rule($userId);
        $query = Incident::query();
        if ($rule === null) return $query->whereRaw('1 = 0');
        if ($rule['all']) return $query;

        return $query->where(function (Builder $q) use ($userId, $rule) {
            $q->where('actor_id', $userId)
                ->orWhere('assigned_to_id', $userId)
                ->orWhere('executor_id', $userId)
                ->orWhere('incident_coordinator_id', $userId)
                ->orWhere('incident_field_team_id', $userId);
            if ($rule['units']) $q->orWhereIn('organization_unit_id', $rule['units']);
            if ($rule['places']) $q->orWhereIn('place_id', $rule['places']);
        });
    }

    /** @return array{all: bool, units: int[], places: int[]}|null null = حساب معطَّل أو بلا ملف */
    private function rule(int $userId): ?array
    {
        $profile = UserProfile::with('user')->where('user_id', $userId)->where('is_active', true)->first();
        if (!$profile) return null;
        $role = $profile->role;

        if (in_array($role, ['system_admin', 'system_staff'], true) || in_array($role, self::INSTITUTE_VIEW_ALL, true)
            || ($role === 'safety_coordinator' && !$profile->organization_unit_id && !$profile->place_id)) {
            return ['all' => true, 'units' => [], 'places' => []];
        }

        $units = ($profile->organization_unit_id && (in_array($role, self::MANAGEMENT_ROLES, true) || $role === 'safety_coordinator'))
            ? array_map('intval', OrganizationUnit::descendantIdsOf($profile->organization_unit_id)) : [];

        $places = [];
        if ($role === 'branch_manager' && $profile->user) {
            $places = ScopeService::forUser($profile->user)->places()->pluck('id')->all(); // أماكن مباني فرعه، ومنها المكاتب
        } else {
            if (PermissionRegistry::isTech($role) && $profile->user) {
                $places = ScopeService::forUser($profile->user)->places()->pluck('id')->all(); // تغطيته، وإلا مكان حسابه
            } elseif ($role === 'safety_coordinator' && $profile->place_id) {
                $places = [$profile->place_id];
            }
            $hub = Place::idByCode(PlaceProfile::HUB);
            $places = array_values(array_filter($places, fn ($id) => (int) $id !== (int) $hub)); // في المكاتب الرؤية بالإدارة لا بالمكان
        }

        return ['all' => false, 'units' => $units, 'places' => array_map('intval', $places)];
    }

    private function involved(Incident $i, int $userId): bool
    {
        return $i->actor_id === $userId || $i->assigned_to_id === $userId || $i->executor_id === $userId
            || $i->incident_coordinator_id === $userId || $i->incident_field_team_id === $userId;
    }
}
