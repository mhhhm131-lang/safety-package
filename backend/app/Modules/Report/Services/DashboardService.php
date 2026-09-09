<?php

namespace App\Modules\Report\Services;

use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Form\Models\FormAssignment;
use App\Modules\Governance\Models\Place;
use App\Modules\Incident\Models\Incident;
use App\Modules\Permit\Models\Permit;
use App\Modules\Risk\Models\Risk;
use App\Modules\Worker\Models\Worker;
use Illuminate\Support\Facades\DB;

/**
 * أرقام لوحة التقارير عبر الوحدات.
 *
 * **ما صُحّح من OHSMS:**
 * ١. لا `tenant_id`؛ النطاق بالمدة والمكان والوحدة (`ReportScope`).
 * ٢. **لا ذاكرة مؤقتة.** كانت خمس دقائق بمفتاح المستأجر وحده: أرقام قديمة على لوحة
 *    غايتها الأرقام الحية، وتسريب أرقام إدارة إلى مدير إدارة أخرى.
 * ٣. `TIMESTAMPDIFF` بديلها حساب محمول (كانت MySQL فقط فتنهار على SQLite وPostgres).
 * ٤. المتحكم كان يبتلع كل استثناء ويعرض أصفاراً — الخلل يبقى مخفياً. لا التقاط هنا.
 *
 * **إضافة المعهد:** «فجوة الاستجابة» أولَ ما يُقاس، لأنها غاية الحزمة كلها
 * (CLAUDE.md: إلغاء الفجوة الزمنية بين اكتشاف الحالة الطارئة والاستجابة الأولية).
 */
class DashboardService
{
    /** كل ما تعرضه لوحة المدير العام. */
    public function overview(ReportScope $scope): array
    {
        return [
            'scope'      => ['label' => $scope->label(), 'days' => $scope->days(), 'place_id' => $scope->placeId],
            'response'   => $this->responseGap($scope),
            'incidents'  => $this->incidentStats($scope),
            'emergency'  => $this->emergencyStats($scope),
            'risks'      => $this->riskStats($scope),
            'permits'    => $this->permitStats($scope),
            'workers'    => $this->workerStats($scope),
            'forms'      => $this->formStats($scope),
            'by_place'   => $this->byPlace($scope),
            'attention'  => $this->needsAttention($scope),
            'by_month'   => $this->incidentsByMonth($scope),
        ];
    }

    // ── فجوة الاستجابة (غاية الحزمة) ──

    /**
     * زمنان اثنان بالدقائق:
     * - بلاغ الشاغل: من وصول البلاغ إلى **استلام الفني** (`field_received_at`).
     * - الحالة الطارئة: من التفعيل إلى **الإقرار بالاستلام** (`acknowledged_at`).
     *
     * الحساب في PHP لا في SQL: `TIMESTAMPDIFF` غير موجودة في SQLite ولا Postgres،
     * وصيغ الفروق تختلف بين المحركات. الأعداد هنا صغيرة (بلاغات مدة واحدة).
     * `null` تعني **لا بيانات في المدة** ولا تُعرض صفراً — الصفر يعني «استجابة فورية».
     */
    public function responseGap(ReportScope $scope): array
    {
        $incidents = $scope->apply(Incident::query())
            ->whereNotNull('field_received_at')
            ->get(['created_at', 'field_received_at']);

        $incidentMinutes = $incidents
            ->map(fn ($i) => $i->created_at && $i->field_received_at
                ? abs($i->field_received_at->diffInSeconds($i->created_at)) / 60
                : null)
            ->filter(fn ($m) => $m !== null);

        $emergencies = $scope->apply(EmergencyIncident::query(), 'triggered_at', 'place_id', null)
            ->where('is_drill', false)
            ->whereNotNull('acknowledged_at')
            ->get(['triggered_at', 'acknowledged_at']);

        $emergencyMinutes = $emergencies
            ->map(fn ($e) => $e->triggered_at && $e->acknowledged_at
                ? abs($e->acknowledged_at->diffInSeconds($e->triggered_at)) / 60
                : null)
            ->filter(fn ($m) => $m !== null);

        return [
            'incident' => [
                'count'       => $incidentMinutes->count(),
                'avg_minutes' => $incidentMinutes->count() ? round((float) $incidentMinutes->avg(), 1) : null,
                'max_minutes' => $incidentMinutes->count() ? round((float) $incidentMinutes->max(), 1) : null,
                'pending'     => $scope->apply(Incident::query())
                    ->whereIn('status', Incident::BEFORE_FIELD)->count(),
            ],
            'emergency' => [
                'count'       => $emergencyMinutes->count(),
                'avg_minutes' => $emergencyMinutes->count() ? round((float) $emergencyMinutes->avg(), 1) : null,
                'max_minutes' => $emergencyMinutes->count() ? round((float) $emergencyMinutes->max(), 1) : null,
                'unacknowledged' => $scope->apply(EmergencyIncident::query(), 'triggered_at', 'place_id', null)
                    ->where('is_drill', false)->whereNull('acknowledged_at')->count(),
            ],
        ];
    }

