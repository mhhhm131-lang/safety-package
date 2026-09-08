<?php

namespace App\Modules\Emergency\Services;

use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Models\EmergencyNotification;
use App\Modules\Emergency\Models\EmergencyTeam;
use App\Modules\Emergency\Models\EvacuationCheckIn;
use App\Modules\Emergency\Models\EvacuationDrill;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * مؤشرات الطوارئ (من OHSMS، مُعاد كتابتها على المخطط الفعلي: النسخة الأصلية استوردت EmergencyDrill/EvacuationRecord
 * غير الموجودين — فكانت شاشة التحليلات تسقط — واستعملت دوال MySQL فقط TIMESTAMPDIFF/DATE_FORMAT).
 * الحساب في PHP حتى يعمل على SQLite محلياً وPostgres على Render. المفاتيح كما تتوقعها شاشة analytics.
 */
class EmergencyAnalyticsService
{
    public function getDashboardMetrics(?Carbon $from = null, ?Carbon $to = null): array
    {
        $from = $from ?? now()->subMonths(12);
        $to = $to ?? now();
        $incidents = $this->incidents($from, $to);

        return [
            'overview' => $this->getOverviewStats($incidents, $from, $to),
            'response_times' => $this->getResponseTimeAnalysis($incidents),
            'incident_trends' => $this->getIncidentTrends($incidents),
            'evacuation_metrics' => $this->getEvacuationMetrics($incidents),
            'team_performance' => $this->getTeamPerformance(),
            'drill_effectiveness' => $this->getDrillEffectiveness($from, $to),
            'notification_stats' => $this->getNotificationStats($from, $to),
            'building_risk_scores' => $this->getBuildingRiskScores(),
        ];
    }

    protected function incidents(Carbon $from, Carbon $to): Collection
    {
        return EmergencyIncident::whereBetween('triggered_at', [$from, $to])->with('place')->get();
    }

    /** الدقائق من التفعيل إلى الانتهاء (المنتهية فقط). */
    protected function minutes(EmergencyIncident $i): ?float
    {
        if (!$i->ended_at) return null;
        return round(abs($i->ended_at->diffInSeconds($i->triggered_at)) / 60, 1);
    }

    public function getOverviewStats(Collection $incidents, Carbon $from, Carbon $to): array
    {
        $total = $incidents->count();
        $real = $incidents->where('is_drill', false)->count();
        $drills = $incidents->where('is_drill', true)->count();
        $resolved = $incidents->where('status', 'ended')->count();
        $times = $incidents->map(fn ($i) => $this->minutes($i))->filter(fn ($v) => $v !== null);
        $previousFrom = $from->copy()->subDays(max(1, $from->diffInDays($to)));
        $previous = EmergencyIncident::whereBetween('triggered_at', [$previousFrom, $from])->count();
        $trend = $previous > 0 ? round((($total - $previous) / $previous) * 100, 1) : 0;

        return [
            'total_incidents' => $total,
            'real_incidents' => $real,
            'drills' => $drills,
            'resolved' => $resolved,
            'resolution_rate' => $total > 0 ? round(($resolved / $total) * 100, 1) : 0,
            'avg_response_time_minutes' => $times->count() ? round((float) $times->avg(), 1) : 0,
            'trend_percentage' => $trend,
            'trend_direction' => $trend > 0 ? 'up' : ($trend < 0 ? 'down' : 'stable'),
        ];
    }

    public function getResponseTimeAnalysis(Collection $incidents): array
    {
        $ended = $incidents->filter(fn ($i) => $i->ended_at);
        $group = fn (string $key) => $ended->groupBy($key)->map(fn ($g) => [
            'avg_minutes' => round((float) $g->map(fn ($i) => $this->minutes($i))->avg(), 1),
            'count' => $g->count(),
        ])->toArray();

        $allTimes = $ended->map(fn ($i) => $this->minutes($i))->sort()->values();
        $percentiles = [];
        if ($allTimes->isNotEmpty()) {
            $n = $allTimes->count();
            foreach (['p50' => 0.5, 'p75' => 0.75, 'p90' => 0.9, 'p95' => 0.95] as $k => $p) {
                $percentiles[$k] = round($allTimes[min($n - 1, (int) ($n * $p))] ?? 0, 1);
            }
        }
        return ['by_type' => $group('incident_type'), 'by_severity' => $group('severity'), 'percentiles' => $percentiles];
    }

    public function getIncidentTrends(Collection $incidents): array
    {
        $monthly = $incidents->groupBy(fn ($i) => $i->triggered_at->format('Y-m'))->sortKeys()
            ->map(fn ($g) => $g->groupBy('incident_type')->map->count()->toArray())->toArray();
        // DAYOFWEEK في MySQL: الأحد=1 … السبت=7 (الشاشة تعتمد هذا الترقيم)
        $byDayOfWeek = $incidents->groupBy(fn ($i) => $i->triggered_at->dayOfWeek + 1)->map->count()->sortKeys()->toArray();
        $byHour = $incidents->groupBy(fn ($i) => (int) $i->triggered_at->format('G'))->map->count()->sortKeys()->toArray();
        $typeDistribution = $incidents->groupBy('incident_type')->map->count()->toArray();
        return ['monthly' => $monthly, 'by_day_of_week' => $byDayOfWeek, 'by_hour' => $byHour, 'type_distribution' => $typeDistribution];
    }

