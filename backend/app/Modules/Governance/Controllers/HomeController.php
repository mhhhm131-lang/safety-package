<?php

namespace App\Modules\Governance\Controllers;

use App\Core\Inbox\InboxService;
use App\Core\Intents\IntentRegistry;
use App\Core\Permissions\PermissionRegistry;
use App\Http\Controllers\Controller;
use App\Modules\Governance\Models\AppNotification;
use App\Modules\Governance\Models\AuditLog;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * المرحلة ١١-٢ (قرار ٣٤): الصفحة الأولى بعد الدخول هي «ما ينتظرك الآن» — لا بطاقات روابط.
 * كل بند سؤال وزر؛ ما ليس مهمة يُفتح من القائمة أو البحث (١١-٤).
 *
 * المرحلة ١٣-٢ (قرار ٣٩): الشاشة الأولى خمسة أجزاء بالترتيب — أرقام كبيرة (لمن يملك `report.view`)،
 * رسم وخريطة، ما ينتظرك، أريد أن…، آخر الإجراءات. لا منطق جديد: الأرقام من `DashboardService` كما تحسبها
 * لوحة التقارير، والإجراءات من سجل التدقيق أو إشعارات المستخدم.
 *
 * ٢٦-١٠ (قرار ٦٦): الأرقام الكبيرة حُذفت — الأجزاء: الأماكن والرسم، ما ينتظرك، أريد أن…، آخر الإجراءات.
 */
