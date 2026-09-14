<?php

namespace App\Modules\Store\Controllers;

use App\Core\Permissions\PermissionRegistry;
use App\Http\Controllers\Controller;
use App\Modules\Store\Services\InspectionWatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * المرحلة ١٤ — «الجولات السابقة» داخل النموذج: سجل السلامة للقراءة فقط، لمن يملك العمل اليومي (كالمخزن).
 */
class InspectionRoundsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (!$this->allowed($request)) return $this->denied();
        $key = (string) $request->query('key', '');
        abort_unless(InspectionWatch::form($key), 422, 'مفتاح غير مسموح');
        $rows = DB::table('inspection_rounds')->where('form_key', $key)
            ->orderByDesc('round_date')->orderByDesc('started_at')
            ->get(['id', 'round_date', 'started_at', 'inspector', 'qualifier', 'freq', 'finished_at', 'closed_at']);
        return response()->json(['rounds' => $rows->map(fn ($r) => [
            'id' => $r->id, 'date' => $r->round_date, 'start' => $r->started_at, 'inspector' => $r->inspector,
            'qualifier' => $r->qualifier, 'freq' => $r->freq, 'finished' => $r->finished_at, 'closed' => $r->closed_at,
        ])->values()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if (!$this->allowed($request)) return $this->denied();
        $r = DB::table('inspection_rounds')->where('id', $id)->first();
        abort_unless($r, 404);
        return response()->json([
            'id' => $r->id, 'form' => $r->form_key, 'date' => $r->round_date, 'start' => $r->started_at,
            'inspector' => $r->inspector, 'qualifier' => $r->qualifier, 'freq' => $r->freq,
            'finished' => $r->finished_at, 'closed' => $r->closed_at,
            'snapshot' => json_decode($r->snapshot, true),
        ]);
    }

    private function allowed(Request $request): bool
    {
        $p = $request->user()->profile;
        return $p && $p->is_active && PermissionRegistry::uiRole($p->role);
    }

    private function denied(): JsonResponse
    {
        return response()->json(['message' => 'حسابك لا يملك صلاحية العمل اليومي (اللوحة ونماذج الفحص).'], 403);
    }
}
