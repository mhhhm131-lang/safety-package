<?php

namespace App\Modules\Governance\Controllers;

use App\Core\Permissions\PermissionRegistry;
use App\Http\Controllers\Controller;
use App\Modules\Emergency\Support\RoleCards;
use App\Modules\Governance\Models\UserProfile;
use Illuminate\View\View;

/**
 * المرحلة ٢٠-٦ (قرار ٥١): «الأدوار والبطاقات» — صفحة قراءة لأي حساب: الأدوار الـ٢٦ وموضع كلٍّ في بطاقات السلامة الـ٢١،
 * وعدد صلاحياته وعدد الحسابات به؛ والبطاقات الـ٢١ وحامل كلٍّ. الجدول في الكود (القرار: الصلاحيات تبقى في الكود وتُعرض للقراءة).
 */
class RolesController extends Controller
{
    public function index(): View
    {
        $counts = UserProfile::where('is_active', true)->selectRaw('role, count(*) as c')->groupBy('role')->pluck('c', 'role');
        $roles = [];
        foreach (RoleCards::rolesPlacement() as $key => $p) {
            $roles[$key] = $p + ['name' => PermissionRegistry::ROLES[$key], 'perms' => count(PermissionRegistry::getRolePermissions($key)),
                'ui' => PermissionRegistry::uiRole($key), 'accounts' => (int) ($counts[$key] ?? 0)];
        }
        return view('governance.roles', ['roles' => $roles, 'cards' => RoleCards::holders(), 'legacy' => PermissionRegistry::LEGACY_ROLES,
            'legacyCounts' => array_intersect_key($counts->all(), array_flip(PermissionRegistry::LEGACY_ROLES)), 'total' => count(PermissionRegistry::PERMISSIONS)]);
    }
}
