<?php

namespace App\Modules\Project\StateMachines;

use App\Core\StateMachine\StateMachine;
use App\Modules\Project\Models\ProjectContractor;

/**
 * Transition rules for ProjectContractor.qualification_status.
 *
 * Stage flow (happy path):
 *   draft → pre_review → pre_approved → post_review → post_approved
 *
 * Regressions:
 *   pre_review  → draft        (docs incomplete, sent back)
 *   post_review → pre_approved (worker readiness failed, sent back)
 *
 * Terminal / cascading:
 *   (any)          → suspended  (admin intervention, incident, doc expiry)
 *   suspended      → post_approved (reinstated after fixes)
 *   (any)          → expired    (triggered by scheduler when expires_at < today)
 */
class ProjectContractorStateMachine extends StateMachine
{
    public function __construct()
    {
        parent::__construct([
            ProjectContractor::STATUS_DRAFT => [
                ProjectContractor::STATUS_PRE_REVIEW => ['any'],
            ],
            ProjectContractor::STATUS_PRE_REVIEW => [
                ProjectContractor::STATUS_PRE_APPROVED => ['system_admin', 'system_staff', 'safety_coordinator'],
                ProjectContractor::STATUS_DRAFT => ['system_admin', 'system_staff', 'safety_coordinator'],
            ],
            ProjectContractor::STATUS_PRE_APPROVED => [
                ProjectContractor::STATUS_POST_REVIEW => ['any'],
            ],
            ProjectContractor::STATUS_POST_REVIEW => [
                ProjectContractor::STATUS_POST_APPROVED => ['system_admin', 'system_staff', 'safety_coordinator'],
                ProjectContractor::STATUS_PRE_APPROVED => ['system_admin', 'system_staff', 'safety_coordinator'],
            ],
            ProjectContractor::STATUS_SUSPENDED => [
                ProjectContractor::STATUS_POST_APPROVED => ['system_admin', 'safety_coordinator'],
            ],
            // Any state → suspended (admin-driven freeze, cascades from
            // party status, document expiry, incident, etc.) or expired
            // (scheduler-driven based on expires_at).
            'any' => [
                ProjectContractor::STATUS_SUSPENDED => ['system_admin', 'safety_coordinator'],
                ProjectContractor::STATUS_EXPIRED => ['system'],
            ],
        ]);
    }
}
