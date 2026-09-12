<?php

namespace App\Modules\Governance\Controllers;

use App\Core\Inbox\InboxService;
use App\Http\Controllers\Controller;
use App\Modules\Governance\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** المرحلة ١١-٢ (قرار ٣٤): عدد ما ينتظر المستخدم — للشارة في الشريط. */
class InboxController extends Controller
{
    public function count(Request $request, InboxService $inbox): JsonResponse
    {
        return response()->json(['count' => $inbox->countFor($request->user())]);
    }

    /**
     * المرحلة ١١-٥: فتح مهمة من الصندوق يعلّم إشعاراتها مقروءةً (الإشعار حدث، والمهمة حالة — لا يبقى الإشعار «جديداً» بعد الفعل).
     * يقبل مسارات هذا الخادم فقط ثم يحوّل إليها.
     */
    public function open(Request $request)
    {
        $url = (string) $request->query('url', '');
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $host = parse_url($url, PHP_URL_HOST);
        if ($path === '' || ($host && $host !== $request->getHost())) return redirect()->route('app.home');
        $target = $path.(($q = parse_url($url, PHP_URL_QUERY)) ? '?'.$q : '').(($f = parse_url($url, PHP_URL_FRAGMENT)) ? '#'.$f : '');
        AppNotification::where('user_id', $request->user()->id)->where('is_read', false)
            ->where(fn ($w) => $w->where('url', $path)->orWhere('url', $target)->orWhere('url', 'like', $path.'%'))
            ->update(['is_read' => true]);
        return redirect($target);
    }
}
