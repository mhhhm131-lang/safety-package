<?php

namespace App\Modules\Emergency\Services;

use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Models\EmergencyTeam;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Incident Command Service - ICS/NIMS compliant incident management
 *
 * Implements:
 * - National Incident Management System (NIMS)
 * - Incident Command System (ICS)
 * - Unified Command structure
 * - Resource tracking
 * - Operational periods
 */
class IncidentCommandService
{
    // ICS Command Structure Positions
    const POSITION_INCIDENT_COMMANDER = 'incident_commander';
    const POSITION_OPERATIONS_CHIEF = 'operations_chief';
    const POSITION_PLANNING_CHIEF = 'planning_chief';
    const POSITION_LOGISTICS_CHIEF = 'logistics_chief';
    const POSITION_FINANCE_CHIEF = 'finance_chief';
    const POSITION_SAFETY_OFFICER = 'safety_officer';
    const POSITION_PUBLIC_INFO_OFFICER = 'public_information_officer';
    const POSITION_LIAISON_OFFICER = 'liaison_officer';

    // ICS Forms
    const FORM_201 = 'incident_briefing';
    const FORM_202 = 'incident_objectives';
    const FORM_203 = 'organization_assignment';
    const FORM_204 = 'division_assignment';
    const FORM_205 = 'communications_plan';
    const FORM_206 = 'medical_plan';
    const FORM_207 = 'organization_chart';
    const FORM_209 = 'incident_status_summary';
    const FORM_214 = 'activity_log';

    // Incident Types (NIMS)
    const TYPE_1 = 1; // Complex incident - national resources
    const TYPE_2 = 2; // Significant incident - regional resources
    const TYPE_3 = 3; // Extended incident - requires multiple resources
    const TYPE_4 = 4; // Limited incident - local resources
    const TYPE_5 = 5; // Initial incident - single resource

    /**
     * Establish Incident Command for an incident
     */
    public function establishCommand(EmergencyIncident $incident, int $commanderId): array
    {
        $ics = [
            'incident_id' => $incident->id,
            'incident_name' => $this->generateIncidentName($incident),
            'incident_type' => $this->determineIncidentType($incident),
            'established_at' => now()->toISOString(),
            'operational_period' => [
                'start' => now()->toISOString(),
                'end' => now()->addHours(12)->toISOString(),
            ],
            'command_structure' => [
                self::POSITION_INCIDENT_COMMANDER => $commanderId,
                self::POSITION_SAFETY_OFFICER => null,
                self::POSITION_PUBLIC_INFO_OFFICER => null,
                self::POSITION_LIAISON_OFFICER => null,
                self::POSITION_OPERATIONS_CHIEF => null,
                self::POSITION_PLANNING_CHIEF => null,
                self::POSITION_LOGISTICS_CHIEF => null,
                self::POSITION_FINANCE_CHIEF => null,
            ],
            'resources' => [],
            'objectives' => [],
            'status' => 'active',
        ];

        // Store ICS data
        $incident->update([
            'ics_data' => $ics,
            'escalation_level' => self::TYPE_5,
        ]);

        // Log command establishment
        $this->logIcs($incident, [
            'action' => 'ics_established',
            'details' => [
                'incident_commander' => $commanderId,
                'incident_type' => $ics['incident_type'],
            ],
            'user_id' => $commanderId,
            'logged_at' => now(),
        ]);

        Log::info("[ICS] Incident Command established", [
            'incident_id' => $incident->id,
            'commander' => $commanderId,
        ]);

        return $ics;
    }

    /**
     * Assign position in command structure
     */
    public function assignPosition(EmergencyIncident $incident, string $position, int $userId): array
    {
        $ics = $incident->ics_data ?? [];

        if (!isset($ics['command_structure'])) {
            return ['error' => 'ICS not established'];
        }

        $previousHolder = $ics['command_structure'][$position] ?? null;
        $ics['command_structure'][$position] = $userId;

        $incident->update(['ics_data' => $ics]);

        // Log assignment
        $this->logIcs($incident, [
            'action' => 'ics_position_assigned',
            'details' => [
                'position' => $position,
                'assigned_to' => $userId,
                'previous_holder' => $previousHolder,
            ],
            'user_id' => auth()->id(),
            'logged_at' => now(),
        ]);

        return [
            'success' => true,
            'position' => $position,
            'assigned_to' => $userId,
            'previous_holder' => $previousHolder,
        ];
    }

