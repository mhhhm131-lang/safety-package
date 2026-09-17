<?php

namespace App\Modules\Store\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\PlaceUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * المرحلة ١٨-٣ (د) (قرار ٤٧): وحدات المكان لنماذج الفحص — الفني يختار القاعة أو الغرفة برقمها في المحطة الأولى
 * بدل كتابة «الموقع/المنطقة» بيده، فتُسجَّل الجولة وبلاغ الفحص باسم الوحدة. بجلسة المتصفح نفسها كبقية /api.
 */
class PlaceUnitsApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $code = (string) $request->query('place', '');
        $place = Place::where('code', $code)->first();
        if (!$place) return response()->json([]);
        $units = PlaceUnit::where('place_id', $place->id)->where('is_active', true)->orderBy('type')->orderBy('sort')->orderBy('name')->get();
        return response()->json($units->map(fn (PlaceUnit $u) => [
            'id' => $u->id, 'type' => $u->type, 'type_label' => $u->type_label, 'name' => $u->name,
            'label' => trim($u->type_label.' '.$u->name.($u->floor ? ' · الدور '.$u->floor : '').($u->location ? ' · '.$u->location : '')),
        ])->values());
    }
}
