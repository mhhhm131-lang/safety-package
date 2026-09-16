<?php

namespace App\Modules\Governance\Controllers;

use App\Core\Inbox\InboxService;
use App\Core\Intents\IntentRegistry;
use App\Core\Permissions\PermissionRegistry;
use App\Http\Controllers\Controller;
use App\Modules\Governance\Models\AppNotification;
use App\Modules\Governance\Models\AuditLog;
use App\Modules\Governance\Models\Setting;
use App\Modules\Report\Services\DashboardService;
use App\Modules\Report\Services\ReportScope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * المرحلة ١١-٢ (قرار ٣٤): الصفحة الأولى بعد الدخول هي «ما ينتظرك الآن» — لا بطاقات روابط.
 * كل بند سؤال وزر؛ ما ليس مهمة يُفتح من القائمة أو البحث (١١-٤).
 *
 * المرحلة ١٣-٢ (قرار ٣٩): الشاشة الأولى خمسة أجزاء بالترتيب — أرقام كبيرة (لمن يملك `report.view`)،
 * رسم وخريطة، ما ينتظرك، أريد أن…، آخر الإجراءات. لا منطق جديد: الأرقام من `DashboardService` كما تحسبها
 * لوحة التقارير، والإجراءات من سجل التدقيق أو إشعارات المستخدم.
 */
class HomeController extends Controller
{
    public function index(Request $request, InboxService $inbox, DashboardService $dashboard): View|\Illuminate\Http\RedirectResponse
    {
        $user = $request->user();
        // المرحلة ٦: حساب الطرف الخارجي يفتح بوابته مباشرة
        if ($user->isContractor()) {
            return redirect()->route('contractor.home');
        }
        $role = $user->role();
        $tasks = $inbox->forUser($user);

        $overview = null;
        if (PermissionRegistry::hasPermission($role, 'report.view')) {
            // الشهر الجاري كما في لوحة التقارير (لا رقم مخترع)؛ الاتجاه ستة أشهر حتى يكون للرسم معنى
            $month = ReportScope::fromRequest(null, null, null, $user->id);
            $trend = ReportScope::fromRequest(Carbon::now()->subMonths(5)->startOfMonth()->toDateString(), null, null, $user->id);
            // الخدمة تعيد الأشهر التي فيها بلاغ فقط؛ الرسم يحتاج الستة كلها والفارغ صفر
            $counts = collect($dashboard->incidentsByMonth($trend))->pluck('count', 'label');
            $byMonth = [];
            for ($m = 5; $m >= 0; $m--) {
                $label = Carbon::now()->subMonths($m)->format('Y-m');
                $byMonth[] = ['label' => $label, 'count' => (int) ($counts[$label] ?? 0)];
            }
            $overview = [
                'waiting'  => $tasks->count(),
                'response' => $dashboard->responseGap($month),
                'by_month' => $byMonth,
                'by_place' => $dashboard->byPlace($month),
            ];
        }

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

        // المرحلة ١٢ (قرار ٣٥): «ما ينتظرك» + «أريد أن…»
        return view('governance.inbox', [
            'tasks' => $tasks,
            'intents' => IntentRegistry::forUser($user),
            'overview' => $overview,
            'recent' => $recent,
            'deadlinesUnset' => $deadlinesUnset,
        ]);
    }
}