    /**
     * Generate ICS Form 201 - Incident Briefing
     */
    public function generateForm201(EmergencyIncident $incident): array
    {
        $building = $incident->building;
        $ics = $incident->ics_data ?? [];

        return [
            'form' => 'ICS-201',
            'title' => 'Incident Briefing',
            'incident_name' => $ics['incident_name'] ?? $incident->incident_type,
            'prepared_by' => auth()->user()?->name,
            'date_prepared' => now()->toDateString(),
            'time_prepared' => now()->toTimeString(),

            'sections' => [
                '1_map_sketch' => [
                    'building' => $building->name,
                    'address' => $building->address,
                    'floors' => $building->getTotalFloors(),
                    'assembly_points' => $building->assemblyPoints->pluck('name')->all(),
                ],
                '2_summary' => [
                    'type' => $incident->incident_type,
                    'started' => $incident->triggered_at->toDateTimeString(),
                    'duration' => $incident->triggered_at->diffForHumans(),
                    'severity' => $incident->severity ?? 'unknown',
                    'personnel_affected' => $incident->getStats()['total'],
                    'personnel_accounted' => $incident->getStats()['safe'],
                    'personnel_missing' => $incident->getStats()['missing'],
                ],
                '3_objectives' => $ics['objectives'] ?? [],
                '4_current_organization' => $this->buildOrgChart($ics),
                '5_resources' => $ics['resources'] ?? [],
            ],
        ];
    }

    /**
     * Generate ICS Form 202 - Incident Objectives
     */
    public function generateForm202(EmergencyIncident $incident): array
    {
        $ics = $incident->ics_data ?? [];

        return [
            'form' => 'ICS-202',
            'title' => 'Incident Objectives',
            'incident_name' => $ics['incident_name'] ?? $incident->incident_type,
            'operational_period' => $ics['operational_period'] ?? null,
            'prepared_by' => auth()->user()?->name,

            'objectives' => $ics['objectives'] ?? [
                [
                    'number' => 1,
                    'objective' => 'Ensure life safety of all personnel',
                    'priority' => 'high',
                ],
                [
                    'number' => 2,
                    'objective' => 'Account for all personnel',
                    'priority' => 'high',
                ],
                [
                    'number' => 3,
                    'objective' => 'Stabilize the incident',
                    'priority' => 'medium',
                ],
                [
                    'number' => 4,
                    'objective' => 'Protect property and environment',
                    'priority' => 'medium',
                ],
            ],

            'weather' => [
                'current' => null,
                'forecast' => null,
            ],

            'safety_message' => 'All personnel must check in at assembly point. Do not re-enter building until all-clear is given.',
        ];
    }

    /**
     * Generate ICS Form 209 - Incident Status Summary
     */
    public function generateForm209(EmergencyIncident $incident): array
    {
        $ics = $incident->ics_data ?? [];
        $logs = $incident->eventLogs()->reorder()->orderBy('logged_at', 'desc')->get();

        return [
            'form' => 'ICS-209',
            'title' => 'Incident Status Summary',
            'incident_name' => $ics['incident_name'] ?? $incident->incident_type,
            'report_version' => 1,
            'report_date' => now()->toDateString(),
            'report_time' => now()->toTimeString(),

            'incident_information' => [
                'start_date' => $incident->triggered_at->toDateString(),
                'start_time' => $incident->triggered_at->toTimeString(),
                'cause' => $incident->cause ?? 'Under investigation',
                'incident_type' => $ics['incident_type'] ?? self::TYPE_5,
                'incident_commander' => $this->getPositionHolder($ics, self::POSITION_INCIDENT_COMMANDER),
            ],

            'life_safety' => [
                'injuries' => $incident->getStats()['injured'],
                'fatalities' => 0,
                'evacuated' => $incident->getStats()['safe'],
                'sheltering' => 0,
                'missing' => $incident->getStats()['missing'],
            ],

            'current_situation' => [
                'status' => $incident->status,
                'percent_contained' => $incident->containment_percent ?? 0,
                'expected_containment' => $incident->expected_containment_at ?? null,
            ],

            'resources_summary' => $this->summarizeResources($ics),

            'significant_events' => $logs->take(10)->map(fn($log) => [
                'time' => $log->logged_at->toTimeString(),
                'event' => $log->getTypeLabel(),
                'details' => $log->message,
            ])->all(),
        ];
    }

    /**
     * Generate ICS Form 214 - Activity Log
     */
    public function generateForm214(EmergencyIncident $incident): array
    {
        $ics = $incident->ics_data ?? [];
        $logs = $incident->eventLogs()->get();

        return [
            'form' => 'ICS-214',
            'title' => 'Activity Log',
            'incident_name' => $ics['incident_name'] ?? $incident->incident_type,
            'operational_period' => $ics['operational_period'] ?? null,
            'unit_name' => 'Incident Command',
            'unit_leader' => $this->getPositionHolder($ics, self::POSITION_INCIDENT_COMMANDER),
            'prepared_by' => auth()->user()?->name,

            'activity_log' => $logs->map(fn($log) => [
                'date' => $log->logged_at->toDateString(),
                'time' => $log->logged_at->toTimeString(),
                'notable_activities' => $this->formatLogActivity($log),
            ])->all(),
        ];
    }

