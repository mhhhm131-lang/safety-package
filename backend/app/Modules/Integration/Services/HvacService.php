<?php

namespace App\Modules\Integration\Services;

use App\Modules\Emergency\Models\EmergencyBuilding;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * HVAC (Heating, Ventilation, Air Conditioning) Integration Service
 *
 * Emergency HVAC Features:
 * - Smoke control mode (pressurization)
 * - Shutdown on fire detection
 * - Zone isolation for chemical/gas incidents
 * - Fresh air purge mode
 */
class HvacService extends DeviceService
{
    protected function kind(): string { return 'hvac'; }

    // HVAC Emergency Modes
    const MODE_NORMAL = 'normal';
    const MODE_SMOKE_CONTROL = 'smoke_control';
    const MODE_SHUTDOWN = 'shutdown';
    const MODE_PURGE = 'purge';
    const MODE_ZONE_ISOLATION = 'zone_isolation';
    const MODE_PRESSURIZATION = 'pressurization';

    /**
     * Get HVAC system status for building
     */
    public function getSystemStatus(int $buildingId): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        $cacheKey = "hvac_status:{$buildingId}";

        return Cache::remember($cacheKey, 30, function () use ($buildingId) {
            try {
                $endpoint = $this->getEndpoint("buildings/{$buildingId}/status");
                $response = $this->http(10)->get($endpoint);

                if ($response->successful()) {
                    return [
                        'success' => true,
                        'data' => $response->json(),
                        'timestamp' => now()->toISOString(),
                    ];
                }

                return $this->getOfflineStatus();
            } catch (\Exception $e) {
                Log::warning("[HVAC] Status fetch failed", ['error' => $e->getMessage()]);
                return $this->getOfflineStatus();
            }
        });
    }

    /**
     * Get zone statuses
     */
    public function getZoneStatuses(int $buildingId): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        try {
            $endpoint = $this->getEndpoint("buildings/{$buildingId}/zones");
            $response = $this->http(15)->get($endpoint);

            if ($response->successful()) {
                return $response->json()['zones'] ?? [];
            }

            return [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Activate smoke control mode (stairwell pressurization, exhaust)
     */
    public function activateSmokeControl(int $buildingId, int $userId, array $options = []): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        try {
            $endpoint = $this->getEndpoint("buildings/{$buildingId}/smoke-control");
            $response = $this->http(30)->post($endpoint, [
                'mode' => self::MODE_SMOKE_CONTROL,
                'activated_by' => $userId,
                'affected_zones' => $options['zones'] ?? [],
                'stairwell_pressurization' => $options['pressurize_stairs'] ?? true,
                'corridor_exhaust' => $options['exhaust_corridors'] ?? true,
                'fire_floor' => $options['fire_floor'] ?? null,
            ]);

            if ($response->successful()) {
                $data = $response->json();

                Log::critical("[HVAC] Smoke control activated", [
                    'building' => $buildingId,
                    'user' => $userId,
                    'options' => $options,
                ]);

                Cache::forget("hvac_status:{$buildingId}");

                return [
                    'success' => true,
                    'mode' => self::MODE_SMOKE_CONTROL,
                    'zones_affected' => $data['zones_affected'] ?? 0,
                    'stairwells_pressurized' => $data['stairwells'] ?? [],
                    'timestamp' => now()->toISOString(),
                ];
            }

            return ['success' => false, 'error' => 'Smoke control activation failed'];
        } catch (\Exception $e) {
            Log::error("[HVAC] Smoke control failed", ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Emergency HVAC shutdown (fire protocol)
     */
    public function emergencyShutdown(int $buildingId, int $userId, ?array $zones = null): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        try {
            $endpoint = $this->getEndpoint("buildings/{$buildingId}/emergency-shutdown");
            $response = $this->http(30)->post($endpoint, [
                'mode' => self::MODE_SHUTDOWN,
                'shutdown_by' => $userId,
                'zones' => $zones, // null = all zones
                'reason' => 'emergency_protocol',
            ]);

            if ($response->successful()) {
                $data = $response->json();

                Log::critical("[HVAC] Emergency shutdown", [
                    'building' => $buildingId,
                    'user' => $userId,
                    'zones' => $zones ?? 'all',
                ]);

                Cache::forget("hvac_status:{$buildingId}");

                return [
                    'success' => true,
                    'mode' => self::MODE_SHUTDOWN,
                    'zones_shutdown' => $data['zones_affected'] ?? 0,
                    'timestamp' => now()->toISOString(),
                ];
            }

            return ['success' => false, 'error' => 'Shutdown failed'];
        } catch (\Exception $e) {
            Log::error("[HVAC] Shutdown failed", ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Activate fresh air purge mode (post-chemical incident)
     */
    public function activatePurgeMode(int $buildingId, int $userId, array $options = []): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        try {
            $endpoint = $this->getEndpoint("buildings/{$buildingId}/purge");
            $response = $this->http(30)->post($endpoint, [
                'mode' => self::MODE_PURGE,
                'activated_by' => $userId,
                'zones' => $options['zones'] ?? [],
                'outside_air_dampers' => 100, // Fully open
                'exhaust_fans' => $options['exhaust_speed'] ?? 'high',
                'duration_minutes' => $options['duration'] ?? 30,
            ]);

            if ($response->successful()) {
                $data = $response->json();

                Log::warning("[HVAC] Purge mode activated", [
                    'building' => $buildingId,
                    'duration' => $options['duration'] ?? 30,
                ]);

                Cache::forget("hvac_status:{$buildingId}");

                return [
                    'success' => true,
                    'mode' => self::MODE_PURGE,
                    'estimated_completion' => $data['completion_time'] ?? null,
                    'zones_affected' => $data['zones'] ?? [],
                    'timestamp' => now()->toISOString(),
                ];
            }

            return ['success' => false, 'error' => 'Purge activation failed'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Isolate specific zone (chemical/gas leak)
     */
    public function isolateZone(int $buildingId, string $zoneId, int $userId): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        try {
            $endpoint = $this->getEndpoint("zones/{$zoneId}/isolate");
            $response = $this->http(15)->post($endpoint, [
                'building_id' => $buildingId,
                'isolated_by' => $userId,
                'mode' => self::MODE_ZONE_ISOLATION,
            ]);

            if ($response->successful()) {
                Log::warning("[HVAC] Zone isolated", [
                    'zone' => $zoneId,
                    'building' => $buildingId,
                ]);

                Cache::forget("hvac_status:{$buildingId}");

                return [
                    'success' => true,
                    'zone_id' => $zoneId,
                    'mode' => self::MODE_ZONE_ISOLATION,
                    'timestamp' => now()->toISOString(),
                ];
            }

            return ['success' => false, 'error' => 'Zone isolation failed'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Return to normal operation
     */
    public function returnToNormal(int $buildingId, int $userId): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        try {
            $endpoint = $this->getEndpoint("buildings/{$buildingId}/normal-mode");
            $response = $this->http(30)->post($endpoint, [
                'mode' => self::MODE_NORMAL,
                'restored_by' => $userId,
            ]);

            if ($response->successful()) {
                Log::info("[HVAC] Returned to normal", ['building' => $buildingId]);
                Cache::forget("hvac_status:{$buildingId}");

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
     * Get air quality readings
     */
    public function getAirQuality(int $buildingId, ?string $zoneId = null): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        try {
            $endpoint = $zoneId
                ? $this->getEndpoint("zones/{$zoneId}/air-quality")
                : $this->getEndpoint("buildings/{$buildingId}/air-quality");

            $response = $this->http(10)->get($endpoint);

            if ($response->successful()) {
                return $response->json();
            }

            return ['error' => 'Air quality data unavailable'];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Check for hazardous air quality conditions
     */
    public function checkHazardousConditions(int $buildingId): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        try {
            $endpoint = $this->getEndpoint("buildings/{$buildingId}/hazard-check");
            $response = $this->http(15)->get($endpoint);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'has_hazards' => $data['hazards_detected'] ?? false,
                    'zones_affected' => $data['affected_zones'] ?? [],
                    'conditions' => $data['conditions'] ?? [],
                    'timestamp' => now()->toISOString(),
                ];
            }

            return ['has_hazards' => false, 'error' => 'Check unavailable'];
        } catch (\Exception $e) {
            return ['has_hazards' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Process webhook event from HVAC system
     */
    public function processWebhookEvent(array $payload): array
    {
        $eventType = $payload['event_type'] ?? 'unknown';
        $buildingId = $payload['building_id'] ?? null;
        $zoneId = $payload['zone_id'] ?? null;

        Log::info("[HVAC] Webhook event", [
            'type' => $eventType,
            'building' => $buildingId,
            'zone' => $zoneId,
        ]);

        if ($buildingId) {
            Cache::forget("hvac_status:{$buildingId}");
        }

        // Air quality alerts are critical
        $criticalEvents = ['smoke_detected', 'co_detected', 'gas_detected', 'high_temp'];
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
            'data' => null,
            'error' => 'HVAC control system offline',
            'timestamp' => now()->toISOString(),
        ];
    }
}
