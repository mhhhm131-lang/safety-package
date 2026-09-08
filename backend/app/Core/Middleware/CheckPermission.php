<?php

namespace App\Core\Middleware;

use App\Core\Permissions\PermissionRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * permission:<code> — يمنع الوصول ما لم يكن دور المستخدم (من ملفه) ضمن أصحاب الصلاحية.
 * منقول من OHSMS بلا tenant وبلا superuser.
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permissionCode): Response
    {
        $user = $request->user();
        if (!$user) {
            return $request->expectsJson()
                ? response()->json(['message' => 'يلزم تسجيل الدخول'], 401)
                : redirect()->route('login', ['next' => $request->getRequestUri()]);
        }

        $profile = $user->profile;
        if (!$profile || !$profile->is_active) {
            abort(403, 'الحساب غير مفعّل.');
        }

        if (!PermissionRegistry::hasPermission($profile->role, $permissionCode)) {
            abort(403, 'ليس لديك صلاحية للوصول لهذه الصفحة.');
        }

        return $next($request);
    }
}