class HomeController extends Controller
{
    public function index(Request $request, InboxService $inbox): View|\Illuminate\Http\RedirectResponse
    {
        $user = $request->user();
        // المرحلة ٦: حساب الطرف الخارجي يفتح بوابته مباشرة
        if ($user->isContractor()) {
            return redirect()->route('contractor.home');
        }
        $role = $user->role();
        $tasks = $inbox->forUser($user);

        // ٢٦-١٠ (قرار ٦٦ «أرقام واحدة»): الأرقام الأربعة الكبيرة حُذفت من هنا — الرسم «حال الآن» وحده، و«فجوة الاستجابة» في لوحة التقارير؛
        // زر «التقارير» تحت الرسم لمن يملك report.view
        $canReports = PermissionRegistry::hasPermission($role, 'report.view');

        // آخر الإجراءات: سجل التدقيق لمن يملكه، وإلا آخر إشعارات المستخدم نفسه
        if (PermissionRegistry::hasPermission($role, 'system.audit')) {
            $recent = AuditLog::with('user')->latest('created_at')->limit(8)->get()
                ->map(fn (AuditLog $l) => ['at' => $l->created_at, 'text' => trim(($l->user?->name ?? 'النظام').' · '.($l->description ?: $l->action.' '.$l->model_name)), 'url' => route('app.audit')]);
        } else {
            $recent = AppNotification::where('user_id', $user->id)->latest('created_at')->limit(8)->get()
                ->map(fn (AppNotification $n) => ['at' => $n->created_at, 'text' => $n->title, 'url' => $n->url ?: route('app.notifications.index')]);
        }

        // المرحلة ١٨-١ (ز، قرار ٤٦): مهلة بلاغ الشاغل بلا رقم = لا «متأخر» ولا تصعيد آلي — تنبيه لمن يملك الإعدادات، بلا عدّ (ليس مهمة)
        $deadlinesUnset = [];
        if (PermissionRegistry::hasPermission($role, 'system.settings')) {
            foreach (Setting::DEADLINE_KEYS as $key => $label) {
                if (Setting::deadlineHours(substr($key, strrpos($key, '.') + 1)) === null) $deadlinesUnset[] = $label;
            }
        }

        // المرحلة ١٩-٣ (قرار ٤٨): من العمل اليومي — «أين تقف البلاغات» (سكة المستويات) و«بلاغاتي» (قررتُ فيها ولم تُغلق) لأدوار الواجهة
        $follow = null;
        $ui = PermissionRegistry::uiRole($role);
        if (in_array($ui, ['tech', 'fm', 'adm', 'exec', 'safety'], true)) {
            $R = \App\Modules\Store\Services\InspectionDocReader::class;
            $bid = \App\Modules\Governance\Services\BuildingContext::id($user); // ٢٨-٣: سكة مبنى الجلسة
            $rail = $R::rail($bid);
            $follow = ['rail' => $rail, 'me' => $R::ROLE_LEVEL[$ui] ?? 0, 'mine' => array_slice($R::decidedByRole($ui, $bid), 0, 12), 'hasLevel' => isset($R::ROLE_LEVEL[$ui])];
            if (!$rail['open'] && !$follow['mine']) $follow = null; // لا بلاغات فحص: لا يُعرض القسم
        }

        // المرحلة ٢٥-١ (قرار ٦٤): الأماكن في الصفحة الأولى لكل حساب في نطاقه — مربعات ملف المكان نفسها
        // (لونها من حال الفحص) بلا شرط report.view؛ الموظف مكانه، الفني ما يغطيه، مدير الفرع فرعه، القيادة الكل
        // المرحلة ٢٥-٢: الرسم «حال الآن» من المصدر الواحد `PlaceSnapshot` بالنطاق نفسه، ويترشّح بالمكان في المتصفح
        // ٢٨-٣ (قرار ٧٨): المربعات بالرمز الكامل، وبلاطة كل مكان من وثائق مبناه وصنفه
        $snapshot = \App\Modules\Governance\Services\PlaceSnapshot::forUser($user);
        $placeByCode = Place::all()->keyBy('code');
        $placeTiles = []; $tilesByB = [];
        // ترتيب اللوحة داخل كل مبنى (HZ_ORDER: القبو قبل مركز السلامة)، والمباني بترتيبها
        $order = array_flip(\App\Modules\Store\Services\InspectionDocReader::HZ_ORDER);
        $codes = array_keys($snapshot['places']);
        usort($codes, fn ($a, $b) => [(int) ($placeByCode[$a]->building_id ?? 0), $order[$placeByCode[$a]->category ?? ''] ?? 99]
            <=> [(int) ($placeByCode[$b]->building_id ?? 0), $order[$placeByCode[$b]->category ?? ''] ?? 99]);
        foreach ($codes as $code) {
            if (!($pp = $placeByCode[$code] ?? null)) continue;
            $tilesByB[$pp->building_id] ??= \App\Modules\Store\Services\InspectionDocReader::placeTiles((int) $pp->building_id);
            if (isset($tilesByB[$pp->building_id][$pp->category])) $placeTiles[$code] = $tilesByB[$pp->building_id][$pp->category];
        }
        // بكلمته «طبّقها الآن» (٢٠٢٦-١٠-٠٨): أكثر من مبنى ← المربعات مجمَّعة بالمبنى، مبنى الجلسة مفتوح والباقي مطوي
        $tileGroups = [];
        $byB = [];
        foreach (array_keys($placeTiles) as $code) $byB[$placeByCode[$code]->building_id][] = $code;
        if (count($byB) > 1) {
            $sessionB = \App\Modules\Governance\Services\BuildingContext::id($user);
            $mainId = \App\Modules\Emergency\Models\EmergencyBuilding::main()?->id;
            $blds = \App\Modules\Emergency\Models\EmergencyBuilding::with('branchUnit')->whereIn('id', array_keys($byB))->get()->keyBy('id');
            foreach ($byB as $bidKey => $codesOf) {
                $b = $blds->get($bidKey);
                if (!$b) continue;
                $tileGroups[] = ['b' => $b, 'branch' => $b->branchUnit?->name ?? $b->branch, 'codes' => $codesOf, 'main' => $b->id === $mainId, 'expanded' => $b->id === $sessionB,
                    'open' => array_sum(array_map(fn ($c) => (int) ($placeTiles[$c]['open'] ?? 0), $codesOf)), 'od' => array_sum(array_map(fn ($c) => (int) ($placeTiles[$c]['od'] ?? 0), $codesOf))];
            }
        }

        // المرحلة ١٢ (قرار ٣٥): «ما ينتظرك» + «أريد أن…»
        return view('governance.inbox', [
            'placeTiles' => $placeTiles,
            'tileGroups' => $tileGroups,
            'placeByCode' => $placeByCode,
            'scopeAll' => $snapshot['all'],
            'snapshot' => $snapshot,
            'follow' => $follow,
            'makani' => ($p = $user->profile) && $p->is_active ? $p->myPlace() : null, // ١٩-٦ (قرار ٤٩)
            'tasks' => $tasks,
            'intents' => IntentRegistry::forUser($user),
            'canReports' => $canReports,
            'recent' => $recent,
            'deadlinesUnset' => $deadlinesUnset,
        ]);
    }
}
