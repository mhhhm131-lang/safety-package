<?php

namespace App\Modules\Governance\Controllers;

use App\Core\Inbox\InboxService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * المرحلة ١١-٢ (قرار ٣٤): الصفحة الأولى بعد الدخول هي «ما ينتظرك الآن» — لا بطاقات روابط.
 * كل بند سؤال وزر؛ ما ليس مهمة يُفتح من القائمة أو البحث (١١-٤).
 */
class HomeController extends Controller
{
    public function index(Request $request, InboxService $inbox): View|\Illuminate\Http\RedirectResponse
    {
        $user = $request->user();
        // المرحلة ٦: حساب الطرف الخارجي يفتح بوابته مباشرة
        if ($user->isContractor()) {
            return redirect()->route('contractor.home');
        }
        return view('governance.inbox', ['tasks' => $inbox->forUser($user)]);
    }
}
