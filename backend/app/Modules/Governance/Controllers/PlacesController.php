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

    public function update(Request $request, Place $place): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:120']);
        $place->update($data);
        return back()->with('ok', "حُدّث اسم {$place->code}");
    }
}
