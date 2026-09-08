<?php

namespace App\Policies;

use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Risk\Models\Risk;

/** سياسة المخاطر (من OHSMS بلا tenant). الكتاب (master) لا يعدّله إلا مسؤول السلامة والمناوب. */
class RiskPolicy
{
    public function viewAny(User $user): bool
    {
        return PermissionRegistry::hasPermission($user->role(), 'risk.list');
    }

    public function view(User $user, Risk $risk): bool
    {
        return PermissionRegistry::hasPermission($user->role(), 'risk.list');
    }

    public function create(User $user): bool
    {
        return PermissionRegistry::hasPermission($user->role(), 'risk.create');
    }

    public function update(User $user, Risk $risk): bool
    {
        if ($risk->risk_type === 'master') {
            return in_array($user->role(), ['system_admin', 'system_staff'], true);
        }
        if ($risk->risk_type === 'active') {
            return PermissionRegistry::hasPermission($user->role(), 'risk.activate');
        }
        return PermissionRegistry::hasPermission($user->role(), 'risk.create');
    }

    public function approve(User $user, Risk $risk): bool
    {
        return PermissionRegistry::hasPermission($user->role(), 'risk.approve');
    }

    public function delete(User $user, Risk $risk): bool
    {
        return $user->role() === 'system_admin';
    }
}
