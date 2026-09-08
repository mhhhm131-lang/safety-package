<?php

namespace App\Modules\Governance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Services\DeptSync;
use App\Modules\Store\Controllers\StoreController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** الهيكل التنظيمي — الجدول هو الأصل، واللوحة تقرأ ipa-depts المشتق منه. */
class OrgUnitsController extends Controller
{
    public function __construct(private DeptSync $sync) {}

    public function index(): View
    {
        $units = OrganizationUnit::with(['place', 'manager'])->orderBy('order')->get();
        $tree = $this->tree($units, null, 0);
        return view('governance.org.index', ['tree' => $tree, 'count' => $units->where('is_active', true)->count()]);
    }

    public function create(): View
    {
        return view('governance.org.form', $this->formData(null));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        $data['code'] = !empty($data['code']) ? Str::lower($data['code']) : 'u'.Str::lower(Str::random(6));
        $data['order'] = (OrganizationUnit::max('order') ?? 0) + 1;
        OrganizationUnit::create($data);
        StoreController::refreshDeptsDocument($this->sync);
        return redirect()->route('app.org.index')->with('ok', "أُضيفت «{$data['name']}»");
    }

    public function edit(OrganizationUnit $unit): View
    {
        return view('governance.org.form', $this->formData($unit));
    }

    public function update(Request $request, OrganizationUnit $unit): RedirectResponse
    {
        $data = $this->validated($request, $unit);
        if (!empty($data['parent_id']) && in_array((int) $data['parent_id'], $unit->descendantIds(), true)) {
            return back()->withInput()->with('err', 'لا تجعل الوحدة تابعة لنفسها أو لأحد أقسامها');
        }
        unset($data['code']);
        $unit->update($data);
        StoreController::refreshDeptsDocument($this->sync);
        return redirect()->route('app.org.index')->with('ok', "حُدّثت «{$unit->name}»");
    }

    public function destroy(OrganizationUnit $unit): RedirectResponse
    {
        if ($unit->children()->exists()) {
            return back()->with('err', 'لهذه الوحدة أقسام تابعة — انقلها أولاً');
        }
        if ($unit->profiles()->exists()) {
            return back()->with('err', 'لهذه الوحدة موظفون مرتبطون — انقلهم أولاً');
        }
        $name = $unit->name;
        $unit->delete();
        StoreController::refreshDeptsDocument($this->sync);
        return redirect()->route('app.org.index')->with('ok', "حُذفت «{$name}»");
    }

    private function tree($units, ?int $parentId, int $level): array
    {
        $out = [];
        foreach ($units->where('parent_id', $parentId) as $u) {
            $out[] = ['unit' => $u, 'level' => $level];
            $out = array_merge($out, $this->tree($units, $u->id, $level + 1));
        }
        return $out;
    }

    private function formData(?OrganizationUnit $unit): array
    {
        $exclude = $unit ? $unit->descendantIds() : [];
        return [
            'unit' => $unit,
            'parents' => OrganizationUnit::whereNotIn('id', $exclude)->orderBy('order')->get(),
            'places' => Place::orderBy('sort')->get(),
            'types' => ['company' => 'المدير العام', 'branch' => 'نائب / فرع', 'department' => 'إدارة', 'section' => 'قسم', 'team' => 'فريق'],
        ];
    }

    private function validated(Request $request, ?OrganizationUnit $unit): array
    {
        return $request->validate([
            'code' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/i', Rule::unique('organization_units', 'code')->ignore($unit?->id)],
            'name' => 'required|string|max:200',
            'unit_type' => ['required', Rule::in(OrganizationUnit::TYPES)],
            'parent_id' => 'nullable|exists:organization_units,id',
            'place_id' => 'nullable|exists:places,id',
            'manager_name' => 'nullable|string|max:200',
            'is_active' => 'nullable|boolean',
        ]);
    }
}
