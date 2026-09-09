<?php

namespace App\Modules\Permit\Services;

use App\Modules\Governance\Models\Place;
use App\Modules\Permit\Models\Equipment;
use App\Modules\Permit\Models\GateLog;
use App\Modules\Permit\Models\Permit;
use App\Modules\Permit\Models\PermitDeviation;
use Illuminate\Support\Facades\DB;

/** أرقام لوحة التصاريح: العدّادات، ما ينتهي قريباً، طابور المراجعة، سعة الأماكن، التعارضات، الانحرافات. */
class PermitDashboardService
{
    public function __construct(
        private readonly PermitConflictService $conflicts,
        private readonly PostClosureService $postClosure,
    ) {}

    /** @return array<string, array<string, int>> [الفئة => [الحالة => العدد]] */
    public function countsByCategoryAndStatus(): array
    {
        $out = [];
        foreach (Permit::query()->selectRaw('permit_category, status, COUNT(*) as n')->groupBy('permit_category', 'status')->get() as $row) {
            $out[$row->permit_category][$row->status] = (int) $row->n;
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public function expiringSoon(int $days = 30, int $limit = 20): array
    {
        return Permit::query()
            ->whereIn('status', [Permit::STATUS_APPROVED, Permit::STATUS_ACTIVE])
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays($days)])
            ->with('type:id,name')
            ->orderBy('expires_at')
            ->limit($limit)
            ->get(['id', 'code', 'title', 'permit_type_id', 'expires_at', 'status'])
            ->map(fn (Permit $p) => [
                'id'         => $p->id,
                'code'       => $p->code,
                'title'      => $p->title,
                'type_name'  => $p->type?->name,
                'expires_at' => $p->expires_at?->toDateString(),
                'days_left'  => (int) now()->startOfDay()->diffInDays($p->expires_at->startOfDay(), false),
                'status'     => $p->status,
            ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function reviewQueue(int $limit = 15): array
    {
        return Permit::query()
            ->whereIn('status', [Permit::STATUS_SUBMITTED, Permit::STATUS_UNDER_REVIEW, Permit::STATUS_SAFETY_APPROVED])
            ->with(['type:id,name', 'requestedBy:id,name'])
            ->orderBy('submitted_at')
            ->limit($limit)
            ->get(['id', 'code', 'title', 'permit_type_id', 'status', 'submitted_at', 'requested_by_id'])
            ->map(fn (Permit $p) => [
                'id'            => $p->id,
                'code'          => $p->code,
                'title'         => $p->title,
                'type_name'     => $p->type?->name,
                'status'        => $p->status,
                'status_label'  => $p->getStatusLabel(),
                'requested_by'  => $p->requestedBy?->name,
                'waiting_since' => $p->submitted_at?->diffForHumans(),
                'overdue'       => $p->submitted_at !== null && $p->submitted_at->lt(now()->subDay()),
            ])->all();
    }

    /** سعة الأماكن التسعة الآن. @return array<int, array<string, mixed>> */
    public function placeCapacities(): array
    {
        return Place::orderBy('sort')->get()
            ->map(fn (Place $p) => $this->conflicts->placeSnapshot($p))
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function activeConflicts(): array
    {
        return $this->conflicts->activeConflicts();
    }

    /** @return array{open: int, high_open: int, recent: \Illuminate\Support\Collection} */
    public function deviations(): array
    {
        $open = PermitDeviation::where('status', PermitDeviation::STATUS_OPEN);

        return [
            'open'      => (clone $open)->count(),
            'high_open' => (clone $open)->where('severity', PermitDeviation::SEVERITY_HIGH)->count(),
            'recent'    => PermitDeviation::with('permit:id,code,title')->latest('recorded_at')->limit(5)->get(),
        ];
    }

    /** @return array{total: int, overdue_inspection: int, out_of_service: int} */
    public function equipment(): array
    {
        return [
            'total'              => Equipment::count(),
            'overdue_inspection' => Equipment::whereNotNull('next_inspection_date')
                ->whereDate('next_inspection_date', '<', now()->toDateString())->count(),
            'out_of_service'     => Equipment::whereIn('status', ['out_of_service', 'maintenance'])->count(),
        ];
    }

    /** @return array{total_today: int, allowed_today: int, denied_today: int} */
    public function gateToday(): array
    {
        $rows = GateLog::whereDate('created_at', now()->toDateString())->get(['result']);

        return [
            'total_today'   => $rows->count(),
            'allowed_today' => $rows->where('result', 'allowed')->count(),
            'denied_today'  => $rows->where('result', 'denied')->count(),
        ];
    }

    public function controlsNeedingReview(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->postClosure->controlsNeedingReview();
    }

    /**
     * تقرير شهري للامتثال (يُقدَّم للجهات: الموارد البشرية والدفاع المدني).
     *
     * @return array<string, mixed>
     */
    public function monthlyReport(int $year, int $month): array
    {
        $start = \Illuminate\Support\Carbon::create($year, $month, 1)->startOfMonth();
        $end   = $start->copy()->endOfMonth();

        $issued = Permit::whereBetween('approved_at', [$start, $end]);
        $byCategory = (clone $issued)->selectRaw('permit_category, COUNT(*) as n')
            ->groupBy('permit_category')->pluck('n', 'permit_category')->all();

        $gate = GateLog::whereBetween('created_at', [$start, $end])->get(['result']);

        return [
            'period' => [
                'year' => $year, 'month' => $month,
                'starts_at' => $start->toDateString(), 'ends_at' => $end->toDateString(),
            ],
            'permits' => [
                'issued'              => (clone $issued)->count(),
                'active_now'          => Permit::where('status', Permit::STATUS_ACTIVE)->count(),
                'completed'           => Permit::where('status', Permit::STATUS_COMPLETED)
                    ->whereBetween('updated_at', [$start, $end])->count(),
                'expired'             => Permit::where('status', Permit::STATUS_EXPIRED)
                    ->whereBetween('expires_at', [$start, $end])->count(),
                'rejected'            => Permit::where('status', Permit::STATUS_REJECTED)
                    ->whereBetween('reviewed_at', [$start, $end])->count(),
                'by_qualification'    => (int) ($byCategory['qualification'] ?? 0),
                'by_work'             => (int) ($byCategory['work'] ?? 0),
                'by_worker'           => (int) ($byCategory['worker'] ?? 0),
                'by_equipment'        => (int) ($byCategory['equipment'] ?? 0),
                'by_special'          => (int) ($byCategory['special'] ?? 0),
            ],
            'deviations' => [
                'recorded' => PermitDeviation::whereBetween('recorded_at', [$start, $end])->count(),
                'high'     => PermitDeviation::whereBetween('recorded_at', [$start, $end])
                    ->where('severity', PermitDeviation::SEVERITY_HIGH)->count(),
                'open'     => PermitDeviation::where('status', PermitDeviation::STATUS_OPEN)->count(),
            ],
            'gate' => [
                'checks'  => $gate->count(),
                'allowed' => $gate->where('result', 'allowed')->count(),
                'denied'  => $gate->where('result', 'denied')->count(),
            ],
            'incidents_linked_to_permits' => DB::table('incidents')
                ->whereNotNull('permit_id')
                ->whereBetween('created_at', [$start, $end])->count(),
            'equipment' => $this->equipment(),
        ];
    }
}
