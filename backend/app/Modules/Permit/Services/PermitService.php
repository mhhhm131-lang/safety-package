<?php

namespace App\Modules\Permit\Services;

use App\Core\StateMachine\Exceptions\TransitionException;
use App\Modules\Permit\Models\Permit;
use App\Modules\Permit\Models\PermitDeviation;
use App\Modules\Permit\Models\PermitEvent;
use App\Modules\Permit\Models\PermitRequirement;
use App\Modules\Permit\Models\PermitRisk;
use App\Modules\Permit\Models\PermitType;
use App\Modules\Permit\StateMachines\PermitStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * العمليات الأساسية على التصاريح: الإنشاء، تغيير الحالة، البنود، السجل الزمني.
 *
 * كل إنشاء وكل انتقال يمرّ من هنا حتى:
 *   - تُفرض آلة الحالة والأدوار،
 *   - تُختم الطوابع الزمنية (تقديم، مراجعة، اعتماد) مع الحدث في معاملة واحدة،
 *   - يُولَّد رمز التصريح المتسلسل.
 *
 * المنطق الخاص بالفئة (اشتقاق البنود، التعارض، فحص العمال) في خدمات مستقلة يستدعيها هذا.
 */
class PermitService
{
    public function __construct(
        private readonly PermitStateMachine $stateMachine,
        private readonly PermitConflictService $conflicts,
        private readonly PermitRiskInheritanceService $inheritance,
        private readonly SmartJsaService $smartJsa,
        private readonly QualificationChecklistService $qualificationChecklist,
        private readonly WorkerGapRiskService $workerGaps,
        private readonly PermitNotifier $notifier,
    ) {}

    /**
     * إنشاء تصريح بحالة «مسودة» مع بنوده المشتقة ومخاطره.
     *
     * @param array{
     *   title: string, description?: ?string, scope?: ?string, parent_permit_id?: ?int,
     *   project_id?: ?int, external_party_id?: ?int, organization_unit_id?: ?int, place_id?: ?int,
     *   subject_type?: ?string, subject_id?: ?int, workers_count?: ?int, equipment_count?: ?int,
     *   location_description?: ?string, sub_location?: ?string, precautions?: ?string, additional_notes?: ?string,
     *   requester_name?: ?string, requester_phone?: ?string, starts_at?: mixed, expires_at?: mixed,
     *   requested_by_id?: ?int, trade_ids?: array<int>, metadata?: ?array
     * } $attributes
     */
    public function create(PermitType $type, array $attributes): Permit
    {
        $this->assertContext($type, $attributes);

        return DB::transaction(function () use ($type, $attributes) {
            $permit = Permit::create([
                'permit_type_id'       => $type->id,
                'permit_category'      => $type->category,
                'scope'                => $attributes['scope'] ?? null,
                'parent_permit_id'     => $attributes['parent_permit_id'] ?? null,
                'code'                 => $this->nextCode(),
                'title'                => $attributes['title'],
                'description'          => $attributes['description'] ?? null,
                'project_id'           => $attributes['project_id'] ?? null,
                'external_party_id'    => $attributes['external_party_id'] ?? null,
                'organization_unit_id' => $attributes['organization_unit_id'] ?? null,
                'place_id'             => $attributes['place_id'] ?? null,
                'subject_type'         => $attributes['subject_type'] ?? null,
                'subject_id'           => $attributes['subject_id'] ?? null,
                'status'               => Permit::STATUS_DRAFT,
                'workers_count'        => $attributes['workers_count'] ?? null,
                'equipment_count'      => $attributes['equipment_count'] ?? null,
                'location_description' => $attributes['location_description'] ?? null,
                'sub_location'         => $attributes['sub_location'] ?? null,
                'precautions'          => $attributes['precautions'] ?? null,
                'additional_notes'     => $attributes['additional_notes'] ?? null,
                'requester_name'       => $attributes['requester_name'] ?? null,
                'requester_phone'      => $attributes['requester_phone'] ?? null,
                'starts_at'            => $attributes['starts_at'] ?? null,
                'expires_at'           => $attributes['expires_at'] ?? $this->defaultExpiry($type, $attributes),
                'requested_by_id'      => $attributes['requested_by_id'] ?? null,
                'metadata'             => $attributes['metadata'] ?? null,
            ]);

            $permit->setRelation('type', $type);

            // المهن تُحفظ قبل اشتقاق البنود لأنها تضيّق بنود التحكم المقترحة.
            $tradeIds = $attributes['trade_ids'] ?? [];
            if ($tradeIds) {
                $permit->trades()->sync($tradeIds);
            }

            // المخاطر: وراثة من التصريح الأب + مخاطر المكان الفعّالة.
            $this->inheritance->inheritAndDerive($permit, $attributes['requested_by_id'] ?? null);

            // البنود: كتالوج التأهيل لتصاريح التأهيل، وبنود التحكم لغيرها.
            if ($type->category === PermitType::CATEGORY_QUALIFICATION) {
                $this->qualificationChecklist->generate($permit);
            } else {
                $this->smartJsa->generate($permit, $tradeIds);
            }

            $this->recordEvent($permit, 'created', [
                'type_code' => $type->code,
                'category'  => $type->category,
            ], $attributes['requested_by_id'] ?? null);

            return $permit;
        });
    }

