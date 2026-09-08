<?php

namespace App\Core\Middleware;

use App\Core\Permissions\PermissionRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * permission:<code>[,<code>…] — يمنع الوصول ما لم يكن دور المستخدم (من ملفه) ضمن أصحاب إحدى الصلاحيات المذكورة.
 * منقول من OHSMS بلا tenant وبلا superuser. تعدد الرموز (أيٌّ منها يكفي) أُضيف في المرحلة ٤ للطوارئ
 * (الاطلاع أو الاستجابة الميدانية).
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, string ...$permissionCodes): Response
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

        foreach ($permissionCodes as $code) {
            if (PermissionRegistry::hasPermission($profile->role, $code)) {
                return $next($request);
            }
        }

        abort(403, 'ليس لديك صلاحية للوصول لهذه الصفحة.');
    }
}
