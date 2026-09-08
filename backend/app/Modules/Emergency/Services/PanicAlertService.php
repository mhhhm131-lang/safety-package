<?php

namespace App\Modules\Emergency\Services;

use App\Models\User;
use App\Modules\Governance\Models\UserProfile;
use App\Core\Services\NotificationService;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyEventLog;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Models\EmergencyTeam;
use App\Modules\Emergency\Models\PanicAlert;
use App\Modules\Emergency\Models\PanicAlertResponder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PanicAlertService
{
    public function __construct(
        protected EmergencyNotificationService $notificationService
    ) {}

    /**
     * Trigger a new panic alert
     */
    public function trigger(User $user, array $data): PanicAlert
    {
        return DB::transaction(function () use ($user, $data) {
            // 1. Determine nearest building if location provided
            $buildingId = $data['building_id'] ?? EmergencyBuilding::main()?->id;

            // 2. Create the alert
            $alert = PanicAlert::create([
                'user_id' => $user->id,
                'building_id' => $buildingId,
                'place_id' => $data['place_id'] ?? $user->profile?->place_id,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'accuracy_meters' => $data['accuracy_meters'] ?? null,
                'location_description' => $data['location_description'] ?? null,
                'alert_type' => $data['alert_type'] ?? 'panic',
                'severity' => $data['severity'] ?? 'high',
                'status' => 'triggered',
                'message' => $data['message'] ?? null,
                'voice_mime' => $data['voice_mime'] ?? null,
                'voice_data' => $data['voice_data'] ?? null,
                'photo_mime' => $data['photo_mime'] ?? null,
                'photo_data' => $data['photo_data'] ?? null,
            ]);

            // 3. Notify security team responders
            $this->notifyResponders($alert);

            // 4. Log the event
            $this->logEvent($alert, 'panic_triggered', [
                'user_name' => $user->name,
                'alert_type' => $alert->alert_type,
                'location' => $alert->getLocationString(),
            ]);

            // 5. Broadcast event (for live tracking dashboard)
            $this->broadcastAlert($alert);

            Log::info("Panic alert triggered", [
                'alert_id' => $alert->id,
                'user_id' => $user->id,
                'type' => $alert->alert_type,
            ]);

            return $alert;
        });
    }

    /**
     * Acknowledge a panic alert
     */
    public function acknowledge(PanicAlert $alert, User $responder): void
    {
        if (!$alert->canBeAcknowledged()) {
            throw new \RuntimeException("Alert cannot be acknowledged in current state: {$alert->status}");
        }

        DB::transaction(function () use ($alert, $responder) {
            $alert->update([
                'status' => PanicAlert::STATUS_ACKNOWLEDGED,
                'acknowledged_by_id' => $responder->id,
                'acknowledged_at' => now(),
            ]);

            // Update responder record
            $responderRecord = $alert->responders()->where('user_id', $responder->id)->first();
            if ($responderRecord) {
                $responderRecord->respond(PanicAlertResponder::RESPONSE_ACKNOWLEDGED);
            }

            // Log the event
            $this->logEvent($alert, 'panic_acknowledged', [
                'responder_name' => $responder->name,
                'response_time_seconds' => $alert->getResponseTimeSeconds(),
            ]);

            // Notify the alert creator
            $this->notifyAlertCreator($alert, 'acknowledged', $responder);
        });
    }

    /**
     * Mark responder as en route
     */
    public function markEnRoute(PanicAlert $alert, User $responder): void
    {
        DB::transaction(function () use ($alert, $responder) {
            // Update alert status if not already responding
            if ($alert->status === PanicAlert::STATUS_ACKNOWLEDGED) {
                $alert->update(['status' => PanicAlert::STATUS_RESPONDING]);
            }

            // Update responder record
            $responderRecord = $alert->responders()->where('user_id', $responder->id)->first();
            if ($responderRecord) {
                $responderRecord->respond(PanicAlertResponder::RESPONSE_EN_ROUTE);
            }

            $this->logEvent($alert, 'responder_en_route', [
                'responder_name' => $responder->name,
            ]);

            // Notify the alert creator
            $this->notifyAlertCreator($alert, 'responder_en_route', $responder);
        });
    }

    /**
     * Mark responder as arrived
     */
    public function markArrived(PanicAlert $alert, User $responder): void
    {
        $responderRecord = $alert->responders()->where('user_id', $responder->id)->first();
        if ($responderRecord) {
            $responderRecord->respond(PanicAlertResponder::RESPONSE_ARRIVED);
        }

        $this->logEvent($alert, 'responder_arrived', [
            'responder_name' => $responder->name,
        ]);

        // Notify the alert creator
        $this->notifyAlertCreator($alert, 'responder_arrived', $responder);
    }

    /**
     * Resolve a panic alert
     */
    public function resolve(PanicAlert $alert, User $resolver, string $notes, bool $isFalseAlarm = false): void
    {
        if (!$alert->canBeResolved()) {
            throw new \RuntimeException("Alert cannot be resolved in current state: {$alert->status}");
        }

        DB::transaction(function () use ($alert, $resolver, $notes, $isFalseAlarm) {
            $status = $isFalseAlarm ? PanicAlert::STATUS_FALSE_ALARM : PanicAlert::STATUS_RESOLVED;

            $alert->update([
                'status' => $status,
                'resolved_by_id' => $resolver->id,
                'resolved_at' => now(),
                'resolution_notes' => $notes,
            ]);

            $this->logEvent($alert, $isFalseAlarm ? 'panic_false_alarm' : 'panic_resolved', [
                'resolver_name' => $resolver->name,
                'resolution_time_seconds' => $alert->getResolutionTimeSeconds(),
                'notes' => $notes,
            ]);

            // Notify the alert creator
            $this->notifyAlertCreator($alert, $status, $resolver);
        });

        Log::info("Panic alert resolved", [
            'alert_id' => $alert->id,
            'resolver_id' => $resolver->id,
            'false_alarm' => $isFalseAlarm,
        ]);
    }

    /**
     * Escalate panic alert to a full emergency incident
     */
    public function escalateToIncident(PanicAlert $alert): EmergencyIncident
    {
        $incidentType = match($alert->alert_type) {
            'medical' => EmergencyIncident::TYPE_MEDICAL,
            'fire' => EmergencyIncident::TYPE_FIRE,
            'security' => EmergencyIncident::TYPE_SECURITY,
            default => EmergencyIncident::TYPE_OTHER,
        };

        // التصعيد يمر بخدمة الحالة الطارئة (آلة الحالة والتنبيه والحصر) لا بإنشاء مباشر
        $incident = app(EmergencyService::class)->triggerAlarm(
            $alert->building ?? EmergencyBuilding::main(), $incidentType, $alert->user, $alert->severity, false,
            "تصعيد من تنبيه ذعر: {$alert->message}", $alert->place_id,
        );
        $incident->update(['initial_report' => "موقع التنبيه: {$alert->getLocationString()}"]);

        // Link the alert to the incident
        $alert->update(['incident_id' => $incident->id]);

        $this->logEvent($alert, 'panic_escalated', [
            'incident_id' => $incident->id,
            'incident_code' => $incident->incident_code,
        ]);

        Log::info("Panic alert escalated to incident", [
            'alert_id' => $alert->id,
            'incident_id' => $incident->id,
        ]);

        return $incident;
    }

    /**
     * Get active alerts for a tenant
     */
    public function getActiveAlerts(): Collection
    {
        return PanicAlert::query()
            ->active()
            ->with(['user', 'building', 'responders.user'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get recent alerts for a tenant
     */
    public function getRecentAlerts(int $hours = 24): Collection
    {
        return PanicAlert::query()
            ->recent($hours)
            ->with(['user', 'building', 'acknowledgedBy', 'resolvedBy'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get alert statistics for a tenant
     */
    public function getStats(?string $period = 'month'): array
    {
        $startDate = match($period) {
            'day' => now()->startOfDay(),
            'week' => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            'year' => now()->startOfYear(),
            default => now()->startOfMonth(),
        };

        $alerts = PanicAlert::query()
            ->where('created_at', '>=', $startDate)
            ->get();

        $resolvedAlerts = $alerts->where('status', PanicAlert::STATUS_RESOLVED);

        return [
            'total' => $alerts->count(),
            'by_status' => [
                'triggered' => $alerts->where('status', PanicAlert::STATUS_TRIGGERED)->count(),
                'acknowledged' => $alerts->where('status', PanicAlert::STATUS_ACKNOWLEDGED)->count(),
                'responding' => $alerts->where('status', PanicAlert::STATUS_RESPONDING)->count(),
                'resolved' => $resolvedAlerts->count(),
                'false_alarm' => $alerts->where('status', PanicAlert::STATUS_FALSE_ALARM)->count(),
            ],
            'by_type' => [
                'panic' => $alerts->where('alert_type', 'panic')->count(),
                'medical' => $alerts->where('alert_type', 'medical')->count(),
                'fire' => $alerts->where('alert_type', 'fire')->count(),
                'security' => $alerts->where('alert_type', 'security')->count(),
                'other' => $alerts->where('alert_type', 'other')->count(),
            ],
            'avg_response_time' => $this->calculateAvgResponseTime($resolvedAlerts),
            'avg_resolution_time' => $this->calculateAvgResolutionTime($resolvedAlerts),
        ];
    }

    /**
     * Notify security team responders
     */
    protected function notifyResponders(PanicAlert $alert): void
    {
        // Get security team members
        // المعهد: فريق الأمن، ثم الفريق الأولي للمكان (له أعضاء بلا حساب → نداء هاتفي من المركز)
        $securityTeams = EmergencyTeam::query()
            ->whereIn('team_type', ['security', 'initial'])
            ->when($alert->place_id, fn ($q) => $q->where(fn ($w) => $w->where('place_id', $alert->place_id)->orWhereNull('place_id')))
            ->where('is_active', true)
            ->when($alert->building_id, function ($q) use ($alert) {
                $q->where(function ($q2) use ($alert) {
                    $q2->where('building_id', $alert->building_id)
                       ->orWhereNull('building_id');
                });
            })
            ->with('members.user')
            ->get();

        $notifiedUserIds = [];

        foreach ($securityTeams as $team) {
            foreach ($team->members as $member) {
                if (!$member->user || in_array($member->user_id, $notifiedUserIds)) {
                    continue;
                }

                // Create responder record
                PanicAlertResponder::create([
                    'panic_alert_id' => $alert->id,
                    'user_id' => $member->user_id,
                    'notified_at' => now(),
                ]);

                // Send push/SMS notification
                $this->sendResponderNotification($alert, $member->user);

                $notifiedUserIds[] = $member->user_id;
            }
        }

        // Also notify safety coordinators if no security team found
        if (empty($notifiedUserIds)) {
            $this->notifySafetyCoordinators($alert);
        }

        Log::info("Notified {count} responders for panic alert", [
            'alert_id' => $alert->id,
            'count' => count($notifiedUserIds),
        ]);
    }

    /**
     * Notify safety coordinators as fallback
     */
    protected function notifySafetyCoordinators(PanicAlert $alert): void
    {
        $coordinators = UserProfile::query()
            ->where('is_active', true)
            ->whereIn('role', ['safety_coordinator', 'system_staff', 'system_admin', 'security_safety_head'])
            ->with('user')
            ->get();

        foreach ($coordinators as $profile) {
            if (!$profile->user) continue;

            PanicAlertResponder::create([
                'panic_alert_id' => $alert->id,
                'user_id' => $profile->user_id,
                'notified_at' => now(),
            ]);

            $this->sendResponderNotification($alert, $profile->user);
        }
    }

    /**
     * Send notification to a responder
     */
    protected function sendResponderNotification(PanicAlert $alert, User $responder): void
    {
        $alertUser = $alert->user;
        $message = sprintf(
            "🚨 تنبيه ذعر من %s\nالنوع: %s\nالموقع: %s",
            $alertUser->name,
            $alert->getTypeLabel(),
            $alert->getLocationString() ?? 'غير محدد'
        );

        try {
            app(NotificationService::class)->create($responder->id, 'emergency.panic', 'تنبيه ذعر — '.$alert->getTypeLabel(), $message, '/app/emergency/panic/'.$alert->id);
        } catch (\Exception $e) {
            Log::warning("Failed to send panic notification: {$e->getMessage()}");
        }
    }

    /**
     * Notify the alert creator about status changes
     */
    protected function notifyAlertCreator(PanicAlert $alert, string $status, User $responder): void
    {
        $messages = [
            'acknowledged' => "تم استلام تنبيهك بواسطة {$responder->name}",
            'responder_en_route' => "{$responder->name} في الطريق إليك",
            'responder_arrived' => "وصل {$responder->name} للموقع",
            'resolved' => "تم حل التنبيه بواسطة {$responder->name}",
            'false_alarm' => "تم إغلاق التنبيه كإنذار كاذب",
        ];

        $message = $messages[$status] ?? "تحديث على تنبيهك: {$status}";

        try {
            app(NotificationService::class)->create($alert->user_id, 'emergency.panic', 'تحديث تنبيهك', $message, '/app/emergency/panic/'.$alert->id);
        } catch (\Exception $e) {
            Log::warning("Failed to notify alert creator: {$e->getMessage()}");
        }
    }

    /**
     * Find nearest building to coordinates
     */
    protected function findNearestBuilding(float $lat, float $lng): ?EmergencyBuilding
    {
        return EmergencyBuilding::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get()
            ->map(function ($building) use ($lat, $lng) {
                $building->distance = $this->haversineDistance(
                    $lat, $lng,
                    $building->latitude, $building->longitude
                );
                return $building;
            })
            ->sortBy('distance')
            ->first();
    }

    /**
     * Calculate distance between two points (Haversine formula)
     */
    protected function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000; // meters

        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLng = deg2rad($lng2 - $lng1);

        $a = sin($deltaLat / 2) ** 2 +
             cos($lat1Rad) * cos($lat2Rad) * sin($deltaLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Log panic event
     */
    protected function logEvent(PanicAlert $alert, string $action, array $details = []): void
    {
        // السجل الزمني مرتبط بحالة طارئة؛ التنبيه غير المصعَّد يوثَّق في سجل التدقيق (HasAuditLog على PanicAlert)
        if (!$alert->incident_id) return;
        EmergencyEventLog::create([
            'incident_id' => $alert->incident_id,
            'event_type' => EmergencyEventLog::TYPE_PANIC,
            'severity' => 'warning',
            'message' => $this->getActionDescription($action).' — '.($alert->user?->name ?? ''),
            'data' => array_merge($details, ['panic_alert_id' => $alert->id, 'action' => $action]),
            'user_id' => auth()->id(),
            'logged_at' => now(),
        ]);
    }

    /**
     * Get human-readable action description
     */
    protected function getActionDescription(string $action): string
    {
        return match($action) {
            'panic_triggered' => 'تم تفعيل تنبيه ذعر',
            'panic_acknowledged' => 'تم استلام التنبيه',
            'responder_en_route' => 'المستجيب في الطريق',
            'responder_arrived' => 'وصل المستجيب',
            'panic_resolved' => 'تم حل التنبيه',
            'panic_false_alarm' => 'تم إغلاق التنبيه كإنذار كاذب',
            'panic_escalated' => 'تم تصعيد التنبيه لحادثة طوارئ',
            default => $action,
        };
    }

    /**
     * Broadcast alert for live dashboard
     */
    protected function broadcastAlert(PanicAlert $alert): void
    {
        // This would integrate with Laravel Broadcasting (Pusher/Ably/WebSockets)
        // For now, we log it
        Log::info("Broadcasting panic alert", ['alert_id' => $alert->id]);
    }

    protected function calculateAvgResponseTime(Collection $alerts): ?int
    {
        $times = $alerts
            ->filter(fn($a) => $a->getResponseTimeSeconds() !== null)
            ->map(fn($a) => $a->getResponseTimeSeconds());

        return $times->count() > 0 ? (int) $times->avg() : null;
    }

    protected function calculateAvgResolutionTime(Collection $alerts): ?int
    {
        $times = $alerts
            ->filter(fn($a) => $a->getResolutionTimeSeconds() !== null)
            ->map(fn($a) => $a->getResolutionTimeSeconds());

        return $times->count() > 0 ? (int) $times->avg() : null;
    }
}