    /**
     * نقل التصريح إلى حالة جديدة. آلة الحالة ترفض الانتقال غير المسموح،
     * والحرّاس هنا ترفض ما لا تُعبَّر عنه الحالة وحدها.
     */
    public function transition(
        Permit $permit,
        string $toStatus,
        ?int $userId = null,
        ?string $notes = null,
        ?string $ip = null,
    ): Permit {
        $from = $permit->status;
        $this->stateMachine->validate($from, $toStatus);

        $this->assertGuards($permit, $from, $toStatus, $notes);

        return DB::transaction(function () use ($permit, $from, $toStatus, $userId, $notes, $ip) {
            $updates = ['status' => $toStatus];

            if ($toStatus === Permit::STATUS_SUBMITTED && !$permit->submitted_at) {
                $updates['submitted_at'] = now();
            }
            if ($toStatus === Permit::STATUS_UNDER_REVIEW && !$permit->reviewed_at) {
                $updates['reviewed_at']    = now();
                $updates['reviewed_by_id'] = $userId;
            }
            if ($toStatus === Permit::STATUS_SAFETY_APPROVED && !$permit->safety_approved_at) {
                $updates['safety_approved_at']    = now();
                $updates['safety_approved_by_id'] = $userId;
            }
            if ($toStatus === Permit::STATUS_APPROVED && !$permit->approved_at) {
                $updates['approved_at']    = now();
                $updates['approved_by_id'] = $userId;
            }
            if ($toStatus === Permit::STATUS_REJECTED) {
                $updates['reviewed_at']      = $permit->reviewed_at ?? now();
                $updates['reviewed_by_id']   = $permit->reviewed_by_id ?? $userId;
                $updates['rejection_reason'] = $notes;
            }

            $permit->update($updates);

            $this->recordEvent(
                $permit, 'status_changed', ['from' => $from, 'to' => $toStatus],
                $userId, $notes, fromStatus: $from, toStatus: $toStatus, ip: $ip,
            );

            $fresh = $permit->refresh();
            $this->notifier->statusChanged($fresh, $from, $toStatus, $userId, $notes);

            return $fresh;
        });
    }

