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

        // قرار ٨٤: حسابات مباني الحساب (بمبنى حسابها، أو بمكانها إن كانت بلا مبنى)؛ من يرى الكل: الكل
        $bIds = \App\Modules\Governance\Services\BuildingScope::buildingIds();
        $pIds = \App\Modules\Governance\Services\BuildingScope::placeIds();
        return response()->json(User::query()->with('profile')->whereHas('profile', fn ($q) => $q->where('is_active', true)
                ->when($bIds !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('building_id', $bIds)->orWhere(fn ($x) => $x->whereNull('building_id')->whereIn('place_id', $pIds ?? [])))))
            ->orderBy('name')->get()
            ->map(fn (User $u) => ['u' => $u->username, 'n' => $u->name, 'r' => PermissionRegistry::getRoleDisplayName($u->profile->role)])
            ->values());
    }
}