    /** كفاءة الإخلاء من سجلات الحصر (evacuation_check_ins بدل EvacuationRecord غير الموجود). */
    public function getEvacuationMetrics(Collection $incidents): array
    {
        $checkIns = EvacuationCheckIn::whereIn('incident_id', $incidents->pluck('id'))->with('incident:id,building_id,triggered_at,place_id')->get();
        if ($checkIns->isEmpty()) {
            return ['total_evacuations' => 0, 'avg_evacuation_time_minutes' => 0, 'compliance_rate' => 0, 'accounted_for' => 0, 'missing' => 0, 'needs_assistance' => 0, 'by_building' => []];
        }
        $timeOf = fn ($c) => $c->checked_in_at && $c->incident ? abs($c->checked_in_at->diffInSeconds($c->incident->triggered_at)) / 60 : null;
        $times = $checkIns->map($timeOf)->filter(fn ($v) => $v !== null);
        $safe = $checkIns->where('status', 'safe')->count();
        $byPlace = $checkIns->groupBy(fn ($c) => $c->incident?->place_id ?? 0)->map(function ($g) use ($timeOf) {
            $t = $g->map($timeOf)->filter(fn ($v) => $v !== null);
            return ['total' => $g->count(), 'accounted' => $g->where('status', 'safe')->count(), 'avg_time' => $t->count() ? round((float) $t->avg(), 1) : 0];
        })->toArray();

        return [
            'total_evacuations' => $checkIns->count(),
            'avg_evacuation_time_minutes' => $times->count() ? round((float) $times->avg(), 1) : 0,
            'compliance_rate' => round(($safe / $checkIns->count()) * 100, 1),
            'accounted_for' => $safe,
            'missing' => $checkIns->where('status', 'missing')->count(),
            'needs_assistance' => $checkIns->where('needs_assistance', true)->count(),
            'by_building' => $byPlace,
        ];
    }

    public function getTeamPerformance(): array
    {
        $teams = EmergencyTeam::where('is_active', true)->withCount('members')->with('building', 'place')->get();
        $teamStats = $teams->map(fn ($t) => [
            'id' => $t->id, 'name' => $t->name, 'type' => $t->team_type, 'building' => $t->building?->name,
            'place' => $t->place?->name, 'readiness' => $t->readiness, 'members_count' => $t->members_count, 'is_active' => $t->is_active,
        ])->all();
        return [
            'total_teams' => $teams->count(),
            'total_members' => (int) $teams->sum('members_count'),
            'by_type' => collect($teamStats)->groupBy('type')->map->count()->toArray(),
            'teams' => $teamStats,
        ];
    }

    public function getDrillEffectiveness(Carbon $from, Carbon $to): array
    {
        $drills = EvacuationDrill::where('status', 'completed')->whereBetween('scheduled_at', [$from, $to])->whereNotNull('evacuation_time_sec')->orderBy('scheduled_at')->get();
        if ($drills->isEmpty()) {
            return ['total_drills' => 0, 'avg_completion_time_minutes' => 0, 'improvement_trend_percentage' => 0, 'avg_score' => 0, 'by_type' => []];
        }
        $mins = fn ($d) => $d->evacuation_time_sec / 60;
        $mid = intdiv($drills->count(), 2);
        $firstAvg = (float) ($drills->take($mid)->avg($mins) ?? 0);
        $secondAvg = (float) ($drills->skip($mid)->avg($mins) ?? 0);
        $improvement = $firstAvg > 0 ? round((($firstAvg - $secondAvg) / $firstAvg) * 100, 1) : 0;
        return [
            'total_drills' => $drills->count(),
            'avg_completion_time_minutes' => round((float) $drills->avg($mins), 1),
            'improvement_trend_percentage' => $improvement,
            'avg_score' => round((float) $drills->avg('score'), 1),
            'by_type' => $drills->groupBy('drill_type')->map(fn ($g) => ['count' => $g->count(), 'avg_time' => round((float) $g->avg($mins), 1)])->toArray(),
        ];
    }