    /** حرّاس الانتقال التي تعتمد بيانات خارج الحالة نفسها. */
    private function assertGuards(Permit $permit, string $from, string $toStatus, ?string $notes): void
    {
        // قرار الرفض أو الإيقاف يلزمه سبب مكتوب.
        if (in_array($toStatus, [Permit::STATUS_REJECTED, Permit::STATUS_SUSPENDED], true) && trim((string) $notes) === '') {
            $label = $toStatus === Permit::STATUS_REJECTED ? 'الرفض' : 'الإيقاف';
            throw new TransitionException("قرار {$label} يتطلب سبباً مكتوباً.");
        }

        // اعتماد بمرحلتين: النوع يفرضه، أو خطر مرتبط درجته ≥ ١٥.
        if ($from === Permit::STATUS_UNDER_REVIEW && $toStatus === Permit::STATUS_APPROVED && $this->requiresTwoStage($permit)) {
            throw new TransitionException(
                'هذا التصريح يتطلب اعتماد السلامة أولاً (نوع عالي الخطورة أو خطر درجته ١٥ فأكثر): '
                .'انتقل إلى «معتمد من السلامة» قبل الاعتماد النهائي.'
            );
        }

        // التعارض وسعة المكان يمنعان الاعتماد والتفعيل.
        if (in_array($toStatus, [Permit::STATUS_APPROVED, Permit::STATUS_ACTIVE], true)) {
            $result = $this->conflicts->check($permit);
            if ($result['has_blocks']) {
                $messages = implode(' | ', array_column(
                    array_filter($result['conflicts'], fn ($c) => $c['severity'] === 'block'), 'message'
                ));
                throw new TransitionException("لا يمكن متابعة التصريح {$permit->code} — تعارض مانع: {$messages}");
            }
        }

        // التفعيل: البنود الإلزامية كلها، ولا ثغرة عالية في عامل معيَّن.
        if ($from === Permit::STATUS_APPROVED && $toStatus === Permit::STATUS_ACTIVE) {
            if (!$permit->requirementsSatisfied()) {
                throw new TransitionException(
                    "لا يمكن تفعيل التصريح {$permit->code} — بنود إلزامية لم تكتمل. أكملها من صفحة التفعيل."
                );
            }
            if ($permit->workers()->exists()) {
                $report = $this->workerGaps->reportForPermit($permit);
                $high = collect($report['gaps'])->flatMap(fn ($w) => $w['gaps'])->where('severity', 'high');
                if ($high->isNotEmpty()) {
                    $labels = $high->pluck('label')->unique()->implode('، ');
                    throw new TransitionException("لا يمكن تفعيل التصريح {$permit->code}: ثغرات في العمال — {$labels}.");
                }
            }
        }

        // الإغلاق لا يتم وفيه انحراف مفتوح (إضافة المعهد: الانحراف يُعالَج أو يُقبل بمبرر).
        if ($toStatus === Permit::STATUS_COMPLETED) {
            $open = $permit->deviations()->where('status', PermitDeviation::STATUS_OPEN)->count();
            if ($open > 0) {
                throw new TransitionException(
                    "لا يمكن إغلاق التصريح {$permit->code} — {$open} انحراف مفتوح. عالجه أو اقبله بمبرر أولاً."
                );
            }
        }

        // الانتهاء الآلي لا يقع قبل موعده.
        if ($toStatus === Permit::STATUS_EXPIRED && $permit->expires_at && $permit->expires_at->isFuture()) {
            throw new TransitionException("لا يمكن اعتبار التصريح {$permit->code} منتهياً — تاريخ الانتهاء لم يحل بعد.");
        }
    }

    /** هل يلزم هذا التصريح اعتماد بمرحلتين؟ */
    public function requiresTwoStage(Permit $permit): bool
    {
        if ($permit->type?->two_stage_approval) {
            return true;
        }
        $maxScore = $permit->risks()->max('risk_score');

        return $maxScore !== null && $maxScore >= 15;
    }

    /**
     * إضافة بند إلى التصريح. Upsert بالرمز حتى يُعاد الاشتقاق بلا تكرار.
     */
    public function addRequirement(
        Permit $permit,
        string $category,
        string $requirementCode,
        string $severity = PermitRequirement::SEVERITY_MANDATORY,
        ?int $referenceId = null,
        ?string $descriptionAr = null,
        ?string $notes = null,
    ): PermitRequirement {
        return PermitRequirement::updateOrCreate(
            ['permit_id' => $permit->id, 'requirement_code' => $requirementCode],
            [
                'category'       => $category,
                'reference_id'   => $referenceId,
                'description_ar' => $descriptionAr,
                'severity'       => $severity,
                'notes'          => $notes,
                'status'         => PermitRequirement::STATUS_REQUIRED,
            ],
        );
    }

    /** ربط مخاطر بالتصريح. لا يُكرَّر الخطر. يعيد عدد المضاف. */
    public function attachRisks(Permit $permit, array $riskIds, bool $autoSuggested = false, ?int $userId = null): int
    {
        if ($riskIds === []) {
            return 0;
        }

        $existing = PermitRisk::where('permit_id', $permit->id)->pluck('risk_id')->flip();
        $added = 0;
        $now = now();

        foreach (array_unique($riskIds) as $riskId) {
            if ($existing->has($riskId)) {
                continue;
            }
            PermitRisk::create([
                'permit_id'      => $permit->id,
                'risk_id'        => $riskId,
                'auto_suggested' => $autoSuggested,
                'added_at'       => $now,
                'added_by_id'    => $userId,
            ]);
            $existing[$riskId] = true;
            $added++;
        }

        if ($added > 0 && !$autoSuggested) {
            $this->recordEvent($permit, 'risks_attached', ['count' => $added], $userId);
        }

        return $added;
    }

