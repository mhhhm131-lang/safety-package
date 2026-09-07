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

        $ok = Auth::attempt([
            'username' => $username,
            'password' => $data['password'],
            'is_active' => true,
        ], remember: true);

        if (!$ok) {
            RateLimiter::hit($throttleKey, 60);
            return back()->withInput(['username' => $username])
                ->withErrors(['username' => 'اسم المستخدم أو كلمة المرور غير صحيحة']);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        return redirect($this->safeNext($request->input('next')));
    }

    public function logout(Request $request): RedirectResponse
    {
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
