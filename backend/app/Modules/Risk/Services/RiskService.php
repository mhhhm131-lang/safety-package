<?php

namespace App\Modules\Risk\Services;

use App\Core\Services\NotificationService;
use App\Core\StateMachine\Exceptions\TransitionException;
use App\Modules\Risk\Models\AffectedGroup;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCause;
use App\Modules\Risk\Models\RiskEvent;
use App\Modules\Risk\Models\RiskPhase;
use App\Modules\Risk\Models\RiskPhaseAffectedGroupDetail;
use App\Modules\Risk\Services\RiskCopyService;
use App\Modules\Risk\StateMachines\RiskStateMachine;
use Illuminate\Support\Facades\DB;

class RiskService
{
    protected NotificationService $notificationService;
    protected RiskStateMachine $stateMachine;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
        $this->stateMachine = new RiskStateMachine();
    }

    /**
     * Create a new risk record with optional causes and affected groups.
     */
    public function createRisk(?int $userId, array $data, string $riskType = 'active'): Risk
    {
        return DB::transaction(function () use ($userId, $data, $riskType) {
            $risk = Risk::create(array_merge($data, [
                'created_by_id' => $userId,
                'risk_type' => $riskType,
                'risk_score' => ($data['severity'] ?? 1) * ($data['likelihood'] ?? 1),
                'status' => 'draft',
            ]));

            $this->ensurePhases($risk);

            RiskEvent::create([
                'risk_id' => $risk->id,
                'action' => 'created',
                'to_status' => 'draft',
                'actor_id' => $userId,
            ]);

            return $risk;
        });
    }

    /**
     * Make sure the risk has a row for each of the three phases
     * (proactive/operational/response). Idempotent — uses firstOrCreate
     * so callers that partially pre-populate phases won't get duplicates.
     * Downstream code (copy service, forms) can then fill phase content.
     */
    public function ensurePhases(Risk $risk): void
    {
        foreach (RiskPhase::PHASES as $phase) {
            RiskPhase::firstOrCreate([
                'risk_id' => $risk->id,
                'phase'   => $phase,
            ]);
        }
    }

    /**
     * Apply a single phase's form payload onto the phase row — scalar
     * fields, causes (free-text → RiskCause firstOrCreate), affected
     * groups with per-phase impact/rep_scope details. Safe to call
     * repeatedly; pivots sync destructively (missing IDs = detached).
     */
    public function persistPhaseContent(RiskPhase $phase, array $input): void
    {
        // مصحَّح: لا يُمحى ما لم يُرسل (التفعيل الجزئي يرث إجراءات الكتاب)
        $scalar = [];
        foreach (['preventive_action', 'corrective_action', 'residual_assessment', 'responsible_org_unit_id',
            'responsible_org_unit_text', 'responsible_user_id', 'responsible_user_text', 'notes'] as $key) {
            if (array_key_exists($key, $input)) {
                $scalar[$key] = $input[$key];
            }
        }
        if ($scalar) {
            $phase->update($scalar);
        }

        if (!array_key_exists('cause_names', $input) && !array_key_exists('affected_group_ids', $input)) {
            return;
        }
        $causeIds = [];
        foreach (($input['cause_names'] ?? []) as $name) {
            $name = trim((string) $name);
            if ($name === '') continue;
            $cause = RiskCause::firstOrCreate(['name' => $name]);
            $causeIds[] = $cause->id;
        }
        if (array_key_exists('cause_names', $input)) {
            $phase->causes()->sync($causeIds);
        }
        if (!array_key_exists('affected_group_ids', $input)) {
            return;
        }
        $groupIds = $input['affected_group_ids'] ?? [];
        $phase->affectedGroups()->sync($groupIds);
        foreach ($groupIds as $gid) {
            RiskPhaseAffectedGroupDetail::updateOrCreate(
                ['risk_phase_id' => $phase->id, 'affected_group_id' => $gid],
                [
                    'impact'    => $input['affected_impact'][$gid] ?? 'medium',
                    'rep_scope' => $input['affected_rep_scope'][$gid] ?? null,
                    'impact_description' => $input['affected_detail'][$gid] ?? null,
                ]
            );
        }
    }

    /**
     * Persist form input for phases on a risk. Only phases explicitly present
     * in $phasesInput are updated — absent keys are skipped to prevent
     * accidental data loss from partial form submissions.
     */
    public function persistAllPhases(Risk $risk, array $phasesInput): void
    {
        $this->ensurePhases($risk);

        foreach (RiskPhase::PHASES as $phaseKey) {
            if (!array_key_exists($phaseKey, $phasesInput)) continue;
            $phase = $risk->phases()->where('phase', $phaseKey)->first();
            if (!$phase) continue;
            $this->persistPhaseContent($phase, $phasesInput[$phaseKey]);
        }
    }

    /**
     * Update an existing risk's fields, causes, and affected groups.
     */
    public function updateRisk(Risk $risk, int $userId, array $data): Risk
    {
        return DB::transaction(function () use ($risk, $userId, $data) {
            $fromStatus = $risk->status;

            // Extra keys (legacy corrective_action / causes / affected_groups
            // at the risk level) are ignored — phase data is handled by
            // persistAllPhases in the controller flow.
            $risk->update($data);

            RiskEvent::create([
                'risk_id' => $risk->id,
                'action' => 'modified',
                'from_status' => $fromStatus,
                'to_status' => $risk->status,
                'actor_id' => $userId,
            ]);

            return $risk->fresh();
        });
    }

    /**
     * Submit a draft risk for approval.
     */
    public function submitForApproval(Risk $risk, int $userId): Risk
    {
        $this->stateMachine->validate($risk->status, 'pending_approval');

        return DB::transaction(function () use ($risk, $userId) {
            $fromStatus = $risk->status;

            $risk->update(['status' => 'pending_approval']);

            RiskEvent::create([
                'risk_id' => $risk->id,
                'action' => 'submitted',
                'from_status' => $fromStatus,
                'to_status' => 'pending_approval',
                'actor_id' => $userId,
            ]);

            $this->notifyApprovers($risk);

            return $risk->fresh();
        });
    }

    /** قرار ٦٩: التنبيه يصل من يعتمد هذا الخطر — مدير وحدته (أو ما فوقها)، أو مسؤول السلامة للعام. قرار ٧١: الدفعة تنبيه واحد بعددها */
    private function notifyApprovers(Risk $risk, int $count = 1): void
    {
        foreach (\App\Modules\Risk\Support\RiskApproval::approverIds($risk) as $approverId) {
            if ($count > 1) {
                $where = $risk->organizationUnit ? ' في «'.$risk->organizationUnit->name.'»' : '';
                $this->notificationService->create($approverId, 'risk.approve', 'أخطار بانتظار اعتمادك',
                    "فُعّلت {$count} أخطار من السجل العام{$where} وتنتظر اعتمادك.", '/app/risk/approval/queue');
                continue;
            }
            $this->notificationService->create($approverId, 'risk.approve', 'خطر بانتظار اعتمادك',
                "الخطر «{$risk->title}» قُدّم للاعتماد.", '/app/risk/approval/queue');
        }
    }

    /**
     * Approve a pending risk.
     */
    public function approve(Risk $risk, int $userId, ?string $notes = null): Risk
    {
        $this->stateMachine->validate($risk->status, 'approved');

        return DB::transaction(function () use ($risk, $userId, $notes) {
            $fromStatus = $risk->status;
            // قرار ٧٠: خطر فُعّل من السجل العام وانتظر معتمده ← ضغطة الاعتماد نفسها تجعله نشطاً، لا «معتمد» ينتظر خطوة ثانية بلا صاحب
            $toStatus = $risk->risk_type === 'active' && $risk->events()->where('action', 'activated')->exists() ? 'active' : 'approved';

            $risk->update([
                'status' => $toStatus,
                'approved_by_id' => $userId,
                'approved_at' => now(),
                'approval_notes' => $notes,
            ]);

            RiskEvent::create([
                'risk_id' => $risk->id,
                'action' => 'approved',
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'note' => $notes,
                'actor_id' => $userId,
            ]);

            return $risk->fresh();
        });
    }

    /**
     * Reject a pending risk.
     */
    public function reject(Risk $risk, int $userId, ?string $notes = null): Risk
    {
        $this->stateMachine->validate($risk->status, 'rejected');

        return DB::transaction(function () use ($risk, $userId, $notes) {
            $fromStatus = $risk->status;

            $risk->update([
                'status' => 'rejected',
                'approval_notes' => $notes,
            ]);

            RiskEvent::create([
                'risk_id' => $risk->id,
                'action' => 'rejected',
                'from_status' => $fromStatus,
                'to_status' => 'rejected',
                'note' => $notes,
                'actor_id' => $userId,
            ]);

            return $risk->fresh();
        });
    }

    /**
     * Activate an approved risk.
     */
    public function activate(Risk $risk, int $userId): Risk
    {
        $this->stateMachine->validate($risk->status, 'active');

        return DB::transaction(function () use ($risk, $userId) {
            $fromStatus = $risk->status;

            $risk->update(['status' => 'active']);

            RiskEvent::create([
                'risk_id' => $risk->id,
                'action' => 'activated',
                'from_status' => $fromStatus,
                'to_status' => 'active',
                'actor_id' => $userId,
            ]);

            return $risk->fresh();
        });
    }

    /**
     * Generic status change using state machine validation.
     */
    public function changeStatus(Risk $risk, int $userId, string $newStatus, ?string $note = null): Risk
    {
        $this->stateMachine->validate($risk->status, $newStatus);

        return DB::transaction(function () use ($risk, $userId, $newStatus, $note) {
            $fromStatus = $risk->status;

            $risk->update(['status' => $newStatus]);

            RiskEvent::create([
                'risk_id' => $risk->id,
                'action' => 'status_changed',
                'from_status' => $fromStatus,
                'to_status' => $newStatus,
                'note' => $note,
                'actor_id' => $userId,
            ]);

            return $risk->fresh();
        });
    }

    /**
     * Activate a reference risk into an active (specific) risk.
     * Delegates the copy mechanics — scalar fields, pivots, AND the
     * three phases with their full per-phase data — to RiskCopyService
     * so the active register inherits everything the tenant tailored in
     * their reference register. Runtime overrides (scope, severity
     * tweaks, assignments) are layered on top. Phase-level overrides
     * from the activation form are then applied via persistAllPhases.
     *
     * قرار ٧٠: `$awaitApproval` — من لا يعتمد هذا الخطر (المنسق لوحدته، مسؤول السلامة والمناوب لإدارة، المناوب للعام)
     * يفعّله «بانتظار الاعتماد» ويُنبَّه معتمده؛ والمعتمد نفسه والبذر نشط فوراً. الحكم في `RiskApproval::activatesDirectly`.
     */
    public function activateFromReference(Risk $referenceRisk, ?int $userId, array $data, bool $awaitApproval = false, bool $notify = true): Risk
    {
        return DB::transaction(function () use ($referenceRisk, $userId, $data, $awaitApproval, $notify) {
            $severity = $data['severity'] ?? $referenceRisk->severity;
            $likelihood = $data['likelihood'] ?? $referenceRisk->likelihood;

            $overrides = array_filter([
                'title'                    => $data['title'] ?? null,
                'description'              => $data['description'] ?? null,
                'contact_channel'          => $data['contact_channel'] ?? null,
                'scope_type'               => $data['scope_type'] ?? null,
                'organization_unit_id'     => $data['organization_unit_id'] ?? null,
                'place_id'                 => $data['place_id'] ?? null,
                'severity'                 => $severity,
                'likelihood'               => $likelihood,
                'risk_score'               => (int) $severity * (int) $likelihood,
                'assigned_coordinator_id'  => $data['assigned_coordinator_id'] ?? null,
                'assigned_field_team_id'   => $data['assigned_field_team_id'] ?? null,
                'target_closure_date'      => $data['target_closure_date'] ?? null,
                'notes'                    => $data['notes'] ?? null,
                'legal_reference'          => $data['legal_reference'] ?? null,
            ], fn ($v) => $v !== null && $v !== '');
            if ($awaitApproval) {
                $overrides['status'] = 'pending_approval';
            }

            $activeRisk = app(RiskCopyService::class)
                ->referenceToActive($referenceRisk, $userId, $overrides);

            if (!empty($data['phases']) && is_array($data['phases'])) {
                $this->persistAllPhases($activeRisk, $data['phases']);
            }

            RiskEvent::create([
                'risk_id'    => $activeRisk->id,
                'action'     => 'activated',
                'to_status'  => $activeRisk->status,
                'note'       => 'فُعّل من السجل العام ' . ($referenceRisk->code ?: '#'.$referenceRisk->id)
                    . ($awaitApproval ? ' — ينتظر اعتماد معتمده ليصير نشطاً' : ''),
                'actor_id'   => $userId,
            ]);

            if ($awaitApproval && $notify) {
                $this->notifyApprovers($activeRisk);
            }

            return $activeRisk;
        });
    }

    /**
     * قرار ٧١: تفعيل دفعة من السجل العام لوحدة واحدة (أو للمعهد كله) بمنسق ومعالج واحدين.
     * يُتخطّى ما ليس معتمداً في السجل العام، وما هو موجود أصلاً في سجل الوحدة (ولو ينتظر الاعتماد) — لا تكرار.
     * الشدة والاحتمال والمراحل تُنسخ كما هي. تنبيه المعتمد (قرار ٧٠) على المستدعي: `notifyActivationAwaiting` مرة واحدة بالعدد.
     *
     * @param array<int> $referenceIds
     * @return array{created: Risk[], existing: int, unapproved: int}
     */
    public function activateManyFromReference(array $referenceIds, ?int $userId, array $data, bool $awaitApproval = false): array
    {
        $ids = array_values(array_unique(array_map('intval', $referenceIds)));
        $refs = Risk::where('risk_type', 'reference')->whereIn('id', $ids)->whereIn('status', ['approved', 'active'])->orderBy('id')->get();
        $unitId = !empty($data['organization_unit_id']) ? (int) $data['organization_unit_id'] : null;

        return DB::transaction(function () use ($refs, $ids, $userId, $data, $unitId, $awaitApproval) {
            $created = [];
            $existing = 0;
            foreach ($refs as $ref) {
                $there = Risk::where('risk_type', 'active')->where('parent_reference_id', $ref->id)->whereNotIn('status', ['rejected', 'closed'])
                    ->when($unitId, fn ($q) => $q->where('organization_unit_id', $unitId), fn ($q) => $q->whereNull('organization_unit_id'))->exists();
                if ($there) {
                    $existing++;
                    continue;
                }
                $created[] = $this->activateFromReference($ref, $userId, $data, $awaitApproval, false);
            }
            return ['created' => $created, 'existing' => $existing, 'unapproved' => count($ids) - $refs->count()];
        });
    }

    /** قرار ٧١: تنبيه معتمد الدفعة مرة واحدة بعددها (خطر واحد: تنبيهه المفرد باسمه) */
    public function notifyActivationAwaiting(Risk $risk, int $count): void
    {
        $this->notifyApprovers($risk, $count);
    }

}
