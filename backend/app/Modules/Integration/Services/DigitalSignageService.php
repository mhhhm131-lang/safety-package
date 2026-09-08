<?php

namespace App\Modules\Integration\Services;

use App\Modules\Emergency\Models\EmergencyBuilding;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Digital Signage Integration Service
 *
 * Emergency Display Features:
 * - Override normal content with emergency alerts
 * - Display evacuation routes per location
 * - Assembly point directions
 * - Real-time status updates
 * - Multi-language support (Arabic/English)
 */
class DigitalSignageService extends DeviceService
{
    protected function kind(): string { return 'signage'; }

    // Display Modes
    const MODE_NORMAL = 'normal';
    const MODE_EMERGENCY = 'emergency';
    const MODE_EVACUATION = 'evacuation';
    const MODE_LOCKDOWN = 'lockdown';
    const MODE_ALL_CLEAR = 'all_clear';
    const MODE_DRILL = 'drill';

    // Alert Templates
    const TEMPLATE_FIRE = 'fire_alert';
    const TEMPLATE_EVACUATION = 'evacuation_order';
    const TEMPLATE_LOCKDOWN = 'lockdown_alert';
    const TEMPLATE_SHELTER = 'shelter_in_place';
    const TEMPLATE_ALL_CLEAR = 'all_clear';
    const TEMPLATE_DRILL = 'drill_notice';

    /**
     * Get all displays for a building
     */
    public function getDisplays(int $buildingId): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        $cacheKey = "signage_displays:{$buildingId}";