    /**
     * Add resource to incident
     */
    public function addResource(EmergencyIncident $incident, array $resource): array
    {
        $ics = $incident->ics_data ?? [];

        $resourceEntry = [
            'id' => uniqid('res_'),
            'type' => $resource['type'],
            'name' => $resource['name'],
            'quantity' => $resource['quantity'] ?? 1,
            'status' => 'assigned',
            'assigned_to' => $resource['assigned_to'] ?? null,
            'location' => $resource['location'] ?? null,
            'eta' => $resource['eta'] ?? null,
            'added_at' => now()->toISOString(),
        ];

        $ics['resources'][] = $resourceEntry;
        $incident->update(['ics_data' => $ics]);

        // Log resource assignment
        $this->logIcs($incident, [
            'action' => 'resource_assigned',
            'details' => $resourceEntry,
            'user_id' => auth()->id(),
            'logged_at' => now(),
        ]);

        return $resourceEntry;
    }

    /**
     * Update resource status
     */
    public function updateResourceStatus(EmergencyIncident $incident, string $resourceId, string $status): bool
    {
        $ics = $incident->ics_data ?? [];

        foreach ($ics['resources'] as &$resource) {
            if ($resource['id'] === $resourceId) {
                $resource['status'] = $status;
                $resource['status_updated_at'] = now()->toISOString();
                break;
            }
        }

        $incident->update(['ics_data' => $ics]);

        return true;
    }

    /**
     * Set incident objectives
     */
    public function setObjectives(EmergencyIncident $incident, array $objectives): array
    {
        $ics = $incident->ics_data ?? [];
        $ics['objectives'] = array_map(fn($obj, $idx) => [
            'number' => $idx + 1,
            'objective' => $obj['objective'],
            'priority' => $obj['priority'] ?? 'medium',
            'status' => $obj['status'] ?? 'active',
        ], $objectives, array_keys($objectives));

        $incident->update(['ics_data' => $ics]);

        return $ics['objectives'];
    }

    /**
     * Start new operational period
     */
    public function startOperationalPeriod(EmergencyIncident $incident, int $hours = 12): array
    {
        $ics = $incident->ics_data ?? [];

        $previousPeriod = $ics['operational_period'] ?? null;

        $ics['operational_period'] = [
            'start' => now()->toISOString(),
            'end' => now()->addHours($hours)->toISOString(),
            'number' => ($previousPeriod['number'] ?? 0) + 1,
        ];

        // Archive previous period
        if ($previousPeriod) {
            $ics['operational_period_history'][] = $previousPeriod;
        }

        $incident->update(['ics_data' => $ics]);

        // Log period change
        $this->logIcs($incident, [
            'action' => 'operational_period_started',
            'details' => $ics['operational_period'],
            'user_id' => auth()->id(),
            'logged_at' => now(),
        ]);

        return $ics['operational_period'];
    }

    /**
     * Transfer command
     */
    public function transferCommand(EmergencyIncident $incident, int $newCommanderId, string $reason = ''): array
    {
        $ics = $incident->ics_data ?? [];

        $previousCommander = $ics['command_structure'][self::POSITION_INCIDENT_COMMANDER] ?? null;
        $ics['command_structure'][self::POSITION_INCIDENT_COMMANDER] = $newCommanderId;

        // Log transfer
        $ics['command_transfers'][] = [
            'from' => $previousCommander,
            'to' => $newCommanderId,
            'reason' => $reason,
            'timestamp' => now()->toISOString(),
        ];

        $incident->update(['ics_data' => $ics]);

        // Log in activity log
        $this->logIcs($incident, [
            'action' => 'command_transfer',
            'details' => [
                'from' => $previousCommander,
                'to' => $newCommanderId,
                'reason' => $reason,
            ],
            'user_id' => auth()->id(),
            'logged_at' => now(),
        ]);

        Log::warning("[ICS] Command transferred", [
            'incident_id' => $incident->id,
            'from' => $previousCommander,
            'to' => $newCommanderId,
        ]);

        return [
            'success' => true,
            'previous_commander' => $previousCommander,
            'new_commander' => $newCommanderId,
        ];
    }

