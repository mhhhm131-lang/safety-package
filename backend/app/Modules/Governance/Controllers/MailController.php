<?php

namespace App\Modules\Governance\Controllers;

use App\Core\Services\MailHealthService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * شاشة البريد (المرحلة ٨-٢): هل القناة الثانية تعمل فعلاً؟
 *
 * يتحقق منها مسؤول السلامة بنفسه بلا مطوّر — وهذا المقصود: القناة كانت معطّلة صامتة
 * ولا أحد يعلم إلا حين لا يصل بلاغ.
 */
class MailController extends Controller
{
    public function __construct(private readonly MailHealthService $mail) {}

    public function index()
    {
        return view('modules.governance.mail', [
            'status'  => $this->mail->status(),
            'default' => Auth::user()?->email,
        ]);
    }

    public function test(Request $request)
    {
        $data = $request->validate(
            ['to' => ['required', 'email']],
            [],
            ['to' => 'البريد المستقبِل'],
        );

        [$ok, $message] = $this->mail->sendTest($data['to'], Auth::id());

        return back()->with($ok ? 'success' : 'error', $message);
    }
}