        return Cache::remember($cacheKey, 300, function () use ($buildingId) {
            try {
                $endpoint = $this->getEndpoint("buildings/{$buildingId}/displays");
                $response = $this->http(10)->get($endpoint);

                if ($response->successful()) {
                    return $response->json()['displays'] ?? [];
                }

                return [];
            } catch (\Exception $e) {
                return [];
            }
        });
    }

    /**
     * Get display status
     */
    public function getDisplayStatus(string $displayId): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        try {
            $endpoint = $this->getEndpoint("displays/{$displayId}/status");
            $response = $this->http(5)->get($endpoint);

            if ($response->successful()) {
                return $response->json();
            }

            return ['status' => 'offline'];
        } catch (\Exception $e) {
            return ['status' => 'offline', 'error' => $e->getMessage()];
        }
    }

    /**
     * Push emergency alert to all displays in building
     */
    public function pushEmergencyAlert(int $buildingId, array $alert, int $userId): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        try {
            $type = $alert['type'] ?? 'emergency';
            $template = $this->getTemplateForType($type);

            $content = $this->buildEmergencyContent($alert);

            $endpoint = $this->getEndpoint("buildings/{$buildingId}/emergency-override");
            $response = $this->http(30)->post($endpoint, [
                'mode' => self::MODE_EMERGENCY,
                'template' => $template,
                'content' => $content,
                'priority' => 'critical',
                'triggered_by' => $userId,
                'audio_alert' => $alert['audio'] ?? true,
                'flash_screen' => $alert['flash'] ?? true,
                'duration' => $alert['duration'] ?? null, // null = indefinite
            ]);

            if ($response->successful()) {
                $data = $response->json();

                Log::critical("[Signage] Emergency alert pushed", [
                    'building' => $buildingId,
                    'type' => $type,
                    'displays' => $data['displays_affected'] ?? 0,
                ]);

                return [
                    'success' => true,
                    'displays_affected' => $data['displays_affected'] ?? 0,
                    'mode' => self::MODE_EMERGENCY,
                    'timestamp' => now()->toISOString(),
                ];
            }

            return ['success' => false, 'error' => 'Failed to push alert'];
        } catch (\Exception $e) {
            Log::error("[Signage] Emergency push failed", ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Display evacuation routes
     */
    public function showEvacuationRoutes(int $buildingId, array $options = []): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        try {
            $endpoint = $this->getEndpoint("buildings/{$buildingId}/evacuation-display");
            $response = $this->http(30)->post($endpoint, [
                'mode' => self::MODE_EVACUATION,
                'template' => self::TEMPLATE_EVACUATION,
                'assembly_points' => $options['assembly_points'] ?? [],
                'blocked_routes' => $options['blocked_routes'] ?? [],
                'floor_specific' => $options['floor_specific'] ?? true,
                'show_countdown' => $options['countdown'] ?? false,
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'displays_updated' => $response->json()['displays'] ?? 0,
                    'mode' => self::MODE_EVACUATION,
                ];
            }

            return ['success' => false, 'error' => 'Failed to show routes'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Display lockdown alert
     */
    public function showLockdownAlert(int $buildingId, array $alert, int $userId): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        try {
            $content = [
                'title_ar' => 'تنبيه إغلاق',
                'title_en' => 'LOCKDOWN ALERT',
                'message_ar' => $alert['message_ar'] ?? 'ابق في مكانك. أغلق الأبواب. ابتعد عن النوافذ.',
                'message_en' => $alert['message_en'] ?? 'Stay in place. Lock doors. Move away from windows.',
                'icon' => 'lock',
                'color' => 'red',
                'instructions_ar' => $alert['instructions_ar'] ?? 'انتظر التعليمات من الجهات الأمنية',
                'instructions_en' => $alert['instructions_en'] ?? 'Await instructions from security',
            ];

            $endpoint = $this->getEndpoint("buildings/{$buildingId}/lockdown-display");
            $response = $this->http(30)->post($endpoint, [
                'mode' => self::MODE_LOCKDOWN,
                'template' => self::TEMPLATE_LOCKDOWN,
                'content' => $content,
                'triggered_by' => $userId,
            ]);

            if ($response->successful()) {
                Log::critical("[Signage] Lockdown displayed", ['building' => $buildingId]);
                return [
                    'success' => true,
                    'displays_affected' => $response->json()['displays'] ?? 0,
                    'mode' => self::MODE_LOCKDOWN,
                ];
            }

            return ['success' => false, 'error' => 'Failed to display lockdown'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Display all-clear message
     */
    public function showAllClear(int $buildingId, int $userId, array $options = []): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        try {
            $content = [
                'title_ar' => 'انتهاء حالة الطوارئ',
                'title_en' => 'ALL CLEAR',
                'message_ar' => $options['message_ar'] ?? 'الوضع آمن. يمكنكم العودة إلى أماكنكم.',
                'message_en' => $options['message_en'] ?? 'The emergency has ended. You may return to your normal locations.',
                'icon' => 'check_circle',
                'color' => 'green',
            ];

            $endpoint = $this->getEndpoint("buildings/{$buildingId}/all-clear");
            $response = $this->http(30)->post($endpoint, [
                'mode' => self::MODE_ALL_CLEAR,
                'template' => self::TEMPLATE_ALL_CLEAR,
                'content' => $content,
                'cleared_by' => $userId,
                'duration' => $options['duration'] ?? 300, // 5 minutes then back to normal
                'return_to_normal' => true,
            ]);

            if ($response->successful()) {
                Log::info("[Signage] All clear displayed", ['building' => $buildingId]);
                return [
                    'success' => true,
                    'displays_affected' => $response->json()['displays'] ?? 0,
                    'mode' => self::MODE_ALL_CLEAR,
                    'returns_to_normal_in' => $options['duration'] ?? 300,
                ];
            }

            return ['success' => false, 'error' => 'Failed to display all clear'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Display drill notice
     */
    public function showDrillNotice(int $buildingId, array $drill): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        try {
            $content = [
                'title_ar' => 'تمرين إخلاء',
                'title_en' => 'EVACUATION DRILL',
                'message_ar' => 'هذا تمرين. يرجى المشاركة واتباع إجراءات الإخلاء.',
                'message_en' => 'This is a drill. Please participate and follow evacuation procedures.',
                'drill_type' => $drill['type'] ?? 'evacuation',
                'start_time' => $drill['start_time'] ?? null,
                'icon' => 'notifications_active',
                'color' => 'orange',
            ];

            $endpoint = $this->getEndpoint("buildings/{$buildingId}/drill-display");
            $response = $this->http(30)->post($endpoint, [
                'mode' => self::MODE_DRILL,
                'template' => self::TEMPLATE_DRILL,
                'content' => $content,
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'displays_affected' => $response->json()['displays'] ?? 0,
                    'mode' => self::MODE_DRILL,
                ];
            }

            return ['success' => false, 'error' => 'Failed to display drill notice'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Update specific display with custom content
     */
    public function updateDisplay(string $displayId, array $content): bool
    {
        if (!$this->canReach()) return false;
        try {
            $endpoint = $this->getEndpoint("displays/{$displayId}/content");
            $response = $this->http(15)->post($endpoint, [
                'content' => $content,
                'priority' => $content['priority'] ?? 'normal',
            ]);

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Return all displays to normal content
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
                Log::info("[Signage] Returned to normal", ['building' => $buildingId]);
                return [
                    'success' => true,
                    'mode' => self::MODE_NORMAL,
                    'displays_restored' => $response->json()['displays'] ?? 0,
                ];
            }

            return ['success' => false, 'error' => 'Return to normal failed'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get display health/status report
     */
    public function getHealthReport(int $buildingId): array
    {
        if (!$this->canReach()) return $this->notEnabled();
        try {
            $endpoint = $this->getEndpoint("buildings/{$buildingId}/health");
            $response = $this->http(15)->get($endpoint);

            if ($response->successful()) {
                return $response->json();
            }

            return ['error' => 'Health report unavailable'];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Build emergency content structure
     */
    protected function buildEmergencyContent(array $alert): array
    {
        $type = $alert['type'] ?? 'emergency';

        $content = [
            'type' => $type,
            'severity' => $alert['severity'] ?? 'critical',
            'timestamp' => now()->toISOString(),
        ];

        // Type-specific content
        switch ($type) {
            case 'fire':
                $content['title_ar'] = '🔥 تنبيه حريق';
                $content['title_en'] = '🔥 FIRE ALERT';
                $content['message_ar'] = 'أخلِ المبنى فوراً عبر أقرب مخرج طوارئ';
                $content['message_en'] = 'Evacuate immediately via nearest exit';
                $content['color'] = 'red';
                break;

            case 'evacuation':
                $content['title_ar'] = '🚨 أمر إخلاء';
                $content['title_en'] = '🚨 EVACUATION ORDER';
                $content['message_ar'] = 'توجه إلى نقطة التجمع المحددة';
                $content['message_en'] = 'Proceed to your designated assembly point';
                $content['color'] = 'orange';
                break;

            case 'earthquake':
                $content['title_ar'] = '⚠️ تنبيه زلزال';
                $content['title_en'] = '⚠️ EARTHQUAKE ALERT';
                $content['message_ar'] = 'انزل - احتمِ - تمسك. لا تستخدم المصاعد';
                $content['message_en'] = 'DROP - COVER - HOLD ON. Do not use elevators';
                $content['color'] = 'red';
                break;

            case 'chemical':
                $content['title_ar'] = '☣️ تسرب كيميائي';
                $content['title_en'] = '☣️ CHEMICAL HAZARD';
                $content['message_ar'] = 'ابتعد عن المنطقة الملوثة. اتبع تعليمات الأمان';
                $content['message_en'] = 'Move away from contaminated area. Follow safety instructions';
                $content['color'] = 'yellow';
                break;

            case 'security':
                $content['title_ar'] = '🔒 تهديد أمني';
                $content['title_en'] = '🔒 SECURITY THREAT';
                $content['message_ar'] = 'ابق في مكانك. اتبع تعليمات الأمن';
                $content['message_en'] = 'Stay in place. Follow security instructions';
                $content['color'] = 'red';
                break;

            default:
                $content['title_ar'] = '🚨 تنبيه طوارئ';
                $content['title_en'] = '🚨 EMERGENCY ALERT';
                $content['message_ar'] = $alert['message_ar'] ?? 'انتبه للتعليمات';
                $content['message_en'] = $alert['message_en'] ?? 'Follow emergency instructions';
                $content['color'] = 'red';
        }

        // Add building/location info if provided
        if (isset($alert['building'])) {
            $content['location'] = $alert['building'];
        }

        // Add assembly point if provided
        if (isset($alert['assembly_point'])) {
            $content['assembly_point'] = $alert['assembly_point'];
        }

        // Add custom instructions
        if (isset($alert['instructions'])) {
            $content['instructions'] = $alert['instructions'];
        }

        return $content;
    }

    protected function getTemplateForType(string $type): string
    {
        return match ($type) {
            'fire' => self::TEMPLATE_FIRE,
            'evacuation' => self::TEMPLATE_EVACUATION,
            'lockdown', 'security' => self::TEMPLATE_LOCKDOWN,
            'chemical', 'earthquake' => self::TEMPLATE_SHELTER,
            'drill' => self::TEMPLATE_DRILL,
            default => self::TEMPLATE_EVACUATION,
        };
    }

}
