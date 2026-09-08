<?php

namespace App\Modules\Incident\Observers;

use App\Modules\Incident\Models\Incident;
use App\Modules\Incident\StateMachines\IncidentStateMachine;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskPhase;
use InvalidArgumentException;

/**
 * يفرض آلة الحالة وقاعدة «كل بلاغ ينتمي إلى خطر معروف» عند طبقة النموذج (من OHSMS بلا tenant).
 * الاستثناء: السري قد يُرسل بلا خطر ويربطه مسؤول السلامة لاحقاً.
 * عند الإنشاء يرث من الخطر: الوحدة، المنسق، الفني، الإجراءين التصحيحي والوقائي (ما لم يُحدَّد يدوياً).
 * تجاوز للاختبارات: Incident::withoutEvents(fn () => ...).
 */
class IncidentObserver
{
    public function updating(Incident $incident): void
    {
        if (!$incident->isDirty('status')) return;
        $from = $incident->getOriginal('status');
        $to = $incident->status;
        if ($from === null || $from === $to) return;
        (new IncidentStateMachine())->validate($from, $to);
    }

    public function creating(Incident $incident): void
    {
        if ($incident->status === null || $incident->status === '') {
            $incident->status = 'new';
        }
        if (!in_array($incident->status, Incident::STATUSES, true)) {
            throw new InvalidArgumentException("Unknown incident status '{$incident->status}'. Must be one of: ".implode(', ', Incident::STATUSES));
        }
        if ($incident->risk_id === null && $incident->incident_type !== 'secret') {
            throw new InvalidArgumentException('Incident creation requires risk_id. Use the risks registry to pick a main category → sub-category → risk type.');
        }
        if ($incident->risk_id !== null) {
            $this->inheritFromRisk($incident);
        }
    }

    private function inheritFromRisk(Incident $incident): void
    {
        $risk = Risk::find($incident->risk_id);
        if (!$risk) {
            throw new InvalidArgumentException("Incident.risk_id={$incident->risk_id} does not point to an existing Risk row.");
        }
        if ($incident->organization_unit_id === null && $risk->organization_unit_id) {
            $incident->organization_unit_id = $risk->organization_unit_id;
        }
        if ($incident->place_id === null && $risk->place_id) {
            $incident->place_id = $risk->place_id;
        }
        if ($incident->incident_coordinator_id === null && $risk->assigned_coordinator_id) {
            $incident->incident_coordinator_id = $risk->assigned_coordinator_id;
        }
        if ($incident->incident_field_team_id === null && $risk->assigned_field_team_id) {
            $incident->incident_field_team_id = $risk->assigned_field_team_id;
        }
        $proactive = $risk->phases()->where('phase', RiskPhase::PHASE_PROACTIVE)->first();
        if ($proactive) {
            if (empty($incident->corrective_action) && !empty($proactive->corrective_action)) {
                $incident->corrective_action = $proactive->corrective_action;
            }
            if (empty($incident->preventive_action) && !empty($proactive->preventive_action)) {
                $incident->preventive_action = $proactive->preventive_action;
            }
        }
    }
}
