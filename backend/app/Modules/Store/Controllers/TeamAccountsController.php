<?php

namespace App\Modules\Store\Controllers;

use App\Core\Permissions\PermissionRegistry;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * المرحلة ١٥-٤ (قرار ٤٣): اقتراح الحسابات بجانب الاسم في نافذة ترشيح الفريق الأولي باللوحة.
 * لمن يرشّح أو يعتمد فقط (دور الواجهة safety/adm/dept)؛ يعيد اسم الدخول والاسم والدور، لا غير.
 */
class TeamAccountsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $profile = $request->user()->profile;
        if (!$profile || !$profile->is_active || !in_array(PermissionRegistry::uiRole($profile->role), ['safety', 'adm', 'dept'], true)) {
            return response()->json(['message' => 'اقتراح الحسابات لمن يرشّح الفريق الأولي أو يعتمده'], 403);
        }

        return response()->json(User::query()->with('profile')->whereHas('profile', fn ($q) => $q->where('is_active', true))->orderBy('name')->get()
            ->map(fn (User $u) => ['u' => $u->username, 'n' => $u->name, 'r' => PermissionRegistry::getRoleDisplayName($u->profile->role)])
            ->values());
    }
}
