<?php

namespace App\Modules\Governance\Controllers;

use App\Core\Inbox\InboxService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** المرحلة ١١-٢ (قرار ٣٤): عدد ما ينتظر المستخدم — للشارة في الشريط. */
class InboxController extends Controller
{
    public function count(Request $request, InboxService $inbox): JsonResponse
    {
        return response()->json(['count' => $inbox->countFor($request->user())]);
    }
}
