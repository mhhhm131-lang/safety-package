<?php

namespace App\Modules\Permit\Services;

use App\Core\Services\NotificationService;
use App\Modules\Permit\Models\GateLog;
use App\Modules\Worker\Models\Worker;
use Illuminate\Support\Collection;

/**
 * فحص عامل قبل السماح له بالعمل، وتسجيل النتيجة.
 * يُستدعى من شاشة الجاهزية (بحث بالاسم أو الهوية) — لا حاجة لقارئ بطاقات.
 * عند المنع يُشعَر مسؤول السلامة والمناوب (قناتا §٦).
 */
class GateService
{
    public function __construct(
        private readonly GateReadinessService $readiness,
        private readonly IndividualPermitFactory $individualPermits,
        private readonly NotificationService $inbox,
    ) {}

    /**
     * @return array{result: string, worker: ?Worker, checks: array, denial_reasons: array<int, string>,
     *               permit_code: ?string, auto_permit: bool}
     */
    public function checkWorker(string $identifier, ?int $placeId = null, string $gateName = 'main', ?int $scannedById = null): array
    {
        $worker = Worker::with(['trade', 'externalParty'])
            ->where(fn ($q) => $q
                ->where('national_id', $identifier)
                ->orWhere('id', is_numeric($identifier) ? (int) $identifier : 0))
            ->first();

        if (!$worker) {
            GateLog::create([
                'worker_id' => null, 'place_id' => $placeId, 'gate_name' => $gateName,
                'result' => 'denied', 'denial_reason' => 'worker_not_found', 'checks' => [],
                'scanned_by_id' => $scannedById, 'created_at' => now(),
            ]);

            return [
                'result' => 'denied', 'worker' => null, 'checks' => [],
                'denial_reasons' => ['worker_not_found'], 'permit_code' => null, 'auto_permit' => false,
            ];
        }

        $snapshot = $this->readiness->computeForWorker($worker, $placeId);
        $checks   = $snapshot['checks'];
        $reasons  = $snapshot['denial_reasons'];
        $permitId = $snapshot['permit_id'];
        $permitCode = $snapshot['permit_code'];
        $autoPermit = false;

        // إن كان المانع الوحيد غياب التصريح، ولمقاوله تصريح تشغيلي نشط:
        // يُولَّد تصريح دخول فردي لليوم آلياً (الاعتماد التنظيمي تمّ على تصريح المقاول).
        if ($reasons === ['no_active_permit']) {
            $auto = $this->individualPermits->tryCreate($worker, $placeId, $scannedById);
            if ($auto) {
                $reasons = [];
                $permitId = $auto->id;
                $permitCode = $auto->code;
                $autoPermit = true;
                $checks['permit'] = ['pass' => true, 'detail' => "تصريح دخول فردي أُنشئ آلياً: {$auto->code}."];
            }
        }

        $result = $reasons === [] ? 'allowed' : 'denied';

        GateLog::create([
            'worker_id' => $worker->id, 'place_id' => $placeId, 'permit_id' => $permitId,
            'gate_name' => $gateName, 'result' => $result,
            'denial_reason' => $result === 'denied' ? implode(', ', $reasons) : null,
            'checks' => $checks, 'scanned_by_id' => $scannedById, 'created_at' => now(),
        ]);

        if ($result === 'denied') {
            $labels = collect($reasons)->map(fn ($r) => GateLog::denialLabel($r))->implode('، ');
            $this->inbox->notifyRoles(
                ['system_admin', 'system_staff'],
                'gate.denied',
                'مُنع عامل من العمل',
                "العامل «{$worker->full_name}» مُنع عند الفحص. الأسباب: {$labels}.",
                '/app/permits/gate/logs',
            );
        }

        return [
            'result' => $result, 'worker' => $worker, 'checks' => $checks,
            'denial_reasons' => $reasons, 'permit_code' => $permitCode, 'auto_permit' => $autoPermit,
        ];
    }

    /** @return array{total_today: int, allowed_today: int, denied_today: int} */
    public function statsToday(): array
    {
        $rows = GateLog::whereDate('created_at', now()->toDateString())->get(['result']);

        return [
            'total_today'   => $rows->count(),
            'allowed_today' => $rows->where('result', 'allowed')->count(),
            'denied_today'  => $rows->where('result', 'denied')->count(),
        ];
    }

    /** @return Collection<int, GateLog> */
    public function recentDenied(int $hours = 24): Collection
    {
        return GateLog::where('result', 'denied')
            ->where('created_at', '>=', now()->subHours($hours))
            ->with(['worker', 'place'])
            ->latest('created_at')
            ->get();
    }
}
