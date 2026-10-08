<?php

namespace App\Modules\Risk\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Support\BranchHandling;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * قرار ٨٠ (بكلمته «موافق» ٢٠٢٦-١٠-٠٨): تبديل الإدارة المعالجة في نسخة الفرع —
 * يقترحه منسق سلامة الفرع (حسابه على وحدة الفرع) ويعتمده مدير الفرع بضغطة؛ ومسؤول السلامة يبدّل مباشرة.
 * لا يُمسّ العام. تبديل غير معتمد لا يوجّه بلاغاً.
 */
class HandlingOverrideController extends Controller
{
    public function set(Request $request, int $risk): RedirectResponse
    {
        $r = Risk::where('risk_type', 'active')->findOrFail($risk);
        $user = Auth::user();
        abort_unless(BranchHandling::canPropose($user, $r), 403, 'تبديل الإدارة المعالجة في الفرع لمنسق سلامة الفرع ومسؤول السلامة.');
        $allowed = BranchHandling::unitChoices($r)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $v = $request->validate(['handling_unit_id' => ['nullable', 'integer', function ($a, $value, $fail) use ($allowed) {
            if ($value !== null && $value !== '' && !in_array((int) $value, $allowed, true)) $fail('الإدارة من هيكل هذا الفرع.');
        }]]);
        $unit = !empty($v['handling_unit_id']) ? OrganizationUnit::find((int) $v['handling_unit_id']) : null;
        if (!$unit) {
            $r->forceFill(['handling_unit_id' => null, 'handling_unit_name' => null, 'handling_override_by_id' => null, 'handling_override_at' => null,
                'handling_override_approved_by_id' => null, 'handling_override_approved_at' => null])->save();
            return back()->with('ok', 'أُلغي بديل الفرع — تعود الإدارة المعالجة إلى افتراض السجل العام.');
        }
        $direct = BranchHandling::canApprove($user, $r); // مسؤول السلامة أو مدير الفرع: يبدّل ويعتمد في ضغطة
        $r->forceFill(['handling_unit_id' => $unit->id, 'handling_unit_name' => $unit->name, 'handling_override_by_id' => $user->id, 'handling_override_at' => now(),
            'handling_override_approved_by_id' => $direct ? $user->id : null, 'handling_override_approved_at' => $direct ? now() : null])->save();
        if (!$direct) BranchHandling::notifyApprovers($r, $user);
        return back()->with('ok', $direct ? "الإدارة المعالجة في هذا الفرع: «{$unit->name}»." : "اقتُرح «{$unit->name}» إدارةً معالجة لهذا الفرع — ينتظر اعتماد مدير الفرع.");
    }

    public function approve(int $risk): RedirectResponse
    {
        $r = Risk::where('risk_type', 'active')->findOrFail($risk);
        $user = Auth::user();
        abort_unless(BranchHandling::canApprove($user, $r), 403, 'اعتماد بديل الفرع لمدير الفرع ومسؤول السلامة.');
        abort_unless($r->handling_override_state === 'pending', 422, 'لا بديل ينتظر الاعتماد.');
        $r->forceFill(['handling_override_approved_by_id' => $user->id, 'handling_override_approved_at' => now()])->save();
        return back()->with('ok', 'اعتُمد: الإدارة المعالجة في هذا الفرع «'.$r->handling_unit_name.'».');
    }
}
