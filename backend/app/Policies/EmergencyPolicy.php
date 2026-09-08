<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\StateMachines\EmergencyStateMachine;

/**
 * سياسة الحالة الطارئة — لم تكن في OHSMS (خلل مؤكد ٥-٣). الصلاحيات من PermissionRegistry (emergency.*)،
 * والانتقالات من آلة الحالة. الرؤية: كل من يملك emergency.view أو emergency.respond (الفني يرى ليصل).
 */
class EmergencyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can_('emergency.view') || $user->can_('emergency.respond');
    }

    public function view(User $user, EmergencyIncident $incident): bool
    {
        return $this->viewAny($user);
    }

    public function trigger(User $user): bool
    {
        return $user->can_('emergency.trigger');
    }

    /** الاستجابة الميدانية: تسجيل الوصول، الإقرار بالاستلام، تعليم مفقود/موجود، ملاحظة في السجل. */
    public function respond(User $user, EmergencyIncident $incident): bool
    {
        return $incident->isOpen() && ($user->can_('emergency.respond') || $user->can_('emergency.trigger'));
    }

    public function contain(User $user, EmergencyIncident $incident): bool
    {
        return app(EmergencyStateMachine::class)->canUserTransition($incident->status, 'contained', $user->role());
    }

    public function end(User $user, EmergencyIncident $incident): bool
    {
        return app(EmergencyStateMachine::class)->canUserTransition($incident->status, 'ended', $user->role());
    }

    public function reactivate(User $user, EmergencyIncident $incident): bool
    {
        return app(EmergencyStateMachine::class)->canUserTransition($incident->status, 'active', $user->role());
    }

    public function cancel(User $user, EmergencyIncident $incident): bool
    {
        return $incident->isOpen() && app(EmergencyStateMachine::class)->canUserTransition($incident->status, 'cancelled', $user->role());
    }

    public function manage(User $user): bool
    {
        return $user->can_('emergency.manage');
    }

    public function drill(User $user): bool
    {
        return $user->can_('emergency.drill');
    }

    public function equipment(User $user): bool
    {
        return $user->can_('emergency.equipment');
    }

    public function teams(User $user): bool
    {
        return $user->can_('emergency.teams');
    }
}
