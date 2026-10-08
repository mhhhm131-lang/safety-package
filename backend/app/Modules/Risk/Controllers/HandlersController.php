<?php

namespace App\Modules\Risk\Controllers;

use App\Core\Permissions\PermissionRegistry;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Support\RiskApproval;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * خطة المعالج — الخطوة ١ (معتمدة بكلمته ٢٠٢٦-١٠-٠٨): «معالجو أخطار إدارتي».
 * مسؤول السلامة يعلّق على الخطر في السجل العام «الإدارة المعالجة»؛ ومدير تلك الإدارة يدخل من حسابه («إدارتي»، على مثال «فنيّي»)
 * فيرى الأخطار المعلَّقة على إدارته وما تحتها، ويكتب أمام كل خطر «المعالج»: تخصصاً من الستة، أو شخصاً من إدارته.
 * يكتب هذه الخانة وحدها في العام ولا يمسّ غيرها (Risk::HANDLER_FIELDS). بكلمته ٢٠٢٦-١٠-٠٤: «مديرها يدخل ويكلّف أمام كل خطر فنياً».
 */
class HandlersController extends Controller
{
    private const NOT_MANAGER = 'هذه الشاشة لمدير الإدارة المعالجة: يسمّي من يعالج الأخطار المعلَّقة على إدارته.';

    /** مدير وحدة (قرار ٦٩: الأدوار الستة) مربوط بوحدة — وإلا ٤٠٣ */
    private function unitIds(): array
    {
        $profile = Auth::user()->profile;
        abort_unless($profile && $profile->is_active && in_array($profile->role, RiskApproval::UNIT_MANAGERS, true) && $profile->organization_unit_id, 403, self::NOT_MANAGER);
        return OrganizationUnit::descendantIdsOf($profile->organization_unit_id);
    }

    /** أخطار العام (المعتمدة) التي إدارتها المعالجة وحدة المدير أو ما تحتها */
    private function mine(array $unitIds)
    {
        return Risk::where('risk_type', 'reference')->adopted()->whereIn('handling_unit_id', $unitIds);
    }

    public function index(): View
    {
        $unitIds = $this->unitIds();
        $risks = $this->mine($unitIds)->with(['category', 'subCategory', 'handlingUnit', 'handlerUser', 'handlerSetBy'])
            ->orderBy('category_id')->orderBy('code')->get();
        return view('modules.risks.handlers', [
            'risks' => $risks,
            'unitName' => Auth::user()->profile?->organizationUnit?->name,
            'specialties' => array_intersect_key(PermissionRegistry::ROLES, array_flip(PermissionRegistry::TECH_ROLES)),
            'people' => $this->people($unitIds),
            'missing' => $risks->filter(fn (Risk $r) => !$r->handler_specialty && !$r->handler_user_id)->count(),
        ]);
    }

    /** من يُسمّى شخصاً: حسابات إدارته وما تحتها (أي دور)، ولمدير المرافق فنيّوه في مبناه أيضاً (كما في «فنيّي») */
    private function people(array $unitIds)
    {
        $me = Auth::user();
        $q = User::whereHas('profile', function ($w) use ($unitIds, $me) {
            $w->where('is_active', true)->where(function ($x) use ($unitIds, $me) {
                $x->whereIn('organization_unit_id', $unitIds);
                if ($me->role() === 'facilities_manager' && ($b = $me->profile?->myBuilding()?->id)) {
                    $x->orWhere(fn ($t) => $t->whereIn('role', PermissionRegistry::techRoles())->where('building_id', $b));
                }
            });
        })->with('profile')->where('id', '!=', $me->id)->orderBy('name')->get(['id', 'name']);
        return $q;
    }

    /** الكتابة: خانة «المعالج» وحدها — «spec:<تخصص>» أو «user:<حساب>» أو فارغ (يمحو) */
    public function set(Request $request, int $risk): RedirectResponse
    {
        $unitIds = $this->unitIds();
        $r = $this->mine($unitIds)->findOrFail($risk);
        $people = $this->people($unitIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $v = $request->validate([
            'handler' => ['nullable', 'string', 'max:60', function ($attr, $value, $fail) use ($people) {
                if ($value === null || $value === '') return;
                if (preg_match('/^spec:([a-z_]+)$/', $value, $m)) {
                    if (!in_array($m[1], PermissionRegistry::TECH_ROLES, true)) $fail('التخصص غير معروف.');
                    return;
                }
                if (preg_match('/^user:(\d+)$/', $value, $m)) {
                    if (!in_array((int) $m[1], $people, true)) $fail('هذا الحساب ليس من إدارتك.');
                    return;
                }
                $fail('اختر تخصصاً أو شخصاً.');
            }],
        ]);
        $value = (string) ($v['handler'] ?? '');
        $data = ['handler_specialty' => null, 'handler_user_id' => null, 'handler_set_by_id' => null, 'handler_set_at' => null];
        if ($value !== '') {
            [$kind, $id] = explode(':', $value, 2);
            $data[$kind === 'spec' ? 'handler_specialty' : 'handler_user_id'] = $kind === 'spec' ? $id : (int) $id;
            $data['handler_set_by_id'] = Auth::id();
            $data['handler_set_at'] = now();
        }
        $r->forceFill($data)->save(); // الحقول الأربعة فقط — لا يمسّ غيرها
        $label = $r->fresh()->handler_label;
        return redirect()->route('risk.handlers.index')->with('ok', $label ? "حُفظ: «{$r->title}» يعالجه {$label}." : "مُحي معالج «{$r->title}».");
    }
}
