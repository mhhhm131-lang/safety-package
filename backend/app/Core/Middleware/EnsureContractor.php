<?php

namespace App\Core\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** صفحات المقاول (من OHSMS): للحسابات المربوطة بطرف خارجي بدور مقاول/مشرف مقاول/مكتب استشاري فقط. */
class EnsureContractor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user || !$user->isContractor()) {
            abort(403, 'هذه الصفحة مخصصة للمقاولين فقط.');
        }
        return $next($request);
    }
}
