<?php

namespace App\Modules\Governance\Controllers;

use App\Core\Permissions\PermissionRegistry;
use App\Http\Controllers\Controller;
use App\Modules\Emergency\Models\EmergencyTeam;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\PlaceUnit;
use App\Modules\Incident\Models\Incident;
use App\Modules\Store\Models\InstituteDocument;
use App\Modules\Store\Services\InspectionDocReader as R;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * المرحلة ١٩-١ (قرار ٤٨): ملف المكان في الخلفية — ما كان يعرضه `renderPlace` في dashboard.html:676-708 بالبيانات نفسها
 * (وثائق النماذج + ipa-place + الجداول)، ومعه ما لم يكن فيه: الوحدات، والخطتان تفتحان، والفريق بهواتفه.
 * قراءة فقط؛ التحرير في مواضعه (الوحدات في شاشتها، الفحص في النموذج، الترشيح في اللوحة حتى ١٩-٥).
 */
class PlaceFileController extends Controller
{
    public function show(Place $place): View
    {
        $user = Auth::user();
        $hz = $place->code;
        $systems = R::systemsOf($hz);
        $reports = R::reportsOf($hz);
        $open = array_values(array_filter($reports, fn ($r) => !R::isClosed($r)));
        usort($open, fn ($a, $b) => (R::overdueHours($b) ?? -INF) <=> (R::overdueHours($a) ?? -INF));

        $kpi = [
            'open' => count($open),
            'overdue' => count(array_filter($open, fn ($r) => (R::overdueHours($r) ?? -1) >= 0)),
            'cat-a' => count(array_filter($open, fn ($r) => ($r['imp'] ?? '') === 'none')),
            'closed' => count(array_filter($reports, fn ($r) => R::isClosed($r))),
        ];
        $sum = [
            'ok' => count(array_filter($systems, fn ($s) => $s['st'] === 'ok')),
            'late' => count(array_filter($systems, fn ($s) => in_array($s['st'], ['late', 'none'], true))),
            'fault' => count(array_filter($systems, fn ($s) => $s['st'] === 'fault')),
        ];

        // الجاهزية: تواريخ الخطتين من ملف المكان في اللوحة (ipa-place) — تبقى هناك حتى ١٩-٥
        $raw = InstituteDocument::where('key', 'ipa-place')->value('data');
        $pl = (array) ((json_decode((string) $raw, true)[$hz] ?? [])['plans'] ?? []);
        $drillDays = !empty($pl['drill']) && ($t = \DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) $pl['drill'], 0, 10))) ? (int) $t->diff(now())->days : null;
        $folder = '/'.(Place::FOLDERS[$hz] ?? $hz);
        $plans = [
            ['label' => 'خطة السلامة', 'url' => "$folder/safety-plan.html", 'cls' => !empty($pl['sa']) ? 'ok' : 'bad',
                'v' => !empty($pl['sa']) ? 'معتمدة' : 'غير معتمدة', 'd' => !empty($pl['sa']) ? 'بتاريخ '.$pl['sa'].(!empty($pl['saBy']) ? ' · '.$pl['saBy'] : '') : 'تُعتمد بقرار الإدارة العليا'],
        ];
        if ($hz !== 'HZ-00') {
            $plans[] = ['label' => 'خطة الاستجابة', 'url' => "$folder/response-plan.html",
                'cls' => empty($pl['ra']) ? 'bad' : (($drillDays === null || $drillDays > 365) ? 'warn' : 'ok'),
                'v' => empty($pl['ra']) ? 'غير معتمدة' : ($drillDays === null ? 'معتمدة — بلا تمرين' : ($drillDays > 365 ? 'معتمدة — التمرين قديم' : 'معتمدة ومُمرَّنة')),
                'd' => (!empty($pl['ra']) ? 'اعتُمدت '.$pl['ra'] : '').(!empty($pl['drill']) ? ' · آخر تمرين '.$pl['drill'] : ' · لم يُنفَّذ تمرين')];
        }

        $ui = PermissionRegistry::uiRole($user->role());
        return view('governance.places.file', [
            'place' => $place, 'forms' => R::formsOf($hz), 'systems' => $systems, 'open' => $open, 'kpi' => $kpi, 'sum' => $sum, 'plans' => $plans,
            'units' => PlaceUnit::where('place_id', $place->id)->where('is_active', true)->orderBy('type')->orderBy('sort')->orderBy('name')->get(),
            'canUnits' => PlaceUnit::canManageAny($user, $place),
            'teams' => EmergencyTeam::where('place_id', $place->id)->where('is_active', true)->with('members')->orderBy('id')->get(),
            'incidents' => Incident::where('place_id', $place->id)->whereNotIn('status', Incident::TERMINAL)->with('placeUnit')->orderByDesc('id')->limit(20)->get(),
            'canIncidents' => PermissionRegistry::hasPermission($user->role(), 'incident.list'),
            'canRisks' => PermissionRegistry::hasPermission($user->role(), 'risk.list'),
            'ui' => $ui,
        ]);
    }
}
