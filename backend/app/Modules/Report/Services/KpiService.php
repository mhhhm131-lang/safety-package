<?php

namespace App\Modules\Report\Services;

use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Incident\Models\Incident;
use App\Modules\Permit\Models\Permit;
use App\Modules\Risk\Models\Risk;
use Illuminate\Support\Collection;

/**
 * مؤشرات الأداء المعروضة على لوحة المدير العام.
 *
 * **حال هذه الخدمة في OHSMS:** موجودة ولا يستدعيها أحد — كود ميت لم يُشغَّل قط.
 * ولهذا بقيت فيها ثلاثة أخطاء: `TIMESTAMPDIFF` (MySQL فقط، تنهار على SQLite وPostgres)،
 * ونسبة امتثال التدريب تعيد **١٠٠٪ حين لا يوجد عامل واحد** (فراغ يُقرأ نجاحاً كاملاً)،
 * واستعلام داخل حلقة على كل عامل. أُعيدت كتابتها ووُصلت بالمتحكم.
 *
 * **القاعدة هنا:** `null` تعني «لا بيانات في المدة» ولا تُعرض صفراً ولا مئة.
 * الصفر رقم له معنى (استجابة فورية)، والمئة كذلك (امتثال تام). الفراغ ليس أياً منهما.
 */
class KpiService
{
    public function all(ReportScope $scope): array
    {
        return [
            'closure_rate'       => $this->incidentClosureRate($scope),
            'avg_closure_days'   => $this->avgClosureDays($scope),
            'escalation_rate'    => $this->escalationRate($scope),
            'avg_risk_score'     => $this->avgActiveRiskScore($scope),
            'permit_activation'  => $this->permitActivationRate($scope),
            'emergency_end_rate' => $this->emergencyEndRate($scope),
        ];
    }

    /** نسبة البلاغات المغلقة من مجموع بلاغات المدة. */
    public function incidentClosureRate(ReportScope $scope): ?int
    {
        $total = $scope->apply(Incident::query())->count();
        if ($total === 0) {
            return null;
        }
        $closed = $scope->apply(Incident::query())->whereIn('status', Incident::TERMINAL)->count();

        return (int) round($closed / $total * 100);
    }

    /**
     * متوسط أيام الإغلاق للبلاغات المغلقة.
     * الحساب في PHP لا في SQL — `TIMESTAMPDIFF` غير محمولة، والأعداد هنا صغيرة.
     */
    public function avgClosureDays(ReportScope $scope): ?float
    {
        $rows = $scope->apply(Incident::query())
            ->whereNotNull('closed_at')
            ->get(['created_at', 'closed_at']);

        $days = $this->minutesBetween($rows, 'created_at', 'closed_at')->map(fn ($m) => $m / 1440);

        return $days->count() ? round((float) $days->avg(), 1) : null;
    }

    /** نسبة البلاغات التي بلغت التصعيد. */
    public function escalationRate(ReportScope $scope): ?int
    {
        $total = $scope->apply(Incident::query())->count();
        if ($total === 0) {
            return null;
        }
        $escalated = $scope->apply(Incident::query())
            ->whereNotNull('escalated_at')->count();

        return (int) round($escalated / $total * 100);
    }

    /** متوسط درجة المخاطر الفعلية النشطة (حالة قائمة، بلا تقييد بالمدة). */
    public function avgActiveRiskScore(ReportScope $scope): ?float
    {
        $query = Risk::query()->where('risk_type', 'active')->where('status', 'active');
        if ($scope->placeId) {
            $query->where('place_id', $scope->placeId);
        }
        if ($scope->unitIds !== null) {
            $ids = $scope->unitIds;
            $query->where(fn ($q) => $q->whereNull('organization_unit_id')->orWhereIn('organization_unit_id', $ids));
        }

        $avg = $query->avg('risk_score');

        return $avg === null ? null : round((float) $avg, 1);
    }

    /** نسبة التصاريح التي وصلت التفعيل من مجموع تصاريح المدة. */
    public function permitActivationRate(ReportScope $scope): ?int
    {
        $total = $scope->apply(Permit::query(), 'created_at', 'place_id', null)->count();
        if ($total === 0) {
            return null;
        }
        $reached = $scope->apply(Permit::query(), 'created_at', 'place_id', null)
            ->whereIn('status', [Permit::STATUS_ACTIVE, Permit::STATUS_COMPLETED, Permit::STATUS_EXPIRED])
            ->count();

        return (int) round($reached / $total * 100);
    }

    /** نسبة الحالات الطارئة الحقيقية التي انتهت في المدة. */
    public function emergencyEndRate(ReportScope $scope): ?int
    {
        $base = fn () => $scope->apply(EmergencyIncident::query(), 'triggered_at', 'place_id', null)
            ->where('is_drill', false);

        $total = $base()->count();
        if ($total === 0) {
            return null;
        }

        return (int) round($base()->where('status', EmergencyIncident::STATUS_ENDED)->count() / $total * 100);
    }

    /** @return Collection<int, float> الدقائق بين عمودَي وقت، متخطّيةً الصفوف الناقصة. */
    private function minutesBetween(Collection $rows, string $start, string $end): Collection
    {
        return $rows
            ->map(function ($row) use ($start, $end) {
                if (!$row->{$start} || !$row->{$end}) {
                    return null;
                }

                return abs($row->{$end}->diffInSeconds($row->{$start})) / 60;
            })
            ->filter(fn ($m) => $m !== null)
            ->values();
    }
}
