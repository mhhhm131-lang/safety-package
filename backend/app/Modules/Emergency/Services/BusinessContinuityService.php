<?php

namespace App\Modules\Emergency\Services;

use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyIncident;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * Business Continuity Service - BCM/BCP implementation
 *
 * Features:
 * - Business Impact Analysis (BIA)
 * - Recovery Time Objectives (RTO)
 * - Recovery Point Objectives (RPO)
 * - Continuity plan activation
 * - Resource allocation for recovery
 */
class BusinessContinuityService
{
    // Recovery priority levels
    const PRIORITY_CRITICAL = 1;
    const PRIORITY_HIGH = 2;
    const PRIORITY_MEDIUM = 3;
    const PRIORITY_LOW = 4;

    // Plan types
    const PLAN_BCP = 'business_continuity';
    const PLAN_DRP = 'disaster_recovery';
    const PLAN_IRP = 'incident_response';
    const PLAN_CMP = 'crisis_management';

    // Impact categories
    const IMPACT_FINANCIAL = 'financial';
    const IMPACT_OPERATIONAL = 'operational';
    const IMPACT_REPUTATIONAL = 'reputational';
    const IMPACT_REGULATORY = 'regulatory';
    const IMPACT_SAFETY = 'safety';

    /**
     * Perform Business Impact Analysis
     */
    public function performBIA(): array
    {
        $processes = $this->getBusinessProcesses();
        $analysis = [];

        foreach ($processes as $process) {
            $analysis[] = [
                'process' => $process,
                'criticality' => $this->assessCriticality($process),
                'rto' => $this->calculateRTO($process),
                'rpo' => $this->calculateRPO($process),
                'mtpd' => $this->calculateMTPD($process),
                'dependencies' => $this->identifyDependencies($process),
                'impact_assessment' => $this->assessImpact($process),
                'recovery_resources' => $this->identifyRecoveryResources($process),
            ];
        }

        // Sort by criticality
        usort($analysis, fn($a, $b) => $a['criticality']['priority'] <=> $b['criticality']['priority']);

        return [
            'analysis_date' => now()->toDateString(),
            'processes_analyzed' => count($analysis),
            'critical_processes' => count(array_filter(
                $analysis,
                fn($a) => $a['criticality']['priority'] === self::PRIORITY_CRITICAL
            )),
            'analysis' => $analysis,
            'summary' => $this->generateBIASummary($analysis),
            'recommendations' => $this->generateBIARecommendations($analysis),
        ];
    }

    /**
     * Create Business Continuity Plan
     */
    public function createBCP(array $bia): array
    {
        $criticalProcesses = array_filter(
            $bia['analysis'] ?? [],
            fn($a) => $a['criticality']['priority'] <= self::PRIORITY_HIGH
        );

        $bcp = [
            'plan_type' => self::PLAN_BCP,
            'version' => '1.0',
            'created_at' => now()->toISOString(),
            'last_tested' => null,
            'next_review' => now()->addMonths(6)->toDateString(),

            'scope' => [
                'processes_covered' => count($criticalProcesses),
                'buildings' => EmergencyBuilding::query()->pluck('name')->all(),
            ],

            'activation_criteria' => [
                ['condition' => 'Building inaccessible for >4 hours', 'priority' => 'critical'],
                ['condition' => 'IT systems down for >2 hours', 'priority' => 'high'],
                ['condition' => 'Key personnel unavailable >50%', 'priority' => 'high'],
                ['condition' => 'Utility failure >8 hours', 'priority' => 'medium'],
            ],

            'recovery_strategies' => $this->developRecoveryStrategies($criticalProcesses),

            'alternate_sites' => $this->identifyAlternateSites(),

            'communication_plan' => $this->createCommunicationPlan(),

            'resource_requirements' => $this->calculateResourceRequirements($criticalProcesses),

            'recovery_procedures' => array_map(
                fn($process) => $this->createRecoveryProcedure($process),
                $criticalProcesses
            ),

            'testing_schedule' => [
                ['type' => 'Tabletop Exercise', 'frequency' => 'Quarterly'],
                ['type' => 'Walkthrough', 'frequency' => 'Semi-annually'],
                ['type' => 'Full Simulation', 'frequency' => 'Annually'],
            ],
        ];

        // Store BCP
        Cache::put("bcp:institute", $bcp, now()->addYear());

        return $bcp;
    }

