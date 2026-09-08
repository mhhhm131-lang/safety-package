<?php

namespace App\Modules\Incident\Controllers;

use App\Core\Services\AuditLogService;
use App\Http\Controllers\Controller;
use App\Modules\Governance\Models\Setting;
use Illuminate\Http\Request;

/** شاشة الإعدادات: مهل بلاغ الشاغل بالساعات (BACKEND.md ٥-٢-ب ج) — بلا قيم افتراضية. لمسؤول السلامة. */
class IncidentSettingsController extends Controller
{
    public function edit()
    {
        $values = [];
        foreach (Setting::DEADLINE_KEYS as $key => $label) {
            $values[$key] = Setting::get($key);
        }
        return view('modules.incidents.settings', ['values' => $values, 'labels' => Setting::DEADLINE_KEYS]);
    }

    public function update(Request $request, AuditLogService $audit)
    {
        $rules = [];
        foreach (array_keys(Setting::DEADLINE_KEYS) as $key) {
            $rules[str_replace('.', '_', $key)] = ['nullable', 'numeric', 'min:0.25', 'max:720'];
        }
        $v = $request->validate($rules);
        foreach (Setting::DEADLINE_KEYS as $key => $label) {
            Setting::set($key, $v[str_replace('.', '_', $key)] ?? null, $request->user()->id);
        }
        $audit->log($request, 'update', 'Setting', null, 'مهل بلاغ الشاغل: '.json_encode($v, JSON_UNESCAPED_UNICODE));
        return redirect()->route('incidents.settings')->with('success', 'حُفظت المهل. الفارغ = لا مهلة ولا تصعيد آلي.');
    }
}
