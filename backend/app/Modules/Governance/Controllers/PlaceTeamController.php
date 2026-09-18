<?php

namespace App\Modules\Governance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Emergency\Services\PlaceProfile as P;
use App\Modules\Governance\Models\Place;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * المرحلة ١٩-٥ (قرار ٤٨): الفريق الأولي والخطتان وفرق الفعاليات من ملف المكان في الخلفية —
 * ما كان في نوافذ اللوحة (dashboard.html:947-1086). الكتابة في وثيقة ipa-place نفسها عبر PlaceProfile، والصلاحية هنا في الخادم.
 */
class PlaceTeamController extends Controller
{
    public function __construct(private P $profile) {}

    /** الوحدة من قائمة وحدات المكان (لا وحدة مخترعة من الرابط) */
    private function unitOf(Place $place, string $uid): array
    {
        foreach (P::unitList($place->code, P::get($place->code)) as $un) {
            if ($un['uid'] === $uid) return $un;
        }
        abort(404, 'لا وحدة بهذا الرمز في هذا المكان.');
    }

    private function back(Place $place, string $anchor, string $msg): RedirectResponse
    {
        return redirect(route('app.places.units.file', $place, false).'#'.$anchor)->with('ok', $msg);
    }

    public function edit(Request $request, Place $place, string $uid, int $k): View
    {
        $un = $this->unitOf($place, $uid);
        abort_unless(P::canUnit($request->user(), $un), 403, 'ترشيح الفريق لمدير الإدارة ومسؤول السلامة ومدير الشؤون الإدارية والهندسية.');
        abort_if($place->code === P::HALLS, 404);
        $unit = P::unit(P::get($place->code), $uid);
        return view('governance.places.team', ['place' => $place, 'un' => $un, 'k' => $k, 'unit' => $unit,
            't' => P::teamPeek($unit, $k), 'n' => max(P::teamCount($unit), $k + 1)]);
    }

    public function save(Request $request, Place $place, string $uid, int $k): RedirectResponse
    {
        $un = $this->unitOf($place, $uid);
        abort_unless(P::canUnit($request->user(), $un), 403);
        abort_if($place->code === P::HALLS || $k > 19, 404);
        $in = $request->validate([
            'nom_by' => 'nullable|string|max:120', 'nom_date' => 'nullable|date_format:Y-m-d', 'staff' => 'nullable',
            'team' => 'array|max:4', 'team.*' => 'array', 'team.*.*' => 'nullable|string|max:120',
            'team.*.trained' => 'nullable|date_format:Y-m-d',
        ]);
        $this->profile->saveTeam($place->code, $un, $k, $in, $request->user()->id);
        return $this->back($place, 'pfTeams', 'حُفظ الترشيح — يظهر الآن لمدير الشؤون الإدارية والهندسية لاعتماده.');
    }

    public function staff(Request $request, Place $place, string $uid): RedirectResponse
    {
        $un = $this->unitOf($place, $uid);
        abort_unless(P::canUnit($request->user(), $un), 403);
        $in = $request->validate(['staff' => 'nullable']);
        $this->profile->saveStaff($place->code, $un, $in['staff'] ?? '', $request->user()->id);
        return $this->back($place, 'pfTeams', 'حُفظ عدد الموظفين.');
    }

    public function approve(Request $request, Place $place, string $uid, int $k): RedirectResponse
    {
        abort_unless(P::canApprove($request->user()), 403, 'اعتماد الفريق لمدير الشؤون الإدارية والهندسية.');
        $this->profile->stamp($place->code, $this->unitOf($place, $uid), $k, 'appr', $request->user()->id);
        return $this->back($place, 'pfTeams', 'اعتُمد الفريق.');
    }

    public function refer(Request $request, Place $place, string $uid, int $k): RedirectResponse
    {
        abort_unless(P::canPlans($request->user()), 403, 'تسجيل الإحالة لمسؤول السلامة ومدير الشؤون الإدارية والهندسية.');
        $this->profile->stamp($place->code, $this->unitOf($place, $uid), $k, 'hr', $request->user()->id);
        return $this->back($place, 'pfTeams', 'سُجّلت الإحالة إلى الموارد البشرية بتاريخ اليوم.');
    }

    public function plans(Request $request, Place $place): RedirectResponse
    {
        abort_unless(P::canPlans($request->user()), 403, 'تحرير الخطتين لمسؤول السلامة ومدير الشؤون الإدارية والهندسية.');
        $in = $request->validate(['sa' => 'nullable|date_format:Y-m-d', 'sa_by' => 'nullable|string|max:120', 'ra' => 'nullable|date_format:Y-m-d', 'drill' => 'nullable|date_format:Y-m-d']);
        $this->profile->savePlans($place->code, $in, $request->user()->id);
        return $this->back($place, 'pfPlans', 'حُفظت تواريخ الخطتين.');
    }

    // ── فرق الفعاليات (القاعات وحدها) ──

    private function eventIndex(Place $place, string $i): ?int
    {
        abort_unless($place->code === P::HALLS, 404);
        if ($i === 'new') return null;
        abort_unless(ctype_digit($i), 404);
        return (int) $i;
    }

    public function eventEdit(Request $request, Place $place, string $i): View
    {
        $idx = $this->eventIndex($place, $i);
        abort_unless(P::canEvents($request->user()), 403, 'فرق الفعاليات لإدارة القاعات ومسؤول السلامة ومدير الشؤون الإدارية والهندسية.');
        $ev = P::events(P::get($place->code));
        abort_if($idx !== null && !isset($ev[$idx]), 404);
        return view('governance.places.event', ['place' => $place, 'i' => $i,
            'e' => $idx !== null ? $ev[$idx] : ['name' => '', 'date' => now()->toDateString(), 'team' => [], 'nom' => [], 'appr' => []]]);
    }

    public function eventSave(Request $request, Place $place, string $i): RedirectResponse
    {
        $idx = $this->eventIndex($place, $i);
        abort_unless(P::canEvents($request->user()), 403);
        $in = $request->validate(['name' => 'required|string|max:160', 'date' => 'nullable|date_format:Y-m-d', 'by' => 'nullable|string|max:120',
            'team' => 'array|max:4', 'team.*' => 'array', 'team.*.*' => 'nullable|string|max:120']);
        $this->profile->saveEvent($place->code, $idx, $in, $request->user()->id);
        return $this->back($place, 'pfEvents', 'حُفظ فريق الفعالية — يظهر الآن لرئيس الأمن والسلامة لاعتماده.');
    }

    public function eventApprove(Request $request, Place $place, string $i): RedirectResponse
    {
        $idx = $this->eventIndex($place, $i);
        abort_unless($idx !== null && P::canApproveEvent($request->user()), 403, 'اعتماد فريق الفعالية لرئيس الأمن والسلامة.');
        $this->profile->approveEvent($place->code, $idx, $request->user()->name, $request->user()->id);
        return $this->back($place, 'pfEvents', 'اعتُمد فريق الفعالية.');
    }

    public function eventDelete(Request $request, Place $place, string $i): RedirectResponse
    {
        $idx = $this->eventIndex($place, $i);
        abort_unless($idx !== null && P::canEvents($request->user()), 403);
        $this->profile->deleteEvent($place->code, $idx, $request->user()->id);
        return $this->back($place, 'pfEvents', 'حُذفت الفعالية وفريقها.');
    }
}
