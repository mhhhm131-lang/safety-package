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
            $rail = $R::rail();
            $follow = ['rail' => $rail, 'me' => $R::ROLE_LEVEL[$ui] ?? 0, 'mine' => array_slice($R::decidedByRole($ui), 0, 12), 'hasLevel' => isset($R::ROLE_LEVEL[$ui])];
            if (!$rail['open'] && !$follow['mine']) $follow = null; // لا بلاغات فحص: لا يُعرض القسم
        }

        // المرحلة ٢٥-١ (قرار ٦٤): الأماكن في الصفحة الأولى لكل حساب في نطاقه — مربعات ملف المكان نفسها
        // (لونها من حال الفحص) بلا شرط report.view؛ الموظف مكانه، الفني ما يغطيه، مدير الفرع فرعه، القيادة الكل
        // المرحلة ٢٥-٢: الرسم «حال الآن» من المصدر الواحد `PlaceSnapshot` بالنطاق نفسه، ويترشّح بالمكان في المتصفح
        $snapshot = \App\Modules\Governance\Services\PlaceSnapshot::forUser($user);
        $placeTiles = array_intersect_key(\App\Modules\Store\Services\InspectionDocReader::placeTiles(), $snapshot['places']);
        $placeByCode = Place::all()->keyBy('code');

        // المرحلة ١٢ (قرار ٣٥): «ما ينتظرك» + «أريد أن…»
        return view('governance.inbox', [
            'placeTiles' => $placeTiles,
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
