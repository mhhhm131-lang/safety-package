<?php

namespace App\Modules\Permit\Services;

use App\Modules\Governance\Models\Place;
use App\Modules\Permit\Models\Permit;
use App\Modules\Permit\Models\PermitRequirement;
use App\Modules\Permit\Models\PermitType;
use App\Modules\Permit\Models\PermitTypeConflictRule;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Project\Models\ProjectContractor;
use App\Modules\Worker\Models\Worker;

/**
 * يجيب سؤالين:
 *   ١. `canIssue`: قبل الإنشاء — هل تحقّق كل شرط؟ (يستعمله المعالج قبل حفظ المسودة)
 *   ٢. `blockers`: لتصريح قائم — ما الذي يمنع الخطوة التالية؟ (لوحة «الإجراء التالي» في صفحة التصريح)
 *
 * الرسائل عربية جاهزة للعرض كما هي.
 */
class PermitEligibilityService
{
    public function __construct(
        private readonly PermitConflictService $conflicts,
        private readonly WorkerGapRiskService $workerGaps,
    ) {}

    /**
     * @param array{project_id?: ?int, external_party_id?: ?int, organization_unit_id?: ?int,
     *              place_id?: ?int, subject_type?: ?string, subject_id?: ?int,
     *              workers_count?: ?int, equipment_count?: ?int} $context
     */
    public function canIssue(string $permitTypeCode, array $context): EligibilityResult
    {
        $type = PermitType::where('code', $permitTypeCode)->first();
        if (!$type || !$type->is_active) {
            return EligibilityResult::blocked(["نوع التصريح «{$permitTypeCode}» غير متاح."]);
        }

        $blockers = [];
        $warnings = [];

        if ($type->requires_project && empty($context['project_id'])) {
            $blockers[] = 'يجب اختيار المشروع.';
        }
        if ($type->requires_contractor && empty($context['external_party_id'])) {
            $blockers[] = 'يجب اختيار المقاول.';
        }
        if ($type->requires_place && empty($context['place_id'])) {
            $blockers[] = 'يجب اختيار المكان.';
        }
        if ($type->requires_worker && empty($context['subject_id'])) {
            $blockers[] = 'يجب تحديد العامل.';
        }
        if ($type->requires_equipment && empty($context['subject_id'])) {
            $blockers[] = 'يجب تحديد المعدة.';
        }

        if ($blockers !== []) {
            return EligibilityResult::blocked($blockers); // بلا السياق الأساسي لا معنى لبقية الفحوص
        }

        if (!empty($context['external_party_id'])) {
            $this->checkContractor($type, $context, $blockers, $warnings);
        }

        if ($type->requires_worker && !empty($context['subject_id'])) {
            $this->checkWorker((int) $context['subject_id'], $blockers);
        }

        if (!empty($context['place_id'])) {
            $this->checkPlaceCapacity($type, $context, $blockers, $warnings);
        }

        $this->warnIfDuplicate($type, $context, $warnings);

        return $blockers === []
            ? EligibilityResult::ok($warnings)
            : EligibilityResult::blocked($blockers, $warnings);
    }

    /**
     * ما يمنع الخطوة التالية لتصريح قائم.
     *
     * @return array<int, string>
     */
    public function blockers(Permit $permit): array
    {
        $out = [];

        // بنود إلزامية ناقصة.
        $open = $permit->requirements()
            ->where('severity', PermitRequirement::SEVERITY_MANDATORY)
            ->whereNotIn('status', [PermitRequirement::STATUS_COMPLETED, PermitRequirement::STATUS_WAIVED])
            ->with('riskControl')
            ->get();
        foreach ($open as $req) {
            $phase = $req->phase ? " [{$req->phaseLabel()}]" : '';
            $out[] = "بند إلزامي ناقص{$phase}: {$req->label()}";
        }

        // تصريح عمل لمقاول: لا يُفعَّل قبل اعتماد تأهيله اللاحق على المشروع.
        if ($permit->permit_category === PermitType::CATEGORY_WORK && $permit->external_party_id && $permit->project_id) {
            $pc = ProjectContractor::where('project_id', $permit->project_id)
                ->where('external_party_id', $permit->external_party_id)
                ->first();
            if (!$pc) {
                $out[] = 'المقاول غير مسجَّل على هذا المشروع.';
            } elseif ($pc->qualification_status !== 'post_approved') {
                $out[] = 'المقاول لم يجتز تأهيل ما بعد التعاقد على هذا المشروع (حالته: '.$pc->getStatusLabel().').';
            }
        }

        // انتهت مدته.
        if ($permit->expires_at && $permit->expires_at->isPast()) {
            $out[] = 'تاريخ الانتهاء مضى — حدِّثه قبل المتابعة.';
        }

        // تعارض مانع أو تجاوز سعة.
        $conflict = $this->conflicts->check($permit);
        foreach ($conflict['conflicts'] as $c) {
            if ($c['severity'] === PermitTypeConflictRule::SEVERITY_BLOCK) {
                $out[] = $c['message'];
            }
        }

        // ثغرات العمال العالية + انحرافات مفتوحة.
        if ($permit->workers()->exists()) {
            $report = $this->workerGaps->reportForPermit($permit);
            foreach ($report['gaps'] as $entry) {
                foreach ($entry['gaps'] as $gap) {
                    if ($gap['severity'] === 'high') {
                        $out[] = "العامل {$entry['worker_name']}: {$gap['label']}";
                    }
                }
            }
        }

        if ($permit->status === Permit::STATUS_ACTIVE) {
            $openDev = $permit->deviations()->where('status', 'open')->count();
            if ($openDev > 0) {
                $out[] = "{$openDev} انحراف مفتوح — يجب معالجته قبل الإغلاق.";
            }
        }

        return $out;
    }

