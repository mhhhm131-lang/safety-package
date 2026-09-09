<?php

namespace App\Modules\Risk\StateMachines;

use App\Core\StateMachine\StateMachine;

class RiskStateMachine extends StateMachine
{
    public function __construct()
    {
        parent::__construct([
            'draft' => [
                'pending_approval' => ['any'],
            ],
            'pending_approval' => [
                'approved' => ['system_admin', 'system_staff', 'top_management', 'safety_committee'],
                'rejected' => ['system_admin', 'system_staff', 'top_management', 'safety_committee'],
            ],
            'rejected' => [
                'draft' => ['any'],
            ],
            'approved' => [
                'active' => ['system_admin', 'system_staff', 'safety_coordinator'],
            ],
            'active' => [
                'in_progress' => ['safety_coordinator', 'system_admin', 'system_staff'],
                'escalated' => ['safety_coordinator', 'system_admin', 'system_staff'],
            ],
            'in_progress' => [
                'closed' => ['safety_coordinator', 'system_admin', 'system_staff'],
            ],
            'escalated' => [
                'in_progress' => ['system_admin', 'system_staff'],
                'closed' => ['system_admin', 'system_staff'],
            ],
        ], \App\Modules\Risk\Models\Risk::STATUS_LABELS);
    }
}
