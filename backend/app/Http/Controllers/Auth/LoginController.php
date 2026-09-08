<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * الدخول الموحّد (المرحلة ٠). يحل محل شاشات الدخول التجريبية في الواجهة.
 * الحسابات في جدول users باسم دخول ودور. ٥ محاولات في الدقيقة لكل اسم وعنوان.
 */
class LoginController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect($this->safeNext($request->query('next')));
        }
        return view('auth.login', ['next' => $request->query('next', '/dashboard.html')]);
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => 'required|string|max:64',
            'password' => 'required|string',
        ]);
        $username = Str::lower(trim($data['username']));
        $throttleKey = $username.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $sec = RateLimiter::availableIn($throttleKey);
            return back()->withInput(['username' => $username])
                ->withErrors(['username' => "محاولات كثيرة — حاول بعد {$sec} ثانية"]);
        }

        $ok = Auth::attempt(['username' => $username, 'password' => $data['password']], remember: true);

        if (!$ok) {
            RateLimiter::hit($throttleKey, 60);
            return back()->withInput(['username' => $username])
                ->withErrors(['username' => 'اسم المستخدم أو كلمة المرور غير صحيحة']);
        }

        // الحساب المعطّل لا يدخل (في OHSMS is_active لا يمنع الدخول — عندنا يمنع)
        if (!Auth::user()->isActive()) {
            Auth::logout();
            RateLimiter::hit($throttleKey, 60);
            return back()->withInput(['username' => $username])
                ->withErrors(['username' => 'هذا الحساب معطّل — راجع مسؤول السلامة']);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();
        app('audit.logger')->log($request, 'login', 'User', Auth::id(), "دخول {$username}", Auth::id());

        return redirect($this->safeNext($request->input('next')));
    }

    public function logout(Request $request): RedirectResponse
    {
        if ($u = Auth::user()) {
            app('audit.logger')->log($request, 'logout', 'User', $u->id, "خروج {$u->username}", $u->id);
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/login');
    }

    /** لا نعيد التوجيه إلا داخل الموقع نفسه. */
    private function safeNext(?string $next): string
    {
        $next = (string) $next;
        if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//')) {
            return '/dashboard.html';
        }
        return $next;
    }
}
