<?php

namespace App\Modules\Integration\Services;

use App\Modules\Emergency\Models\EmergencyBuilding;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Access Control System Integration Service
 *
 * Features:
 * - Door lock/unlock control
 * - Real-time occupancy from badge readers
 * - Emergency unlock all doors
 * - Lockdown mode
 * - Integration with evacuation mustering
 */
class AccessControlService extends DeviceService
{
    protected function kind(): string { return 'access_control'; }

    // Door States
    const DOOR_LOCKED = 'locked';
    const DOOR_UNLOCKED = 'unlocked';
    const DOOR_OPEN = 'open';
    const DOOR_FORCED = 'forced';
    const DOOR_HELD = 'held_open';

    // Access Control Modes
    const MODE_NORMAL = 'normal';
    const MODE_LOCKDOWN = 'lockdown';
    const MODE_EMERGENCY_EGRESS = 'emergency_egress';
    const MODE_FREE_ACCESS = 'free_access';

    /**
     * Get current building occupancy
     */
    public function getCurrentOccupancy(int $buildingId): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        $cacheKey = "access_occupancy:{$buildingId}";

        return Cache::remember($cacheKey, 60, function () use ($buildingId) {
            try {
                $endpoint = $this->getEndpoint("buildings/{$buildingId}/occupancy");
                $response = $this->http(10)->get($endpoint);

                if ($response->successful()) {
                    $data = $response->json();
                    return [
                        'success' => true,
                        'total' => $data['total'] ?? 0,
                        'by_floor' => $data['by_floor'] ?? [],
                        'by_zone' => $data['by_zone'] ?? [],
                        'last_entry' => $data['last_entry'] ?? null,
                        'last_exit' => $data['last_exit'] ?? null,
                        'timestamp' => now()->toISOString(),
                    ];
                }

                return $this->getOccupancyOffline($buildingId);
            } catch (\Exception $e) {
                Log::warning("[AccessControl] Occupancy fetch failed", ['error' => $e->getMessage()]);
                return $this->getOccupancyOffline($buildingId);
            }
        });
    }

    /**
     * Get list of personnel currently in building
     */
    public function getPersonnelInBuilding(int $buildingId): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        try {
            $endpoint = $this->getEndpoint("buildings/{$buildingId}/personnel");
            $response = $this->http(15)->get($endpoint);

            if ($response->successful()) {
                return $response->json()['personnel'] ?? [];
            }

            return [];
        } catch (\Exception $e) {
            Log::error("[AccessControl] Personnel list failed", ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get door statuses for a building
     */
    public function getDoorStatuses(int $buildingId): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        $cacheKey = "access_doors:{$buildingId}";

        return Cache::remember($cacheKey, 30, function () use ($buildingId) {
            try {
                $endpoint = $this->getEndpoint("buildings/{$buildingId}/doors");
                $response = $this->http(10)->get($endpoint);

                if ($response->successful()) {
                    return $response->json()['doors'] ?? [];
                }

                return [];
            } catch (\Exception $e) {
                return [];
            }
        });
    }

    /**
     * Unlock specific door
     */
    public function unlockDoor(int $buildingId, string $doorId, int $userId, int $duration = 30): bool
    {
        if (!$this->canReach()) return false;
        try {
            $endpoint = $this->getEndpoint("doors/{$doorId}/unlock");
            $response = $this->http(10)->post($endpoint, [
                'building_id' => $buildingId,
                'duration' => $duration,
                'requested_by' => $userId,
                'reason' => 'manual_unlock',
            ]);

            if ($response->successful()) {
                Log::info("[AccessControl] Door unlocked", [
                    'door' => $doorId,
                    'user' => $userId,
                    'duration' => $duration,
                ]);
                Cache::forget("access_doors:{$buildingId}");
                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::error("[AccessControl] Unlock failed", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Lock specific door
     */
    public function lockDoor(int $buildingId, string $doorId, int $userId): bool
    {
        if (!$this->canReach()) return false;
        try {
            $endpoint = $this->getEndpoint("doors/{$doorId}/lock");
            $response = $this->http(10)->post($endpoint, [
                'building_id' => $buildingId,
                'requested_by' => $userId,
            ]);

            if ($response->successful()) {
                Cache::forget("access_doors:{$buildingId}");
                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::error("[AccessControl] Lock failed", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Emergency unlock all doors (fire/evacuation mode)
     */
    public function emergencyUnlockAll(int $buildingId, int $userId, string $reason = 'evacuation'): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        try {
            $endpoint = $this->getEndpoint("buildings/{$buildingId}/emergency-unlock");
            $response = $this->http(30)->post($endpoint, [
                'requested_by' => $userId,
                'reason' => $reason,
                'mode' => self::MODE_EMERGENCY_EGRESS,
            ]);

            if ($response->successful()) {
                $data = $response->json();

                Log::critical("[AccessControl] Emergency unlock activated", [
                    'building' => $buildingId,
                    'user' => $userId,
                    'reason' => $reason,
                    'doors_unlocked' => $data['doors_affected'] ?? 0,
                ]);

                Cache::forget("access_doors:{$buildingId}");

                return [
                    'success' => true,
                    'doors_affected' => $data['doors_affected'] ?? 0,
                    'mode' => self::MODE_EMERGENCY_EGRESS,
                    'timestamp' => now()->toISOString(),
                ];
            }

            return ['success' => false, 'error' => 'System did not respond'];
        } catch (\Exception $e) {
            Log::error("[AccessControl] Emergency unlock failed", ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Activate lockdown mode
     */
    public function activateLockdown(int $buildingId, int $userId, array $options = []): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        try {
            $endpoint = $this->getEndpoint("buildings/{$buildingId}/lockdown");
            $response = $this->http(30)->post($endpoint, [
                'requested_by' => $userId,
                'mode' => self::MODE_LOCKDOWN,
                'lock_exterior' => $options['lock_exterior'] ?? true,
                'lock_interior' => $options['lock_interior'] ?? false,
                'allow_egress' => $options['allow_egress'] ?? true,
                'message' => $options['message'] ?? 'Lockdown activated',
            ]);

            if ($response->successful()) {
                $data = $response->json();

                Log::critical("[AccessControl] Lockdown activated", [
                    'building' => $buildingId,
                    'user' => $userId,
                    'options' => $options,
                ]);

                Cache::forget("access_doors:{$buildingId}");

                return [
                    'success' => true,
                    'doors_locked' => $data['doors_locked'] ?? 0,
                    'mode' => self::MODE_LOCKDOWN,
                    'timestamp' => now()->toISOString(),
                ];
            }

            return ['success' => false, 'error' => 'Lockdown activation failed'];
        } catch (\Exception $e) {
            Log::error("[AccessControl] Lockdown failed", ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Deactivate lockdown and return to normal
     */
    public function deactivateLockdown(int $buildingId, int $userId): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        try {
            $endpoint = $this->getEndpoint("buildings/{$buildingId}/lockdown/deactivate");
            $response = $this->http(30)->post($endpoint, [
                'requested_by' => $userId,
                'mode' => self::MODE_NORMAL,
            ]);

            if ($response->successful()) {
                Log::info("[AccessControl] Lockdown deactivated", [
                    'building' => $buildingId,
                    'user' => $userId,
                ]);

                Cache::forget("access_doors:{$buildingId}");

                return [
                    'success' => true,
                    'mode' => self::MODE_NORMAL,
                    'timestamp' => now()->toISOString(),
                ];
            }

            return ['success' => false, 'error' => 'Deactivation failed'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get mustering data (who has checked out via readers)
     */
    public function getMusteringData(int $buildingId, ?string $since = null): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        try {
            $endpoint = $this->getEndpoint("buildings/{$buildingId}/mustering");
            $params = [];

            if ($since) {
                $params['since'] = $since;
            }

            $response = $this->http(15)->get($endpoint, $params);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'total_inside' => $data['total_inside'] ?? 0,
                    'total_exited' => $data['total_exited'] ?? 0,
                    'exited_personnel' => $data['exited'] ?? [],
                    'remaining_personnel' => $data['remaining'] ?? [],
                    'timestamp' => now()->toISOString(),
                ];
            }

            return ['success' => false, 'error' => 'Failed to fetch mustering data'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get recent access events
     */
    public function getAccessEvents(int $buildingId, int $limit = 50): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        try {
            $endpoint = $this->getEndpoint("buildings/{$buildingId}/events");
            $response = $this->http(15)->get($endpoint, ['limit' => $limit]);

            if ($response->successful()) {
                return $response->json()['events'] ?? [];
            }

            return [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Process webhook event from access control system
     */
    public function processWebhookEvent(array $payload): array
    {
        $eventType = $payload['event_type'] ?? 'unknown';
        $buildingId = $payload['building_id'] ?? null;
        $doorId = $payload['door_id'] ?? null;

        Log::info("[AccessControl] Webhook event", [
            'type' => $eventType,
            'building' => $buildingId,
            'door' => $doorId,
        ]);

        // Clear relevant caches
        if ($buildingId) {
            Cache::forget("access_doors:{$buildingId}");
            Cache::forget("access_occupancy:{$buildingId}");
        }

        // Determine if this is a security event
        $securityEvents = ['forced_door', 'door_held', 'invalid_access', 'tamper'];
        $isSecurityEvent = in_array($eventType, $securityEvents);

        return [
            'processed' => true,
            'event_type' => $eventType,
            'is_security_event' => $isSecurityEvent,
            'requires_notification' => $isSecurityEvent,
            'timestamp' => now()->toISOString(),
        ];
    }


    protected function getOccupancyOffline(int $buildingId): array
    {
        return [
            'success' => false,
            'total' => 0,
            'by_floor' => [],
            'error' => 'Access control system offline',
            'timestamp' => now()->toISOString(),
        ];
    }
}
