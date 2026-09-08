<?php

namespace App\Modules\Integration\Services;

use App\Modules\Emergency\Models\EmergencyBuilding;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Elevator Control System Integration Service
 *
 * Emergency Elevator Features:
 * - Recall to designated floor (Phase I)
 * - Firefighter service mode (Phase II)
 * - Status monitoring
 * - Out of service during emergencies
 */
class ElevatorService extends DeviceService
{
    protected function kind(): string { return 'elevator'; }

    // Elevator Modes
    const MODE_NORMAL = 'normal';
    const MODE_FIRE_RECALL = 'fire_recall';      // Phase I - automatic recall
    const MODE_FIREFIGHTER = 'firefighter';       // Phase II - manual control
    const MODE_OUT_OF_SERVICE = 'out_of_service';
    const MODE_MAINTENANCE = 'maintenance';

    // Elevator States
    const STATE_IDLE = 'idle';
    const STATE_MOVING = 'moving';
    const STATE_DOOR_OPEN = 'door_open';
    const STATE_DOOR_CLOSED = 'door_closed';
    const STATE_FAULT = 'fault';

    /**
     * Get all elevator statuses for a building
     */
    public function getElevatorStatuses(int $buildingId): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        $cacheKey = "elevator_status:{$buildingId}";

        return Cache::remember($cacheKey, 15, function () use ($buildingId) {
            try {
                $endpoint = $this->getEndpoint("buildings/{$buildingId}/elevators");
                $response = $this->http(10)->get($endpoint);

                if ($response->successful()) {
                    return [
                        'success' => true,
                        'elevators' => $response->json()['elevators'] ?? [],
                        'timestamp' => now()->toISOString(),
                    ];
                }

                return $this->getOfflineStatus();
            } catch (\Exception $e) {
                Log::warning("[Elevator] Status fetch failed", ['error' => $e->getMessage()]);
                return $this->getOfflineStatus();
            }
        });
    }

    /**
     * Get single elevator status
     */
    public function getElevatorStatus(int $buildingId, string $elevatorId): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        try {
            $endpoint = $this->getEndpoint("elevators/{$elevatorId}");
            $response = $this->http(10)->get($endpoint, [
                'building_id' => $buildingId,
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            return [
                'id' => $elevatorId,
                'state' => 'unknown',
                'error' => 'Status unavailable',
            ];
        } catch (\Exception $e) {
            return [
                'id' => $elevatorId,
                'state' => 'offline',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Activate Fire Recall (Phase I) - Send all elevators to recall floor
     */
    public function activateFireRecall(int $buildingId, int $userId, ?int $recallFloor = null): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        $building = EmergencyBuilding::find($buildingId);
        $recallFloor = $recallFloor ?? ($this->device->config['recall_floor'] ?? 0);

        try {
            $endpoint = $this->getEndpoint("buildings/{$buildingId}/fire-recall");
            $response = $this->http(30)->post($endpoint, [
                'mode' => self::MODE_FIRE_RECALL,
                'recall_floor' => $recallFloor,
                'activated_by' => $userId,
                'timestamp' => now()->toISOString(),
            ]);

            if ($response->successful()) {
                $data = $response->json();

                Log::critical("[Elevator] Fire recall activated", [
                    'building' => $buildingId,
                    'recall_floor' => $recallFloor,
                    'user' => $userId,
                    'elevators_affected' => $data['elevators_affected'] ?? 0,
                ]);

                Cache::forget("elevator_status:{$buildingId}");

                return [
                    'success' => true,
                    'mode' => self::MODE_FIRE_RECALL,
                    'recall_floor' => $recallFloor,
                    'elevators_affected' => $data['elevators_affected'] ?? 0,
                    'estimated_recall_time' => $data['estimated_time'] ?? 60,
                    'timestamp' => now()->toISOString(),
                ];
            }

            return ['success' => false, 'error' => 'Fire recall activation failed'];
        } catch (\Exception $e) {
            Log::error("[Elevator] Fire recall failed", ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Activate Firefighter Service Mode (Phase II)
     */
    public function activateFirefighterMode(int $buildingId, string $elevatorId, int $userId): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        try {
            $endpoint = $this->getEndpoint("elevators/{$elevatorId}/firefighter-mode");
            $response = $this->http(15)->post($endpoint, [
                'building_id' => $buildingId,
                'mode' => self::MODE_FIREFIGHTER,
                'activated_by' => $userId,
            ]);

            if ($response->successful()) {
                Log::warning("[Elevator] Firefighter mode activated", [
                    'elevator' => $elevatorId,
                    'user' => $userId,
                ]);

                Cache::forget("elevator_status:{$buildingId}");

                return [
                    'success' => true,
                    'elevator_id' => $elevatorId,
                    'mode' => self::MODE_FIREFIGHTER,
                    'timestamp' => now()->toISOString(),
                ];
            }

            return ['success' => false, 'error' => 'Mode activation failed'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Deactivate emergency modes and return to normal
     */
    public function returnToNormal(int $buildingId, int $userId): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        try {
            $endpoint = $this->getEndpoint("buildings/{$buildingId}/normal-mode");
            $response = $this->http(30)->post($endpoint, [
                'mode' => self::MODE_NORMAL,
                'deactivated_by' => $userId,
            ]);

            if ($response->successful()) {
                Log::info("[Elevator] Returned to normal operation", [
                    'building' => $buildingId,
                    'user' => $userId,
                ]);

                Cache::forget("elevator_status:{$buildingId}");

                return [
                    'success' => true,
                    'mode' => self::MODE_NORMAL,
                    'timestamp' => now()->toISOString(),
                ];
            }

            return ['success' => false, 'error' => 'Return to normal failed'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send elevator to specific floor (firefighter mode only)
     */
    public function sendToFloor(int $buildingId, string $elevatorId, int $floor, int $userId): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        try {
            $endpoint = $this->getEndpoint("elevators/{$elevatorId}/send-to-floor");
            $response = $this->http(15)->post($endpoint, [
                'building_id' => $buildingId,
                'floor' => $floor,
                'commanded_by' => $userId,
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'elevator_id' => $elevatorId,
                    'target_floor' => $floor,
                    'estimated_time' => $response->json()['estimated_time'] ?? 30,
                ];
            }

            return ['success' => false, 'error' => 'Command failed'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Open elevator doors (firefighter mode)
     */
    public function openDoors(int $buildingId, string $elevatorId, int $userId): bool
    {
        if (!$this->canReach()) return false;
        try {
            $endpoint = $this->getEndpoint("elevators/{$elevatorId}/doors/open");
            $response = $this->http(10)->post($endpoint, [
                'building_id' => $buildingId,
                'commanded_by' => $userId,
            ]);

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Close elevator doors (firefighter mode)
     */
    public function closeDoors(int $buildingId, string $elevatorId, int $userId): bool
    {
        if (!$this->canReach()) return false;
        try {
            $endpoint = $this->getEndpoint("elevators/{$elevatorId}/doors/close");
            $response = $this->http(10)->post($endpoint, [
                'building_id' => $buildingId,
                'commanded_by' => $userId,
            ]);

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Put elevator out of service
     */
    public function setOutOfService(int $buildingId, string $elevatorId, int $userId, string $reason = ''): bool
    {
        if (!$this->canReach()) return false;
        try {
            $endpoint = $this->getEndpoint("elevators/{$elevatorId}/out-of-service");
            $response = $this->http(15)->post($endpoint, [
                'building_id' => $buildingId,
                'set_by' => $userId,
                'reason' => $reason,
            ]);

            if ($response->successful()) {
                Cache::forget("elevator_status:{$buildingId}");
                return true;
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get elevator position (real-time)
     */
    public function getElevatorPosition(int $buildingId, string $elevatorId): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        try {
            $endpoint = $this->getEndpoint("elevators/{$elevatorId}/position");
            $response = $this->http(5)->get($endpoint, [
                'building_id' => $buildingId,
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            return ['floor' => 'unknown', 'direction' => 'unknown'];
        } catch (\Exception $e) {
            return ['floor' => 'offline', 'direction' => 'unknown'];
        }
    }

    /**
     * Check if any elevator has trapped passengers
     */
    public function checkTrappedPassengers(int $buildingId): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        try {
            $endpoint = $this->getEndpoint("buildings/{$buildingId}/trapped-check");
            $response = $this->http(15)->get($endpoint);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'has_trapped' => $data['has_trapped'] ?? false,
                    'elevators_with_trapped' => $data['elevators'] ?? [],
                    'total_trapped' => $data['total_count'] ?? 0,
                    'timestamp' => now()->toISOString(),
                ];
            }

            return ['has_trapped' => false, 'error' => 'Check unavailable'];
        } catch (\Exception $e) {
            return ['has_trapped' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Process webhook event from elevator system
     */
    public function processWebhookEvent(array $payload): array
    {
        $eventType = $payload['event_type'] ?? 'unknown';
        $buildingId = $payload['building_id'] ?? null;
        $elevatorId = $payload['elevator_id'] ?? null;

        Log::info("[Elevator] Webhook event", [
            'type' => $eventType,
            'building' => $buildingId,
            'elevator' => $elevatorId,
        ]);

        // Clear cache
        if ($buildingId) {
            Cache::forget("elevator_status:{$buildingId}");
        }

        // Critical events requiring immediate attention
        $criticalEvents = ['entrapment', 'fault', 'door_malfunction', 'emergency_stop'];
        $isCritical = in_array($eventType, $criticalEvents);

        return [
            'processed' => true,
            'event_type' => $eventType,
            'is_critical' => $isCritical,
            'requires_notification' => $isCritical,
            'timestamp' => now()->toISOString(),
        ];
    }


    protected function getOfflineStatus(): array
    {
        return [
            'success' => false,
            'elevators' => [],
            'error' => 'Elevator control system offline',
            'timestamp' => now()->toISOString(),
        ];
    }
}