    /**
     * Activate continuity plan
     */
    public function activatePlan(
        string $planType,
        int $activatedBy,
        array $context = []
    ): array {
        $bcp = Cache::get("bcp:institute");

        if (!$bcp) {
            return ['success' => false, 'error' => 'لا خطة استمرارية محفوظة'];
        }

        $activation = [
            'id' => uniqid('act_'),
            'plan_type' => $planType,
            'activated_by' => $activatedBy,
            'activated_at' => now()->toISOString(),
            'status' => 'active',
            'context' => $context,
            'incident_type' => $context['incident_type'] ?? 'unknown',
            'affected_processes' => $context['affected_processes'] ?? [],
            'affected_buildings' => $context['affected_buildings'] ?? [],
        ];

        // Determine recovery actions needed
        $activation['recovery_actions'] = $this->determineRecoveryActions($bcp, $context);

        // Calculate estimated recovery time
        $activation['estimated_recovery'] = $this->estimateRecoveryTime($activation['recovery_actions']);

        // Store activation
        Cache::put("bcp_activation:institute", $activation, now()->addDays(30));

        // Log activation
        DB::table('emergency_bcp_activations')->insert([
            'activation_id' => $activation['id'],
            'plan_type' => $planType,
            'activated_by' => $activatedBy,
            'context' => json_encode($context),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'success' => true,
            'activation' => $activation,
        ];
    }

    /**
     * Track recovery progress
     */
    public function trackRecoveryProgress(): array
    {
        $activation = Cache::get("bcp_activation:institute");

        if (!$activation || $activation['status'] !== 'active') {
            return ['active' => false];
        }

        $progress = [];
        foreach ($activation['recovery_actions'] ?? [] as $action) {
            $actionStatus = Cache::get("recovery_action:{$action['id']}", [
                'status' => 'pending',
                'progress' => 0,
            ]);

            $progress[] = [
                'action' => $action,
                'status' => $actionStatus['status'],
                'progress_percent' => $actionStatus['progress'],
                'started_at' => $actionStatus['started_at'] ?? null,
                'completed_at' => $actionStatus['completed_at'] ?? null,
            ];
        }

        $overallProgress = count($progress) > 0
            ? array_sum(array_column($progress, 'progress_percent')) / count($progress)
            : 0;

        return [
            'active' => true,
            'activation_id' => $activation['id'],
            'activated_at' => $activation['activated_at'],
            'overall_progress' => round($overallProgress),
            'actions' => $progress,
            'estimated_completion' => $this->estimateCompletionTime($progress),
        ];
    }

    /**
     * Update recovery action status
     */
    public function updateActionStatus(string $actionId, string $status, int $progress = 0): bool
    {
        $actionStatus = [
            'status' => $status,
            'progress' => min(100, max(0, $progress)),
            'updated_at' => now()->toISOString(),
        ];

        if ($status === 'in_progress' && !Cache::has("recovery_action:{$actionId}")) {
            $actionStatus['started_at'] = now()->toISOString();
        }

        if ($status === 'completed') {
            $actionStatus['completed_at'] = now()->toISOString();
            $actionStatus['progress'] = 100;
        }

        Cache::put("recovery_action:{$actionId}", $actionStatus, now()->addDays(30));

        return true;
    }

    /**
     * Deactivate plan (recovery complete)
     */
    public function deactivatePlan(int $deactivatedBy, string $notes = ''): array
    {
        $activation = Cache::get("bcp_activation:institute");

        if (!$activation) {
            return ['success' => false, 'error' => 'No active plan'];
        }

        $activation['status'] = 'deactivated';
        $activation['deactivated_at'] = now()->toISOString();
        $activation['deactivated_by'] = $deactivatedBy;
        $activation['deactivation_notes'] = $notes;

        // Calculate actual recovery time
        $activatedAt = Carbon::parse($activation['activated_at']);
        $activation['actual_recovery_time'] = $activatedAt->diffInMinutes(now());

        Cache::put("bcp_activation:institute", $activation, now()->addDays(30));

        // Update database
        DB::table('emergency_bcp_activations')
            ->where('activation_id', $activation['id'])
            ->update([
                'status' => 'deactivated',
                'deactivated_at' => now(),
                'deactivated_by' => $deactivatedBy,
                'notes' => $notes,
                'updated_at' => now(),
            ]);

        return [
            'success' => true,
            'recovery_time_minutes' => $activation['actual_recovery_time'],
        ];
    }

    /**
     * Get alternate work locations
     */
    public function getAlternateLocations(int $requiredCapacity = 0): array
    {
        // Get pre-configured alternate sites
        $sites = Cache::get("alternate_sites:institute", []);

        if ($requiredCapacity > 0) {
            $sites = array_filter(
                $sites,
                fn($s) => ($s['capacity'] ?? 0) >= $requiredCapacity
            );
        }

        return [
            'sites' => $sites,
            'total_capacity' => array_sum(array_column($sites, 'capacity')),
        ];
    }

    /**
     * Generate recovery report
     */
    public function generateRecoveryReport(string $activationId): array
    {
        $activation = DB::table('emergency_bcp_activations')
            ->where('activation_id', $activationId)
            ->first();

        if (!$activation) {
            return ['error' => 'Activation not found'];
        }

        $activationData = json_decode($activation->context ?? '{}', true);

        return [
            'report_type' => 'Recovery Report',
            'activation_id' => $activationId,
            'generated_at' => now()->toISOString(),

            'incident_summary' => [
                'type' => $activationData['incident_type'] ?? 'unknown',
                'affected_processes' => $activationData['affected_processes'] ?? [],
                'affected_buildings' => $activationData['affected_buildings'] ?? [],
            ],

            'timeline' => [
                'incident_occurred' => $activationData['incident_time'] ?? null,
                'plan_activated' => $activation->created_at,
                'recovery_completed' => $activation->deactivated_at,
                'total_duration_minutes' => $activation->deactivated_at
                    ? Carbon::parse($activation->created_at)->diffInMinutes($activation->deactivated_at)
                    : null,
            ],

            'actions_taken' => $this->getActionHistory($activationId),

            'resources_used' => $this->getResourcesUsed($activationId),

            'lessons_learned' => $activation->notes ?? '',

            'recommendations' => $this->generateRecoveryRecommendations($activationData),
        ];
    }

    // Helper methods

    protected function getBusinessProcesses(): array
    {
        // This would query actual business processes from database
        return [
            ['id' => 'ops_1', 'name' => 'Safety Management', 'department' => 'HSE'],
            ['id' => 'ops_2', 'name' => 'Permit Processing', 'department' => 'Operations'],
            ['id' => 'ops_3', 'name' => 'Incident Reporting', 'department' => 'HSE'],
            ['id' => 'ops_4', 'name' => 'Training Management', 'department' => 'HR'],
            ['id' => 'ops_5', 'name' => 'Equipment Inspection', 'department' => 'Maintenance'],
        ];
    }

    protected function assessCriticality(array $process): array
    {
        // Assess based on department and process type
        $criticalProcesses = ['Safety Management', 'Incident Reporting', 'Permit Processing'];

        $isCritical = in_array($process['name'], $criticalProcesses);

        return [
            'priority' => $isCritical ? self::PRIORITY_CRITICAL : self::PRIORITY_MEDIUM,
            'label' => $isCritical ? 'Critical' : 'Important',
            'justification' => $isCritical
                ? 'Core safety/compliance function'
                : 'Supports business operations',
        ];
    }

    protected function calculateRTO(array $process): array
    {
        $criticalRTO = ['Safety Management' => 2, 'Incident Reporting' => 1, 'Permit Processing' => 4];
        $hours = $criticalRTO[$process['name']] ?? 24;

        return [
            'hours' => $hours,
            'formatted' => $hours < 24 ? "{$hours} hours" : round($hours / 24) . ' days',
        ];
    }

    protected function calculateRPO(array $process): array
    {
        $criticalRPO = ['Safety Management' => 1, 'Incident Reporting' => 0, 'Permit Processing' => 4];
        $hours = $criticalRPO[$process['name']] ?? 24;

        return [
            'hours' => $hours,
            'formatted' => $hours < 24 ? "{$hours} hours" : round($hours / 24) . ' days',
            'data_loss_acceptable' => $hours > 0,
        ];
    }

    protected function calculateMTPD(array $process): array
    {
        // Maximum Tolerable Period of Disruption
        $mtpd = ($this->calculateRTO($process)['hours'] ?? 24) * 2;

        return [
            'hours' => $mtpd,
            'formatted' => $mtpd < 48 ? "{$mtpd} hours" : round($mtpd / 24) . ' days',
        ];
    }

    protected function identifyDependencies(array $process): array
    {
        return [
            'systems' => ['OHSMS Platform', 'Email', 'Network'],
            'personnel' => ['HSE Manager', 'System Administrator'],
            'facilities' => ['Main Office', 'Server Room'],
            'external' => ['Internet Provider', 'Cloud Services'],
        ];
    }

    protected function assessImpact(array $process): array
    {
        return [
            self::IMPACT_FINANCIAL => ['level' => 'medium', 'description' => 'Operational delays'],
            self::IMPACT_OPERATIONAL => ['level' => 'high', 'description' => 'Core function disruption'],
            self::IMPACT_REPUTATIONAL => ['level' => 'medium', 'description' => 'Client confidence'],
            self::IMPACT_REGULATORY => ['level' => 'high', 'description' => 'Compliance requirements'],
            self::IMPACT_SAFETY => ['level' => 'critical', 'description' => 'Safety management affected'],
        ];
    }

    protected function identifyRecoveryResources(array $process): array
    {
        return [
            'minimum_staff' => 2,
            'equipment' => ['Laptops', 'Internet Access', 'Phone'],
            'systems' => ['OHSMS Platform', 'Email'],
            'data' => ['Last backup', 'Cloud sync'],
        ];
    }

    protected function generateBIASummary(array $analysis): array
    {
        $critical = count(array_filter($analysis, fn($a) => $a['criticality']['priority'] === 1));
        $high = count(array_filter($analysis, fn($a) => $a['criticality']['priority'] === 2));

        $shortestRTO = min(array_column(array_column($analysis, 'rto'), 'hours') ?: [24]);

        return [
            'total_processes' => count($analysis),
            'critical_count' => $critical,
            'high_priority_count' => $high,
            'shortest_rto_hours' => $shortestRTO,
            'key_dependencies' => ['OHSMS Platform', 'Network Infrastructure', 'Cloud Services'],
        ];
    }

    protected function generateBIARecommendations(array $analysis): array
    {
        return [
            ['priority' => 'high', 'recommendation' => 'Implement redundant system access'],
            ['priority' => 'high', 'recommendation' => 'Establish alternate work site agreements'],
            ['priority' => 'medium', 'recommendation' => 'Cross-train key personnel'],
            ['priority' => 'medium', 'recommendation' => 'Test backup restoration quarterly'],
        ];
    }

    protected function developRecoveryStrategies(array $processes): array
    {
        return [
            'work_from_home' => [
                'description' => 'Enable remote work for critical staff',
                'activation_time' => '1 hour',
                'capacity' => '80%',
            ],
            'alternate_site' => [
                'description' => 'Relocate to pre-arranged alternate facility',
                'activation_time' => '4 hours',
                'capacity' => '100%',
            ],
            'manual_procedures' => [
                'description' => 'Fallback to paper-based procedures',
                'activation_time' => 'Immediate',
                'capacity' => '50%',
            ],
        ];
    }

    protected function identifyAlternateSites(): array
    {
        return [
            ['name' => 'Remote Work', 'type' => 'distributed', 'capacity' => 100, 'setup_time' => '1 hour'],
            ['name' => 'Partner Office', 'type' => 'hot_site', 'capacity' => 30, 'setup_time' => '2 hours'],
        ];
    }

    protected function createCommunicationPlan(): array
    {
        return [
            'internal' => ['Email', 'SMS', 'WhatsApp Group', 'Phone Tree'],
            'external' => ['Client notification', 'Regulatory reporting', 'Media statement'],
            'escalation' => ['Department Heads', 'Executive Team', 'Board of Directors'],
        ];
    }

    protected function calculateResourceRequirements(array $processes): array
    {
        return [
            'personnel' => ['minimum' => 5, 'optimal' => 15],
            'equipment' => ['laptops' => 10, 'phones' => 15],
            'facilities' => ['workstations' => 10, 'meeting_rooms' => 2],
        ];
    }

    protected function createRecoveryProcedure(array $process): array
    {
        return [
            'process_id' => $process['id'],
            'process_name' => $process['name'],
            'steps' => [
                ['order' => 1, 'action' => 'Assess current status', 'responsible' => 'Team Lead'],
                ['order' => 2, 'action' => 'Activate alternate resources', 'responsible' => 'IT'],
                ['order' => 3, 'action' => 'Restore critical data', 'responsible' => 'IT'],
                ['order' => 4, 'action' => 'Resume operations', 'responsible' => 'Team Lead'],
                ['order' => 5, 'action' => 'Verify functionality', 'responsible' => 'QA'],
            ],
        ];
    }

    protected function determineRecoveryActions(array $bcp, array $context): array
    {
        $actions = [];

        // Based on context, determine needed actions
        if (in_array('building_inaccessible', $context['conditions'] ?? [])) {
            $actions[] = [
                'id' => uniqid('act_'),
                'type' => 'activate_alternate_site',
                'priority' => 1,
                'description' => 'Activate alternate work location',
                'estimated_time' => 120,
            ];
        }

        if (in_array('it_systems_down', $context['conditions'] ?? [])) {
            $actions[] = [
                'id' => uniqid('act_'),
                'type' => 'restore_systems',
                'priority' => 1,
                'description' => 'Restore IT systems from backup',
                'estimated_time' => 240,
            ];
        }

        // Add standard recovery actions
        $actions[] = [
            'id' => uniqid('act_'),
            'type' => 'notify_stakeholders',
            'priority' => 1,
            'description' => 'Notify all stakeholders',
            'estimated_time' => 30,
        ];

        return $actions;
    }

    protected function estimateRecoveryTime(array $actions): array
    {
        $totalMinutes = array_sum(array_column($actions, 'estimated_time'));

        return [
            'minutes' => $totalMinutes,
            'hours' => round($totalMinutes / 60, 1),
            'formatted' => $totalMinutes < 60
                ? "{$totalMinutes} minutes"
                : round($totalMinutes / 60, 1) . ' hours',
        ];
    }

    protected function estimateCompletionTime(array $progress): ?string
    {
        $pending = array_filter($progress, fn($p) => $p['status'] !== 'completed');

        if (empty($pending)) {
            return 'Complete';
        }

        $totalRemaining = array_sum(array_map(
            fn($p) => ($p['action']['estimated_time'] ?? 60) * (1 - ($p['progress_percent'] / 100)),
            $pending
        ));

        return now()->addMinutes($totalRemaining)->format('Y-m-d H:i');
    }

    protected function getActionHistory(string $activationId): array
    {
        // This would query actual action history
        return [];
    }

    protected function getResourcesUsed(string $activationId): array
    {
        return [
            'personnel_hours' => 0,
            'equipment' => [],
            'facilities' => [],
        ];
    }

    protected function generateRecoveryRecommendations(array $activationData): array
    {
        return [
            'Update BCP based on lessons learned',
            'Schedule follow-up drill within 30 days',
            'Review and update contact lists',
        ];
    }
}