    /**
     * Demobilize incident
     */
    public function demobilize(EmergencyIncident $incident, int $userId): array
    {
        $ics = $incident->ics_data ?? [];

        $ics['status'] = 'demobilized';
        $ics['demobilized_at'] = now()->toISOString();
        $ics['demobilized_by'] = $userId;

        // الإنهاء الفعلي عبر آلة الحالة (EmergencyService::endIncident)؛ هنا تُختم بيانات القيادة فقط
        $incident->update(['ics_data' => $ics]);

        // Log demobilization
        $this->logIcs($incident, [
            'action' => 'ics_demobilized',
            'details' => [
                'by' => $userId,
                'total_duration' => $incident->triggered_at->diffForHumans(now(), ['parts' => 2]),
            ],
            'user_id' => $userId,
            'logged_at' => now(),
        ]);

        return [
            'success' => true,
            'demobilized_at' => now()->toISOString(),
            'total_duration' => $incident->triggered_at->diff(now()),
        ];
    }

    // Helper methods

    protected function generateIncidentName(EmergencyIncident $incident): string
    {
        $building = $incident->building;
        $date = $incident->triggered_at->format('Ymd');
        return strtoupper("{$building->name}_{$incident->incident_type}_{$date}");
    }

    protected function determineIncidentType(EmergencyIncident $incident): int
    {
        $severity = $incident->severity ?? 'low';
        $occupancy = $incident->building->total_capacity ?? 100;

        if ($severity === 'critical' || $occupancy > 1000) {
            return self::TYPE_2;
        }
        if ($occupancy > 500) {
            return self::TYPE_3;
        }
        if ($occupancy > 100) {
            return self::TYPE_4;
        }
        return self::TYPE_5;
    }

    protected function buildOrgChart(array $ics): array
    {
        return [
            'command' => [
                'incident_commander' => $this->getPositionHolder($ics, self::POSITION_INCIDENT_COMMANDER),
            ],
            'command_staff' => [
                'safety_officer' => $this->getPositionHolder($ics, self::POSITION_SAFETY_OFFICER),
                'public_information_officer' => $this->getPositionHolder($ics, self::POSITION_PUBLIC_INFO_OFFICER),
                'liaison_officer' => $this->getPositionHolder($ics, self::POSITION_LIAISON_OFFICER),
            ],
            'general_staff' => [
                'operations' => $this->getPositionHolder($ics, self::POSITION_OPERATIONS_CHIEF),
                'planning' => $this->getPositionHolder($ics, self::POSITION_PLANNING_CHIEF),
                'logistics' => $this->getPositionHolder($ics, self::POSITION_LOGISTICS_CHIEF),
                'finance' => $this->getPositionHolder($ics, self::POSITION_FINANCE_CHIEF),
            ],
        ];
    }

    protected function getPositionHolder(array $ics, string $position): ?array
    {
        $userId = $ics['command_structure'][$position] ?? null;

        if (!$userId) {
            return null;
        }

        $user = DB::table('users')->find($userId);
        return $user ? ['id' => $user->id, 'name' => $user->name] : null;
    }

    protected function summarizeResources(array $ics): array
    {
        $resources = $ics['resources'] ?? [];

        $summary = [];
        foreach ($resources as $resource) {
            $type = $resource['type'];
            if (!isset($summary[$type])) {
                $summary[$type] = ['assigned' => 0, 'available' => 0, 'out_of_service' => 0];
            }
            $status = $resource['status'] ?? 'assigned';
            $summary[$type][$status] = ($summary[$type][$status] ?? 0) + ($resource['quantity'] ?? 1);
        }

        return $summary;
    }

    protected function formatLogActivity($log): string
    {
        return $log->getTypeLabel().': '.$log->message;
    }

    /** OHSMS كتب في logs() غير الموجودة؛ هنا السجل الزمني الفعلي بنوع ics. */
    protected function logIcs(EmergencyIncident $incident, array $row): void
    {
        $action = $row['action'] ?? 'ics';
        $details = $row['details'] ?? [];
        \App\Modules\Emergency\Models\EmergencyEventLog::log($incident, \App\Modules\Emergency\Models\EmergencyEventLog::TYPE_ICS,
            'قيادة الحادث: '.$this->icsActionLabel($action), $details, 'info', $row['user_id'] ?? auth()->id());
    }

    protected function icsActionLabel(string $action): string
    {
        return match ($action) {
            'ics_established' => 'تأسيس القيادة', 'ics_position_assigned' => 'تعيين موقع في هيكل القيادة',
            'resource_assigned' => 'تخصيص مورد', 'operational_period_started' => 'بدء فترة تشغيلية',
            'command_transfer' => 'نقل القيادة', 'ics_demobilized' => 'إنهاء القيادة (تسريح)', default => $action,
        };
    }
}
