<?php

namespace App\Modules\Permit\Services;

use App\Modules\Permit\Models\Permit;
use App\Modules\Worker\Models\Worker;

/**
 * جاهزية العامل للعمل — ستة فحوص في لقطة واحدة (من WorkPermit في OHSMS، «جاهزية البوابة»).
 *
 *   الحالة · التدريب الإلزامي · الشهادات · الفحص الطبي · تصريح نشط يغطيه · كفاءات مهنته
 *
 * تُقرأ من نتيجة WorkerGapRiskService (مصدر واحد لقواعد الثغرات) مضافاً إليها فحص التصريح.
 * لا تكتب سجلاً — الكتابة في GateService بعد قرار السماح أو المنع.
 */
class GateReadinessService
{
    public function __construct(private readonly WorkerGapRiskService $gaps) {}

    /**
     * @return array{worker_id: int, overall: string, checks: array<string, array{pass: bool, detail: string}>,
     *               denial_reasons: array<int, string>, permit_id: ?int, permit_code: ?string, computed_at: string}
     */
    public function computeForWorker(Worker $worker, ?int $placeId = null): array
    {
        $gaps = $this->gaps->gapsForWorker($worker);
        $byType = collect($gaps)->keyBy('type');

        $checks = [
            'status'          => $this->check($byType, ['status_not_authorized'], 'حالة العامل تسمح بالعمل ('.$worker->getStatusLabel().').'),
            'training'        => $this->check($byType, ['training_missing'], 'التدريب الإلزامي مكتمل.'),
            'certificates'    => $this->check($byType, ['certificates_expired'], 'الشهادات سارية.'),
            'medical'         => $this->check($byType, ['medical_missing', 'medical_expired'], 'الفحص الطبي ساري.'),
            'competency_gaps' => $this->check($byType, ['competency_gaps_outstanding'], 'لا كفاءات ناقصة مسجَّلة لمهنته.'),
        ];

        $permit = $this->coveringPermit($worker, $placeId);
        $checks['permit'] = $permit
            ? ['pass' => true, 'detail' => "تصريح نشط: {$permit->code} ({$permit->title})."]
            : ['pass' => false, 'detail' => 'لا تصريح نشط يغطي هذا العامل اليوم.'];

        $reasons = [];
        foreach ($checks as $key => $check) {
            if (!$check['pass']) {
                $reasons[] = match ($key) {
                    'status'          => 'status_not_authorized',
                    'training'        => 'training_incomplete',
                    'certificates'    => 'certificates_expired',
                    'medical'         => 'medical_expired',
                    'competency_gaps' => 'competency_gaps_outstanding',
                    'permit'          => 'no_active_permit',
                    default           => $key,
                };
            }
        }

        return [
            'worker_id'      => $worker->id,
            'overall'        => $reasons === [] ? 'allowed' : 'denied',
            'checks'         => $checks,
            'denial_reasons' => $reasons,
            'permit_id'      => $permit?->id,
            'permit_code'    => $permit?->code,
            'computed_at'    => now()->toIso8601String(),
        ];
    }

    /**
     * التصريح النشط الذي يغطي العامل الآن — بطريقين لا ثالث لهما:
     *   (أ) تصريح موضوعه هذا العامل بعينه (تصريح دخول فردي أو تفويض دور)، أو
     *   (ب) تصريح أُسند إليه صراحةً في `permit_workers`.
     *
     * **قرار المعهد:** تصريح المقاول العام **لا** يُعدّ تغطية ضمنية لكل عماله.
     * من لم يُسنَد صراحةً يُولَّد له تصريح دخول فردي آلياً عند الفحص
     * (IndividualPermitFactory) ما دام مقاوله يحمل تصريح عمل نشط — فيبقى لكل
     * عامل دخل أثر باسمه، وهذا هو المطلوب للمساءلة في المعهد.
     *
     * المكان — إن مُرّر — يطابق مكان التصريح أو يكون التصريح بلا مكان.
     */
    public function coveringPermit(Worker $worker, ?int $placeId = null): ?Permit
    {
        $now = now();

        $base = fn () => Permit::query()
            ->where('status', Permit::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', $now))
            ->when($placeId, fn ($q) => $q->where(fn ($inner) => $inner
                ->whereNull('place_id')->orWhere('place_id', $placeId)));

        $permit = $base()
            ->where('subject_type', Permit::SUBJECT_WORKER)
            ->where('subject_id', $worker->id)
            ->latest('id')->first();
        if ($permit) {
            return $permit;
        }

        return $base()
            ->whereHas('workers', fn ($q) => $q->where('worker_id', $worker->id))
            ->latest('id')->first();
    }

    /** @param \Illuminate\Support\Collection<string, array> $byType */
    private function check(\Illuminate\Support\Collection $byType, array $types, string $okDetail): array
    {
        foreach ($types as $type) {
            if ($byType->has($type)) {
                $gap = $byType[$type];
                return ['pass' => false, 'detail' => $gap['label'].($gap['detail'] ? " — {$gap['detail']}" : '')];
            }
        }

        return ['pass' => true, 'detail' => $okDetail];
    }
}
