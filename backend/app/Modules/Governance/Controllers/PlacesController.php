<?php

namespace App\Modules\Governance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Governance\Models\Place;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** الأماكن التسعة: الرموز ثابتة (SOURCE.md)، الاسم فقط يُعدَّل. */
class PlacesController extends Controller
{
    public function index(): View
    {
        return view('governance.places.index', ['places' => Place::withCount('units')->orderBy('sort')->get()]);
    }

    /** رمز QR لكل مكان (قرار ٢٠٢٦-٠٩-٠٨): يفتح صفحة بلاغ الشاغل والمكان محدد مسبقاً. */
    public function qr(?string $code = null): View
    {
        $q = Place::orderBy('sort');
        if ($code) $q->where('code', $code);
        $places = $q->get();
        abort_if($places->isEmpty(), 404);
        $places->each(fn (Place $p) => $p->qr_url = url('/incident?place='.$p->code));
        return view('governance.places.qr', ['places' => $places]);
    }

    public function update(Request $request, Place $place): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:120']);
        $place->update($data);
        return back()->with('ok', "حُدّث اسم {$place->code}");
    }
}
