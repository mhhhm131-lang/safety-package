<?php

namespace App\Modules\Risk\Services;

use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskPhase;
use App\Modules\Risk\Models\RiskPhaseAffectedGroupDetail;
use Illuminate\Support\Facades\DB;

/**
 * نسخ المخاطر بين السجلات الثلاثة (من OHSMS بلا tenant/project/party/trades/activities).
 * كتاب المخاطر (master) ← السجل العام (reference) ← سجل الإدارة (active).
 * مصحَّح: ينسخ المراحل الثلاث بأسبابها ومتأثريها وتفاصيلهم (OHSMS كان لا ينسخ الضوابط).
 */
class RiskCopyService
{
    /** من الكتاب إلى السجل العام — يعيد النسخة القائمة إن وُجدت. */
    public function masterToReference(Risk $risk, ?int $userId): Risk
    {
        $existing = Risk::where('parent_reference_id', $risk->id)->where('risk_type', 'reference')->first();
        if ($existing) {
            return $existing;
        }
        return $this->copyRisk($risk, [
            'risk_type' => 'reference', 'parent_reference_id' => $risk->id,
            'created_by_id' => $userId, 'status' => 'draft',
        ]);
    }

    /** من الكتاب مباشرة إلى سجل وحدة تنظيمية. */
    public function toOrgUnitActive(Risk $risk, int $orgUnitId, ?int $userId): Risk
    {
        return $this->copyRisk($risk, [
            'risk_type' => 'active', 'parent_reference_id' => $risk->id, 'scope_type' => 'org_unit',
            'organization_unit_id' => $orgUnitId, 'created_by_id' => $userId, 'status' => 'draft',
        ]);
    }

    /** تفعيل خطر من السجل العام في سجل إدارة/مكان مع تجاوزات. */
    public function referenceToActive(Risk $reference, ?int $userId, array $overrides = []): Risk
    {
        $base = ['risk_type' => 'active', 'parent_reference_id' => $reference->id, 'created_by_id' => $userId, 'status' => 'active'];
        return $this->copyRisk($reference, array_merge($base, $overrides));
    }

    /**
     * نسخ مجموعة من الكتاب بتجاوزات لكل خطر.
     * @return array{created: Risk[], skipped: array, errors: array}
     */
    public function bulkCopyWithOverrides(array $items, string $destination, ?int $destinationId, ?int $userId): array
    {
        $created = [];
        $skipped = [];
        $errors = [];
        DB::transaction(function () use ($items, $destination, $destinationId, $userId, &$created, &$skipped, &$errors) {
            foreach ($items as $item) {
                $riskId = (int) ($item['risk_id'] ?? 0);
                if (!$riskId) continue;
                $source = Risk::find($riskId);
                if (!$source) {
                    $skipped[] = ['risk_id' => $riskId, 'reason' => 'not_found'];
                    continue;
                }
                try {
                    $newRisk = match ($destination) {
                        'reference' => $this->masterToReference($source, $userId),
                        'org_unit' => $this->toOrgUnitActive($source, (int) $destinationId, $userId),
                        default => throw new \InvalidArgumentException("Invalid destination: {$destination}"),
                    };
                    $overrides = $item['overrides'] ?? [];
                    $patch = [];
                    foreach (['title', 'description', 'severity', 'likelihood', 'benefit'] as $field) {
                        if (array_key_exists($field, $overrides) && $overrides[$field] !== null && $overrides[$field] !== '') {
                            $patch[$field] = $overrides[$field];
                        }
                    }
                    if ($patch) {
                        if (isset($patch['severity']) || isset($patch['likelihood'])) {
                            $patch['risk_score'] = (int) ($patch['severity'] ?? $newRisk->severity) * (int) ($patch['likelihood'] ?? $newRisk->likelihood);
                        }
                        $newRisk->fill($patch)->save();
                    }
                    $created[] = $newRisk;
                } catch (\Throwable $e) {
                    $errors[] = ['risk_id' => $riskId, 'message' => $e->getMessage()];
                }
            }
        });
        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }

    private function copyRisk(Risk $source, array $overrides): Risk
    {
        return DB::transaction(function () use ($source, $overrides) {
            $data = [];
            foreach (['title', 'description', 'category_id', 'sub_category_id', 'risk_type_category_id', 'severity', 'likelihood',
                'risk_score', 'benefit', 'contact_channel', 'legal_reference', 'place_id'] as $field) {
                $data[$field] = $source->{$field};
            }
            $newRisk = Risk::create(array_merge($data, $overrides));
            $this->copyPhases($source, $newRisk);
            $this->ensurePhases($newRisk);
            $this->copyControls($source, $newRisk);
            return $newRisk;
        });
    }

    /** نسخ المراحل الثلاث: الإجراءات، الأسباب، المتأثرون وتفاصيلهم، والجهة والشخص (المعهد واحد فلا تُسقط المفاتيح). */
    private function copyPhases(Risk $source, Risk $target): void
    {
        $source->loadMissing(['phases.causes', 'phases.affectedGroups', 'phases.affectedGroupDetails']);
        foreach ($source->phases as $srcPhase) {
            $newPhase = RiskPhase::create([
                'risk_id' => $target->id, 'phase' => $srcPhase->phase,
                'preventive_action' => $srcPhase->preventive_action, 'corrective_action' => $srcPhase->corrective_action,
                'residual_assessment' => $srcPhase->residual_assessment,
                'responsible_org_unit_id' => $srcPhase->responsible_org_unit_id, 'responsible_org_unit_text' => $srcPhase->responsible_org_unit_text,
                'responsible_user_id' => $srcPhase->responsible_user_id, 'responsible_user_text' => $srcPhase->responsible_user_text,
                'notes' => $srcPhase->notes,
            ]);
            $causeIds = $srcPhase->causes->pluck('id')->all();
            if ($causeIds) $newPhase->causes()->sync($causeIds);
            $groupIds = $srcPhase->affectedGroups->pluck('id')->all();
            if ($groupIds) $newPhase->affectedGroups()->sync($groupIds);
            foreach ($srcPhase->affectedGroupDetails as $d) {
                RiskPhaseAffectedGroupDetail::create([
                    'risk_phase_id' => $newPhase->id, 'affected_group_id' => $d->affected_group_id,
                    'impact' => $d->impact, 'rep_scope' => $d->rep_scope, 'impact_description' => $d->impact_description,
                    'details' => $d->details, 'cascading_effects' => $d->cascading_effects,
                ]);
            }
        }
    }

    /** الإصلاح الثالث في BACKEND.md ٥-٢: OHSMS لا ينسخ بنود التحكم الخاصة بالخطر عند النسخ بين السجلات. */
    private function copyControls(Risk $source, Risk $target): void
    {
        foreach ($source->controls()->orderBy('sort_order')->get() as $control) {
            $copy = $control->replicate(['review_flag', 'review_notes', 'flag_count']);
            $copy->risk_id = $target->id;
            $copy->save();
        }
    }

    private function ensurePhases(Risk $risk): void
    {
        foreach (RiskPhase::PHASES as $phase) {
            RiskPhase::firstOrCreate(['risk_id' => $risk->id, 'phase' => $phase]);
        }
    }
}
