<?php

namespace App\Policies;

use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Incident\Models\Incident;
use App\Modules\Incident\Services\IncidentVisibilityService;

/** سياسة بلاغ الشاغل (من OHSMS بلا superuser). الصلاحيات من PermissionRegistry والرؤية من VisibilityService. */
class IncidentPolicy
{
    public function __construct(private IncidentVisibilityService $visibility) {}

    public function viewAny(User $user): bool
    {
        return $this->has($user, 'incident.list');
    }

    public function view(User $user, Incident $incident): bool
    {
        return $this->has($user, 'incident.list') && $this->visibility->canView($incident, $user->id);
    }

    public function create(User $user): bool
    {
        return $this->has($user, 'incident.create');
    }

    /** المعالجة: المركز دائماً، وغيره إن كان معيَّناً على هذا البلاغ. */
    public function manage(User $user, Incident $incident): bool
    {
        if (!$this->has($user, 'incident.manage')) return false;
        $role = $user->role();
        if (in_array($role, ['system_admin', 'system_staff'], true)) return true;
        if ($role === 'safety_committee') return in_array($incident->status, ['escalated_to_manager'], true);
        return $user->id === $incident->assigned_to_id
            || $user->id === $incident->incident_field_team_id
            || $user->id === $incident->incident_coordinator_id;
    }

    public function approveClosure(User $user, Incident $incident): bool
    {
        return $incident->actor_id !== null && $incident->actor_id === $user->id && $incident->pending_closure;
    }

    private function has(User $user, string $code): bool
    {
        $role = $user->role();
        return $role ? PermissionRegistry::hasPermission($role, $code) : false;
    }
}