    // ── عدّادات الوحدات ──

    public function incidentStats(ReportScope $scope): array
    {
        $counts = $scope->apply(Incident::query())
            ->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status')->all();

        $open = 0;
        foreach ($counts as $status => $n) {
            if (!in_array($status, Incident::TERMINAL, true)) {
                $open += (int) $n;
            }
        }

        return [
            'total'    => array_sum($counts),
            'open'     => $open,
            'closed'   => (int) ($counts['closed'] ?? 0),
            'resolved' => (int) ($counts['resolved'] ?? 0),
            'escalated' => (int) ($counts['escalated_to_coord'] ?? 0) + (int) ($counts['escalated_to_manager'] ?? 0),
            'by_status' => $counts,
            'by_type'  => $scope->apply(Incident::query())
                ->selectRaw('incident_type, COUNT(*) as c')->groupBy('incident_type')->pluck('c', 'incident_type')->all(),
        ];
    }

    public function emergencyStats(ReportScope $scope): array
    {
        $rows = $scope->apply(EmergencyIncident::query(), 'triggered_at', 'place_id', null)
            ->selectRaw('status, is_drill, COUNT(*) as c')->groupBy('status', 'is_drill')->get();

        $real = $drills = $open = 0;
        foreach ($rows as $row) {
            $n = (int) $row->c;
            if ($row->is_drill) {
                $drills += $n;
                continue;
            }
            $real += $n;
            if (in_array($row->status, EmergencyIncident::OPEN_STATUSES, true)) {
                $open += $n;
            }
        }

        return ['total' => $real + $drills, 'real' => $real, 'drills' => $drills, 'open' => $open];
    }

    /**
     * المخاطر لا تُقيَّد بالمدة: السجل حالة قائمة لا حدثاً في فترة.
     * تُعدّ **المخاطر الفعلية** (`risk_type = active`) وحدها — الكتاب والسجل العام مرجعان لا واقع.
     */
    public function riskStats(ReportScope $scope): array
    {
        $base = fn () => $this->scopeUnitOnly($scope, Risk::query()->where('risk_type', 'active'));

        $row = $base()->selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN risk_score >= 15 THEN 1 ELSE 0 END) as critical,
            SUM(CASE WHEN risk_score BETWEEN 8 AND 14 THEN 1 ELSE 0 END) as medium,
            SUM(CASE WHEN risk_score < 8 THEN 1 ELSE 0 END) as low
        ")->first();

