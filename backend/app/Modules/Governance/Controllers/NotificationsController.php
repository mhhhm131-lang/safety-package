<?php

namespace App\Modules\Governance\Controllers;

use App\Core\Services\NotificationService;
use App\Http\Controllers\Controller;
use App\Modules\Governance\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** صندوق الوارد داخل النظام (من OHSMS). */
class NotificationsController extends Controller
{
    public function __construct(private NotificationService $svc) {}

    public function index(Request $request): View
    {
        $notifications = AppNotification::where('user_id', $request->user()->id)->latest('id')->paginate(30);
        return view('governance.notifications.index', compact('notifications'));
    }

    public function count(Request $request): JsonResponse
    {
        return response()->json(['count' => $this->svc->getUnreadCount($request->user()->id)]);
    }

    public function markRead(Request $request, AppNotification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);
        $notification->update(['is_read' => true]);
        return response()->json(['ok' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        return response()->json(['ok' => true, 'updated' => $this->svc->markAllRead($request->user()->id)]);
    }
}