    /** إتمام بند (رُفع دليله وتُحقق منه). */
    public function completeRequirement(PermitRequirement $req, ?int $verifierId = null, ?string $notes = null): PermitRequirement
    {
        $req->update([
            'status'          => PermitRequirement::STATUS_COMPLETED,
            'completed_by_id' => $req->completed_by_id ?? $verifierId,
            'completed_at'    => $req->completed_at ?? now(),
            'verified_by_id'  => $verifierId,
            'verified_at'     => now(),
            'notes'           => $notes ?? $req->notes,
        ]);

        $this->recordEvent($req->permit, 'requirement_completed', [
            'requirement_code' => $req->requirement_code,
            'label'            => $req->label(),
        ], $verifierId, $notes);

        return $req;
    }

    public function recordEvent(
        Permit $permit,
        string $eventType,
        array $changes = [],
        ?int $userId = null,
        ?string $notes = null,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $ip = null,
        ?float $lat = null,
        ?float $lng = null,
    ): PermitEvent {
        return PermitEvent::create([
            'permit_id'       => $permit->id,
            'event_type'      => $eventType,
            'from_status'     => $fromStatus,
            'to_status'       => $toStatus,
            'changes'         => $changes ?: null,
            'notes'           => $notes,
            'performed_by_id' => $userId,
            'signature_ip'    => $ip,
            'signature_lat'   => $lat,
            'signature_lng'   => $lng,
            'created_at'      => now(),
        ]);
    }

    /** ما يفرضه النوع من سياق: يُرفض الإنشاء ناقصاً بدل أن يبقى تصريح لا يمكن تقييمه. */
    private function assertContext(PermitType $type, array $attributes): void
    {
        $missing = [];
        if ($type->requires_project && empty($attributes['project_id'])) {
            $missing[] = 'المشروع مطلوب لهذا النوع';
        }
        if ($type->requires_contractor && empty($attributes['external_party_id'])) {
            $missing[] = 'المقاول (الطرف الخارجي) مطلوب لهذا النوع';
        }
        if ($type->requires_place && empty($attributes['place_id'])) {
            $missing[] = 'المكان مطلوب لهذا النوع';
        }
        if ($type->requires_worker && $this->subjectMissing($attributes, Permit::SUBJECT_WORKER)) {
            $missing[] = 'يجب تحديد العامل';
        }
        if ($type->requires_equipment && $this->subjectMissing($attributes, Permit::SUBJECT_EQUIPMENT)) {
            $missing[] = 'يجب تحديد المعدة';
        }
        if ($missing !== []) {
            throw new \InvalidArgumentException(implode(' — ', $missing));
        }
    }

    private function subjectMissing(array $attributes, string $expectedType): bool
    {
        return empty($attributes['subject_id']) || ($attributes['subject_type'] ?? null) !== $expectedType;
    }

    /** تاريخ الانتهاء من صلاحية النوع الافتراضية إن لم يُحدَّد (وإلا يبقى فارغاً). */
    private function defaultExpiry(PermitType $type, array $attributes): ?\DateTimeInterface
    {
        if (!$type->default_validity_days) {
            return null;
        }
        $start = $attributes['starts_at'] ?? null;
        $base  = $start ? \Illuminate\Support\Carbon::parse($start) : now();

        return $base->copy()->addDays($type->default_validity_days);
    }

    /** رمز التصريح: ت-<السنة>-<تسلسل من أربعة>. */
    private function nextCode(): string
    {
        $year = now()->format('Y');
        $prefix = "ت-{$year}-";

        $last = DB::table('permits')
            ->where('code', 'like', $prefix.'%')
            ->pluck('code')
            ->map(fn (string $code) => (int) mb_substr($code, mb_strlen($prefix)))
            ->max() ?? 0;

        return $prefix.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }
}
