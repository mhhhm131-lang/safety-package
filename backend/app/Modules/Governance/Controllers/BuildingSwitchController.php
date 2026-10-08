<?php

namespace App\Modules\Governance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Governance\Services\BuildingContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** ٢٨-٣ (قرار ٧٨): مبدّل «المبنى» في الشريط لمن يرى أكثر من مبنى؛ صفحات المعهد تتبع مبنى الجلسة */
class BuildingSwitchController extends Controller
{
    public function __invoke(Request $request, EmergencyBuilding $building): RedirectResponse
    {
        abort_unless(BuildingContext::set($request->user(), $building->id), 403, 'هذا المبنى خارج نطاق حسابك.');
        return back()->with('ok', 'المبنى الآن: '.$building->name);
    }
}