        return [
            'total'    => (int) ($row->total ?? 0),
            'active'   => (int) ($row->active ?? 0),
            'critical' => (int) ($row->critical ?? 0),
            'medium'   => (int) ($row->medium ?? 0),
            'low'      => (int) ($row->low ?? 0),
        ];
    }

    public function permitStats(ReportScope $scope): array
    {
        $counts = $scope->apply(Permit::query())
            ->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status')->all();

        return [
            'total'    => array_sum($counts),
            'active'   => (int) ($counts[Permit::STATUS_ACTIVE] ?? 0),
            'awaiting' => (int) ($counts[Permit::STATUS_SUBMITTED] ?? 0)
                + (int) ($counts[Permit::STATUS_UNDER_REVIEW] ?? 0)
                + (int) ($counts[Permit::STATUS_SAFETY_APPROVED] ?? 0),
            'expired'  => (int) ($counts[Permit::STATUS_EXPIRED] ?? 0),
            'by_status' => $counts,
        ];
    }

    /** العمال حالة قائمة لا حدثاً: بلا تقييد بالمدة. */
    public function workerStats(ReportScope $scope): array
    {
        $counts = $this->scopeUnitOnly($scope, Worker::query())
            ->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status')->all();

        return [
            'total'      => array_sum($counts),
            'authorized' => (int) ($counts['work_authorized'] ?? 0) + (int) ($counts['role_authorized'] ?? 0),
            'blocked'    => (int) ($counts['blocked'] ?? 0) + (int) ($counts['suspended'] ?? 0),
            'in_process' => (int) ($counts['submitted'] ?? 0) + (int) ($counts['induction'] ?? 0) + (int) ($counts['training'] ?? 0),
        ];
    }

    public function formStats(ReportScope $scope): array
    {
        $counts = FormAssignment::query()
            ->whereBetween('created_at', [$scope->from, $scope->to])
            ->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status')->all();

        $total     = array_sum($counts);
        $completed = (int) ($counts['completed'] ?? 0);

        return [
            'total'     => $total,
            'completed' => $completed,
            'pending'   => (int) ($counts['pending'] ?? 0),
            'overdue'   => (int) ($counts['overdue'] ?? 0),
            // النسبة null بلا تكليفات — لا تُعرض ١٠٠٪ على فراغ (خلل KPIService في OHSMS).
            'response_pct' => $total > 0 ? (int) round($completed / $total * 100) : null,
        ];
    }

    // ── الأماكن التسعة ──

    /** صف لكل مكان: البلاغات والحالات الطارئة والمخاطر الحرجة والتصاريح النشطة. */
    public function byPlace(ReportScope $scope): array
    {
        $incidents = $scope->apply(Incident::query())
            ->selectRaw('place_id, COUNT(*) as c')->groupBy('place_id')->pluck('c', 'place_id')->all();

        $emergencies = $scope->apply(EmergencyIncident::query(), 'triggered_at', 'place_id', null)
            ->where('is_drill', false)
            ->selectRaw('place_id, COUNT(*) as c')->groupBy('place_id')->pluck('c', 'place_id')->all();

        $risks = $this->scopeUnitOnly($scope, Risk::query()->where('risk_type', 'active')->where('risk_score', '>=', 15))
            ->selectRaw('place_id, COUNT(*) as c')->groupBy('place_id')->pluck('c', 'place_id')->all();

        $permits = $scope->apply(Permit::query())
            ->where('status', Permit::STATUS_ACTIVE)
            ->selectRaw('place_id, COUNT(*) as c')->groupBy('place_id')->pluck('c', 'place_id')->all();

        return Place::orderBy('sort')->get(['id', 'code', 'name'])
            ->when($scope->placeId, fn ($places) => $places->where('id', $scope->placeId))
            ->map(fn (Place $p) => [
                'code'      => $p->code,
                'name'      => $p->name,
                'incidents' => (int) ($incidents[$p->id] ?? 0),
                'emergency' => (int) ($emergencies[$p->id] ?? 0),
                'critical_risks' => (int) ($risks[$p->id] ?? 0),
                'active_permits' => (int) ($permits[$p->id] ?? 0),
            ])
            ->values()
            ->all();
    }

    // ── ما يحتاج قراراً ──

    /** أسطر قصيرة يقرؤها المدير العام: كل سطر رقم وسبب ورابط. */
    public function needsAttention(ReportScope $scope): array
    {
        $out = [];

        $late = $scope->apply(Incident::query())
            ->whereIn('status', ['escalated_to_coord', 'escalated_to_manager'])->count();
        if ($late) {
            $out[] = ['n' => $late, 'text' => 'بلاغ شاغل مُصعَّد لم يُغلق', 'route' => 'incidents.index'];
        }

        $unack = $scope->apply(EmergencyIncident::query(), 'triggered_at', 'place_id', null)
            ->where('is_drill', false)->whereNull('acknowledged_at')->count();
        if ($unack) {
            $out[] = ['n' => $unack, 'text' => 'حالة طارئة بلا إقرار باستلام التنبيه', 'route' => 'emergency.incidents.index'];
        }

        $critical = $this->scopeUnitOnly($scope, Risk::query()->where('risk_type', 'active'))
            ->where('risk_score', '>=', 15)->where('status', 'active')->count();
        if ($critical) {
            $out[] = ['n' => $critical, 'text' => 'خطر فعلي حرج (١٥ فأكثر) ما زال نشطاً', 'route' => 'risk.active.index'];
        }

        $awaiting = $scope->apply(Permit::query())
            ->whereIn('status', [Permit::STATUS_SUBMITTED, Permit::STATUS_UNDER_REVIEW, Permit::STATUS_SAFETY_APPROVED])
            ->count();
        if ($awaiting) {
            $out[] = ['n' => $awaiting, 'text' => 'تصريح ينتظر إجراءً', 'route' => 'permits.queue'];
        }

        $overdueForms = FormAssignment::query()
            ->whereBetween('created_at', [$scope->from, $scope->to])
            ->where('status', 'overdue')->count();
        if ($overdueForms) {
            $out[] = ['n' => $overdueForms, 'text' => 'تكليف نموذج تجاوز مهلته', 'route' => 'forms.index'];
        }

        $blocked = $this->scopeUnitOnly($scope, Worker::query())
            ->whereIn('status', ['blocked', 'suspended'])->count();
        if ($blocked) {
            $out[] = ['n' => $blocked, 'text' => 'عامل محظور أو موقوف', 'route' => 'workers.index'];
        }

        return $out;
    }

    // ── الاتجاه الشهري ──

    /**
     * بلاغات الشاغل شهراً بشهر داخل المدة المختارة.
     * تعبير الشهر يختلف بين المحركات — SQLite محلياً وPostgres على المنشور.
     */
    public function incidentsByMonth(ReportScope $scope): array
    {
        $expr = match (DB::connection()->getDriverName()) {
            'sqlite'            => "strftime('%Y-%m', created_at)",
            'mysql', 'mariadb'  => "DATE_FORMAT(created_at, '%Y-%m')",
            'pgsql'             => "TO_CHAR(created_at, 'YYYY-MM')",
            default             => 'SUBSTR(created_at, 1, 7)',
        };

        return $scope->apply(Incident::query())
            ->selectRaw("{$expr} as label, COUNT(*) as c")
            ->groupBy('label')->orderBy('label')->get()
            ->map(fn ($r) => ['label' => $r->label, 'count' => (int) $r->c])
            ->all();
    }

    // ── تقريرا البلاغات والمخاطر (الشاشتان المنقولتان) ──

    public function incidentReport(ReportScope $scope): array
    {
        $stats = $this->incidentStats($scope);

        return [
            'total'     => $stats['total'],
            'by_type'   => $stats['by_type'],
            'by_status' => $stats['by_status'],
            'response'  => $this->responseGap($scope)['incident'],
            'recent'    => $scope->apply(Incident::query())
                ->with(['place:id,code,name', 'organizationUnit:id,name'])
                ->latest()->limit(10)->get(),
            'by_month'  => $this->incidentsByMonth($scope),
        ];
    }

    public function riskReport(ReportScope $scope): array
    {
        $base = fn () => $this->scopeUnitOnly($scope, Risk::query()->where('risk_type', 'active'));

        // مصفوفة ٥×٥: [الاحتمال][الشدة] = العدد
        $matrix = [];
        for ($l = 1; $l <= 5; $l++) {
            for ($s = 1; $s <= 5; $s++) {
                $matrix[$l][$s] = 0;
            }
        }
        $base()->selectRaw('likelihood, severity, COUNT(*) as c')
            ->whereNotNull('likelihood')->whereNotNull('severity')
            ->groupBy('likelihood', 'severity')->get()
            ->each(function ($row) use (&$matrix) {
                $l = (int) $row->likelihood;
                $s = (int) $row->severity;
                if ($l >= 1 && $l <= 5 && $s >= 1 && $s <= 5) {
                    $matrix[$l][$s] = (int) $row->c;
                }
            });

        return array_merge($this->riskStats($scope), [
            'matrix' => $matrix,
            'top'    => $base()->with(['place:id,code,name', 'organizationUnit:id,name'])
                ->orderByDesc('risk_score')->limit(10)->get(),
        ]);
    }

    // ── مساعد ──

    /** المخاطر والعمال حالة قائمة: يُطبَّق المكان والوحدة بلا المدة. */
    private function scopeUnitOnly(ReportScope $scope, $query)
    {
        if ($scope->placeId) {
            $query->where('place_id', $scope->placeId);
        }
        if ($scope->unitIds !== null) {
            $ids = $scope->unitIds;
            $query->where(fn ($q) => $q->whereNull('organization_unit_id')->orWhereIn('organization_unit_id', $ids));
        }

        return $query;
    }
}