    public function getNotificationStats(Carbon $from, Carbon $to): array
    {
        $rows = EmergencyNotification::whereBetween('created_at', [$from, $to])->get();
        $total = $rows->count();
        $sent = $rows->whereIn('status', ['sent', 'delivered'])->count();
        $failed = $rows->where('status', 'failed')->count();
        $byChannel = $rows->groupBy('channel')->map(function ($g) {
            $t = $g->count();
            $s = $g->whereIn('status', ['sent', 'delivered'])->count();
            return ['total' => $t, 'sent' => $s, 'failed' => $g->where('status', 'failed')->count(), 'manual' => $g->where('status', 'manual')->count(),
                'success_rate' => $t > 0 ? round(($s / $t) * 100, 1) : 0];
        })->toArray();
        $delivery = $rows->filter(fn ($r) => $r->sent_at && $r->created_at)->map(fn ($r) => abs($r->sent_at->diffInSeconds($r->created_at)));
        return [
            'total' => $total, 'sent' => $sent, 'failed' => $failed,
            'success_rate' => $total > 0 ? round(($sent / $total) * 100, 1) : 0,
            'avg_delivery_time_seconds' => $delivery->count() ? round((float) $delivery->avg(), 1) : 0,
            'by_channel' => $byChannel,
        ];
    }

    /** درجة خطر المبنى (OHSMS): حوادث السنة، وجود فرق، نقاط تجمع، قِدم آخر تمرين. */
    public function getBuildingRiskScores(): array
    {
        $scores = [];
        foreach (EmergencyBuilding::all() as $building) {
            $incidentCount = EmergencyIncident::where('building_id', $building->id)->where('is_drill', false)->where('triggered_at', '>=', now()->subYear())->count();
            $hasTeams = EmergencyTeam::where('building_id', $building->id)->where('is_active', true)->exists();
            $hasAssemblyPoints = $building->assemblyPoints()->where('status', 'active')->exists();
            $lastDrill = EvacuationDrill::where('building_id', $building->id)->where('status', 'completed')->max('ended_at');
            $daysSinceLastDrill = $lastDrill ? (int) now()->diffInDays(Carbon::parse($lastDrill)) : 365;

            $score = min($incidentCount * 10, 40) + ($hasTeams ? 0 : 15) + ($hasAssemblyPoints ? 0 : 15) + min($daysSinceLastDrill / 10, 30);
            $riskLevel = match (true) { $score <= 20 => 'low', $score <= 50 => 'medium', $score <= 75 => 'high', default => 'critical' };
            $scores[] = [
                'building_id' => $building->id, 'building_name' => $building->name, 'risk_score' => round($score, 1), 'risk_level' => $riskLevel,
                'factors' => ['incidents_last_year' => $incidentCount, 'has_teams' => $hasTeams, 'has_assembly_points' => $hasAssemblyPoints, 'days_since_last_drill' => $daysSinceLastDrill],
            ];
        }
        usort($scores, fn ($a, $b) => $b['risk_score'] <=> $a['risk_score']);
        return $scores;
    }

    public function getExecutiveSummary(Carbon $from, Carbon $to, ?array $metrics = null): array
    {
        $metrics = $metrics ?? $this->getDashboardMetrics($from, $to);
        $summary = [
            'period' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            'key_metrics' => [
                'total_incidents' => $metrics['overview']['total_incidents'],
                'resolution_rate' => $metrics['overview']['resolution_rate'].'%',
                'avg_response_time' => $metrics['overview']['avg_response_time_minutes'].' دقيقة',
                'notification_success_rate' => $metrics['notification_stats']['success_rate'].'%',
            ],
            'highlights' => [], 'concerns' => [], 'recommendations' => [],
        ];
        if ($metrics['overview']['total_incidents'] > 0 && $metrics['overview']['resolution_rate'] >= 90) {
            $summary['highlights'][] = 'معدل إنهاء الحالات '.$metrics['overview']['resolution_rate'].'%';
        }
        if ($metrics['drill_effectiveness']['improvement_trend_percentage'] > 0) {
            $summary['highlights'][] = 'تحسّن زمن الإخلاء في التمارين بنسبة '.$metrics['drill_effectiveness']['improvement_trend_percentage'].'%';
        }
        $highRisk = collect($metrics['building_risk_scores'])->filter(fn ($b) => in_array($b['risk_level'], ['high', 'critical']))->count();
        if ($highRisk > 0) {
            $summary['concerns'][] = 'مبنى بمستوى خطر مرتفع أو حرج (لا فرق أو لا نقاط تجمع أو لا تمرين حديث)';
        }
        if ($metrics['drill_effectiveness']['total_drills'] === 0) {
            $summary['recommendations'][] = 'لم يُنفَّذ تمرين في الفترة — خطة الاستجابة تُعتمد ثم تُمرَّن (ملف المكان في اللوحة)';
        }
        if ($metrics['notification_stats']['total'] > 0 && $metrics['notification_stats']['success_rate'] < 95) {
            $summary['recommendations'][] = 'مراجعة قنوات التنبيه: بعض الإشعارات لم تُرسل';
        }
        if (($metrics['notification_stats']['by_channel']['phone_call']['manual'] ?? 0) > 0) {
            $summary['recommendations'][] = 'أعضاء فريق أولي وجهات اتصال بلا حساب أو بريد يُنادون هاتفياً — قرار قناة الرسائل النصية معلّق';
        }
        return $summary;
    }
}
