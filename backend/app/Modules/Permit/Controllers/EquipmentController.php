<?php

namespace App\Modules\Permit\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Governance\Models\Place;
use App\Modules\Permit\Models\Equipment;
use App\Modules\Permit\Models\EquipmentInspection;
use App\Modules\Permit\Models\Permit;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Project\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * المعدات وفحوصها — موضوع تصاريح تشغيل المعدات والرافعات وعزل الطاقة.
 * الفحص الدوري يضبط تاريخ الفحص القادم آلياً من دورية المعدة.
 */
class EquipmentController extends Controller
{
    public function index(Request $request)
    {
        $query = Equipment::with(['place', 'externalParty', 'project'])->orderBy('name');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('place_id')) {
            $query->where('place_id', (int) $request->query('place_id'));
        }
        if ($request->boolean('overdue')) {
            $query->whereNotNull('next_inspection_date')->whereDate('next_inspection_date', '<', now()->toDateString());
        }
        if ($q = trim((string) $request->query('q'))) {
            $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$q}%")
                ->orWhere('code', 'like', "%{$q}%")
                ->orWhere('serial_number', 'like', "%{$q}%"));
        }

        return view('modules.permits.equipment.index', [
            'equipment' => $query->paginate(30)->withQueryString(),
            'places'    => Place::orderBy('sort')->get(['id', 'code', 'name']),
            'overdue'   => Equipment::whereNotNull('next_inspection_date')
                ->whereDate('next_inspection_date', '<', now()->toDateString())->count(),
        ]);
    }

    public function create()
    {
        return view('modules.permits.equipment.form', [
            'item'     => new Equipment(),
            'places'   => Place::orderBy('sort')->get(['id', 'code', 'name']),
            'projects' => Project::orderBy('name')->get(['id', 'name']),
            'parties'  => ExternalParty::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $item = Equipment::create($this->validated($request) + ['created_by_id' => Auth::id()]);

        return redirect()->route('equipment.show', $item)->with('ok', 'سُجّلت المعدة.');
    }

    public function show(Equipment $equipment)
    {
        $equipment->load(['place', 'project', 'externalParty', 'assignedTo', 'inspections.inspector']);

        $permits = Permit::where('subject_type', Permit::SUBJECT_EQUIPMENT)
            ->where('subject_id', $equipment->id)
            ->with('type')->latest('id')->limit(20)->get();

        return view('modules.permits.equipment.show', compact('equipment', 'permits'));
    }

    public function edit(Equipment $equipment)
    {
        return view('modules.permits.equipment.form', [
            'item'     => $equipment,
            'places'   => Place::orderBy('sort')->get(['id', 'code', 'name']),
            'projects' => Project::orderBy('name')->get(['id', 'name']),
            'parties'  => ExternalParty::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, Equipment $equipment)
    {
        $equipment->update($this->validated($request));

        return redirect()->route('equipment.show', $equipment)->with('ok', 'حُدّثت بيانات المعدة.');
    }

    /** تسجيل فحص: يضبط آخر فحص، ويحسب القادم من الدورية إن لم يُحدَّد. */
    public function storeInspection(Request $request, Equipment $equipment)
    {
        $data = $request->validate([
            'inspection_date' => ['required', 'date'],
            'result'          => ['required', 'in:pass,fail,conditional'],
            'findings'        => ['nullable', 'string', 'max:2000'],
            'next_inspection' => ['nullable', 'date', 'after:inspection_date'],
        ], [], ['inspection_date' => 'تاريخ الفحص', 'result' => 'النتيجة']);

        $next = $data['next_inspection'] ?? ($equipment->inspection_frequency_days
            ? \Illuminate\Support\Carbon::parse($data['inspection_date'])->addDays($equipment->inspection_frequency_days)->toDateString()
            : null);

        EquipmentInspection::create($data + [
            'equipment_id'    => $equipment->id,
            'inspector_id'    => Auth::id(),
            'next_inspection' => $next,
            'created_at'      => now(),
        ]);

        $equipment->update([
            'last_inspection_date' => $data['inspection_date'],
            'next_inspection_date' => $next,
            // فحص غير مطابق يُخرج المعدة من الخدمة حتى تُعالج.
            'status' => $data['result'] === 'fail' ? 'out_of_service' : $equipment->status,
        ]);

        return back()->with('ok', $data['result'] === 'fail'
            ? 'سُجّل الفحص: غير مطابق — أُخرجت المعدة من الخدمة.'
            : 'سُجّل الفحص.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name'                      => ['required', 'string', 'max:200'],
            'code'                      => ['nullable', 'string', 'max:60'],
            'equipment_type'            => ['nullable', 'in:heavy,light,electrical,safety,lifting,other'],
            'serial_number'             => ['nullable', 'string', 'max:120'],
            'manufacturer'              => ['nullable', 'string', 'max:120'],
            'model_number'              => ['nullable', 'string', 'max:120'],
            'status'                    => ['required', 'in:active,maintenance,out_of_service,retired'],
            'place_id'                  => ['nullable', 'integer', 'exists:places,id'],
            'project_id'                => ['nullable', 'integer', 'exists:projects,id'],
            'external_party_id'         => ['nullable', 'integer', 'exists:external_parties,id'],
            'inspection_frequency_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'last_inspection_date'      => ['nullable', 'date'],
            'next_inspection_date'      => ['nullable', 'date'],
            'location'                  => ['nullable', 'string', 'max:200'],
            'notes'                     => ['nullable', 'string', 'max:2000'],
        ], [], ['name' => 'اسم المعدة', 'status' => 'الحالة']);
    }
}
