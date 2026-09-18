<?php

namespace App\Modules\Governance\Controllers;

use App\Core\Permissions\PermissionRegistry;
use App\Http\Controllers\Controller;
use App\Modules\Emergency\Services\PlaceProfile as P;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\PlaceUnit;
use App\Modules\Incident\Models\Incident;
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
    /** المرحلة ١٩-٢: ملف النظام — ما كان يعرضه `renderSystem` في dashboard.html:715-740. */
    public function system(Place $place, string $form, string $sys): View
    {
        $s = R::systemOf($place->code, $form, $sys);
        abort_unless($s, 404, 'لا نظام بهذا الرمز في نماذج هذا المكان.');
        return view('governance.places.system', ['place' => $place, 's' => $s,
            'ui' => PermissionRegistry::uiRole(Auth::user()->role())]);
    }

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

        // الجاهزية: الخطتان والفريق من ملف المكان (ipa-place) — ١٩-٥: تُحرَّر من هنا عبر PlaceTeamController
        $profile = P::get($hz);
        $pl = $profile['plans'];
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

        foreach ($plans as $i => $c) $plans[$i]['key'] = $i ? 'ra' : 'sa';
        $inspOk = count(array_filter($systems, fn ($s) => $s['st'] === 'ok'));
        $plans[] = ['key' => 'insp', 'label' => 'نماذج الفحص', 'url' => null, 'cls' => !count($systems) ? 'bad' : ($inspOk === count($systems) ? 'ok' : 'warn'),
            'v' => !count($systems) ? 'لم تُفعَّل' : $inspOk.' من '.count($systems).' في موعده', 'd' => count($systems) ? 'تُحسب تلقائياً من سجل الجولات' : 'افتح النموذج وسجّل أول جولة'];
        [$units, $teamCard] = $this->teams($user, $hz, $profile);
        $events = $hz === P::HALLS ? $this->events($profile) : null;
        $plans[] = ['key' => 'team', 'url' => null] + ($events ? $events['card'] : $teamCard);

        $ui = PermissionRegistry::uiRole($user->role());
        return view('governance.places.file', [
            'place' => $place, 'forms' => R::formsOf($hz), 'systems' => $systems, 'open' => $open, 'kpi' => $kpi, 'sum' => $sum, 'plans' => $plans,
            'units' => PlaceUnit::where('place_id', $place->id)->where('is_active', true)->orderBy('type')->orderBy('sort')->orderBy('name')->get(),
            'canUnits' => PlaceUnit::canManageAny($user, $place),
            'teamUnits' => $units, 'events' => $events, 'pl' => $pl,
            'can' => ['plans' => P::canPlans($user), 'events' => P::canEvents($user), 'eventApprove' => P::canApproveEvent($user)],
            'incidents' => Incident::where('place_id', $place->id)->whereNotIn('status', Incident::TERMINAL)->with('placeUnit')->orderByDesc('id')->limit(20)->get(),
            'canIncidents' => PermissionRegistry::hasPermission($user->role(), 'incident.list'),
            'canRisks' => PermissionRegistry::hasPermission($user->role(), 'risk.list'),
            'ui' => $ui,
        ]);
    }

    /** ١٩-٥: وحدات الفريق بفرقها وحالاتها وأزرارها، وبطاقة الجاهزية الرابعة (dashboard.html:925-945 readiness) */
    private function teams($user, string $hz, array $profile): array
    {
        $rows = []; $states = [];
        foreach (P::unitList($hz, $profile) as $un) {
            $u = P::unit($profile, $un['uid']);
            $need = P::teamsNeeded($u); $n = P::teamCount($u); $canUnit = P::canUnit($user, $un);
            $teams = [];
            for ($k = 0; $k < $n; $k++) {
                $t = P::teamPeek($u, $k); $st = P::teamState($t);
                if ($k < $need) $states[] = $st;
                $teams[] = ['k' => $k, 'st' => $st, 't' => $t, 'extra' => $k >= $need, 'named' => P::named($t['team']),
                    'edit' => $canUnit ? ($st === 'none' ? 'تسجيل الترشيح' : 'تعديل') : null,
                    'approve' => $st === 'nom' && P::canApprove($user), 'refer' => $st === 'appr' && P::canPlans($user)];
            }
            $rows[] = ['un' => $un, 'staff' => (int) ($u['staff'] ?? 0), 'need' => $need, 'state' => P::unitStateAll($u), 'can' => $canUnit, 'teams' => $teams];
        }
        $need = count($states); $byDept = $need === count($rows);
        $okN = count(array_filter($states, fn ($s) => in_array($s, ['appr', 'hr'], true)));
        $nomN = count(array_filter($states, fn ($s) => $s === 'nom'));
        if ($hz === P::HUB || count($rows) > 1 || $need > 1) {
            $card = ['label' => 'الفرق الأولية', 'cls' => !$rows ? 'bad' : ($okN === $need ? 'ok' : ($okN || $nomN ? 'warn' : 'bad')),
                'v' => $byDept ? $okN.' من '.count($rows).' إدارة لها فريق معتمد' : $okN.' من '.$need.' فريق معتمد',
                'd' => ($nomN ? $nomN.' مرشَّح بانتظار الاعتماد · ' : '').($byDept ? 'فريق لكل إدارة تشغل المكان' : 'فريق لكل '.P::PER_TEAM.' موظفاً')];
        } else {
            $t = $rows[0]['teams'][0];
            $card = ['label' => 'الفريق الأولي', 'cls' => P::UST[$t['st']][0], 'v' => P::UST[$t['st']][1],
                'd' => $t['named'].' من ٤ أدوار بأسماء'.(!empty($t['t']['nom']['by']) ? ' · ترشيح: '.$t['t']['nom']['by'] : '')];
        }
        return [$rows, $card];
    }

    /** ١٩-٥: فرق فعاليات القاعات — القادمة ثم المنتهية، والبطاقة (dashboard.html:1007-1027) */
    private function events(array $profile): array
    {
        $today = now()->toDateString();
        $up = []; $past = [];
        foreach (P::events($profile) as $i => $e) {
            $isPast = !empty($e['date']) && $e['date'] < $today;
            $st = !empty($e['appr']['date']) ? ['ok', 'معتمد'] : (!empty($e['nom']['date']) ? ['warn', 'مرشَّح — بانتظار اعتماد رئيس الأمن والسلامة'] : ['bad', 'لم يُرشَّح']);
            $row = ['i' => $i, 'e' => $e, 'past' => $isPast, 'st' => $st, 'named' => P::named($e['team'])];
            if ($isPast) $past[] = $row; else $up[] = $row;
        }
        $ok = count(array_filter($up, fn ($r) => !empty($r['e']['appr']['date'])));
        return ['up' => $up, 'past' => $past, 'card' => ['label' => 'فرق الفعاليات', 'cls' => !$up ? 'warn' : ($ok === count($up) ? 'ok' : 'warn'),
            'v' => !$up ? 'لا فعالية قادمة مسجّلة' : $ok.' من '.count($up).' فعالية قادمة بفريق معتمد', 'd' => 'المحاضر منسق ومسعف لقاعته · الأمن المكلّف منقذ وإطفائي']];
    }
}