    // ── داخلي ──

    private function checkContractor(PermitType $type, array $context, array &$blockers, array &$warnings): void
    {
        $party = ExternalParty::find($context['external_party_id']);
        if (!$party) {
            $blockers[] = 'المقاول المحدد غير موجود.';
            return;
        }
        if ($party->status === 'blocked') {
            $blockers[] = "المقاول «{$party->name}» محظور — لا تُصدر له تصاريح.";
            return;
        }

        // تصريح التأهيل هو وسيلة الاعتماد نفسها، فلا يُشترط اعتماد سابق.
        if ($type->category === PermitType::CATEGORY_QUALIFICATION || empty($context['project_id'])) {
            return;
        }

        $pc = ProjectContractor::where('project_id', $context['project_id'])
            ->where('external_party_id', $party->id)
            ->first();

        if (!$pc) {
            $blockers[] = 'المقاول غير مسجَّل على هذا المشروع.';
        } elseif ($pc->qualification_status !== 'post_approved') {
            $blockers[] = "المقاول في حالة «{$pc->getStatusLabel()}» — لا يُصدر تصريح عمل قبل اعتماد تأهيل ما بعد التعاقد.";
        }
    }

    private function checkWorker(int $workerId, array &$blockers): void
    {
        $worker = Worker::find($workerId);
        if (!$worker) {
            $blockers[] = 'العامل المحدد غير موجود.';
            return;
        }
        foreach ($this->workerGaps->gapsForWorker($worker) as $gap) {
            if ($gap['severity'] === 'high') {
                $blockers[] = "العامل {$worker->full_name}: {$gap['label']}";
            }
        }
    }

    private function checkPlaceCapacity(PermitType $type, array $context, array &$blockers, array &$warnings): void
    {
        if ($type->category === PermitType::CATEGORY_QUALIFICATION) {
            return;
        }
        $place = Place::find($context['place_id']);
        if (!$place) {
            return;
        }

        $snapshot = $this->conflicts->placeSnapshot($place);
        $plannedWorkers = (int) ($context['workers_count'] ?? 0);
        $plannedEquip   = (int) ($context['equipment_count'] ?? 0);

        if ($place->max_workers) {
            $projected = $snapshot['active_workers'] + $plannedWorkers;
            if ($projected > $place->max_workers) {
                $blockers[] = "المكان «{$place->name}» سيتجاوز حد العمال ({$place->max_workers}) — المجموع {$projected}.";
            } elseif ($projected >= (int) round($place->max_workers * 0.8)) {
                $warnings[] = "المكان «{$place->name}» سيعمل بـ{$projected} من {$place->max_workers} عاملاً.";
            }
        }

        if ($place->max_equipment) {
            $projected = $snapshot['active_equipment'] + $plannedEquip;
            if ($projected > $place->max_equipment) {
                $blockers[] = "المكان «{$place->name}» سيتجاوز حد المعدات ({$place->max_equipment}) — المجموع {$projected}.";
            }
        }
    }

    /** تصريح مطابق قائم: تنبيه لا منع (قد يلزم تصريح ثانٍ لوردية أخرى). */
    private function warnIfDuplicate(PermitType $type, array $context, array &$warnings): void
    {
        $exists = Permit::query()
            ->where('permit_type_id', $type->id)
            ->whereIn('status', [
                Permit::STATUS_ACTIVE, Permit::STATUS_APPROVED,
                Permit::STATUS_SAFETY_APPROVED, Permit::STATUS_UNDER_REVIEW,
            ])
            ->when(!empty($context['project_id']), fn ($q) => $q->where('project_id', $context['project_id']))
            ->when(!empty($context['external_party_id']), fn ($q) => $q->where('external_party_id', $context['external_party_id']))
            ->when(!empty($context['place_id']), fn ($q) => $q->where('place_id', $context['place_id']))
            ->when(!empty($context['subject_type']) && !empty($context['subject_id']), fn ($q) => $q
                ->where('subject_type', $context['subject_type'])->where('subject_id', $context['subject_id']))
            ->exists();

        if ($exists) {
            $warnings[] = 'يوجد تصريح نشط أو قيد المراجعة من النوع نفسه للسياق نفسه — تحقّق قبل الإنشاء.';
        }
    }
}
