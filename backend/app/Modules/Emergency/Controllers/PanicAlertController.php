<?php

namespace App\Modules\Emergency\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Emergency\Models\PanicAlert;
use App\Modules\Emergency\Services\PanicAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PanicAlertController extends Controller
{
    public function __construct(
        protected PanicAlertService $panicService
    ) {}

    /**
     * Trigger a panic alert
     */
    public function trigger(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'alert_type' => 'nullable|in:panic,medical,fire,security,other',
            'severity' => 'nullable|in:low,medium,high,critical',
            'message' => 'nullable|string|max:1000',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'accuracy_meters' => 'nullable|numeric|min:0',
            'location_description' => 'nullable|string|max:255',
            'building_id' => 'nullable|exists:emergency_buildings,id',
            'place_id' => 'nullable|exists:places,id',
            'voice_recording' => 'nullable|file|mimes:mp3,wav,m4a,ogg|max:10240',
            'photo' => 'nullable|image|max:10240',
        ]);

        $user = auth()->user();

        // المرفقات في القاعدة base64 (Render بلا قرص دائم — كما مرفقات بلاغ الشاغل)
        if ($request->hasFile('voice_recording')) {
            $f = $request->file('voice_recording');
            $validated['voice_mime'] = $f->getMimeType();
            $validated['voice_data'] = base64_encode($f->get());
        }
        if ($request->hasFile('photo')) {
            $f = $request->file('photo');
            $validated['photo_mime'] = $f->getMimeType();
            $validated['photo_data'] = base64_encode($f->get());
        }
        unset($validated['voice_recording'], $validated['photo']);

        try {
            $alert = $this->panicService->trigger($user, $validated);

            return response()->json([
                'success' => true,
                'message' => 'تم إرسال تنبيه الذعر',
                'data' => [
                    'alert_id' => $alert->id,
                    'status' => $alert->status,
                    'type' => $alert->alert_type,
                    'type_label' => $alert->getTypeLabel(),
                    'created_at' => $alert->created_at->toIso8601String(),
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشل في إرسال التنبيه: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get active alerts for current user's tenant
     */
    public function active(): JsonResponse
    {
        $alerts = $this->panicService->getActiveAlerts();

        return response()->json([
            'success' => true,
            'data' => $alerts->map(fn($alert) => [
                'id' => $alert->id,
                'user' => [
                    'id' => $alert->user_id,
                    'name' => $alert->user->name,
                ],
                'alert_type' => $alert->alert_type,
                'type_label' => $alert->getTypeLabel(),
                'severity' => $alert->severity,
                'severity_label' => $alert->getSeverityLabel(),
                'status' => $alert->status,
                'status_label' => $alert->getStatusLabel(),
                'location' => $alert->getLocationString(),
                'latitude' => $alert->latitude,
                'longitude' => $alert->longitude,
                'building' => $alert->building ? [
                    'id' => $alert->building_id,
                    'name' => $alert->building->name,
                ] : null,
                'message' => $alert->message,
                'has_recording' => (bool) $alert->voice_data,
                'has_photo' => (bool) $alert->photo_data,
                'created_at' => $alert->created_at->toIso8601String(),
                'responders' => $alert->responders->map(fn($r) => [
                    'user_id' => $r->user_id,
                    'name' => $r->user->name,
                    'response' => $r->response_type,
                    'response_label' => $r->getResponseLabel(),
                    'responded_at' => $r->responded_at?->toIso8601String(),
                ]),
            ]),
            'count' => $alerts->count(),
        ]);
    }

    /**
     * Get recent alerts
     */
    public function recent(Request $request): JsonResponse
    {
        $hours = $request->get('hours', 24);
        $alerts = $this->panicService->getRecentAlerts((int) $hours);

        return response()->json([
            'success' => true,
            'data' => $alerts->map(fn($alert) => [
                'id' => $alert->id,
                'user' => [
                    'id' => $alert->user_id,
                    'name' => $alert->user->name,
                ],
                'alert_type' => $alert->alert_type,
                'type_label' => $alert->getTypeLabel(),
                'severity' => $alert->severity,
                'status' => $alert->status,
                'status_label' => $alert->getStatusLabel(),
                'location' => $alert->getLocationString(),
                'building' => $alert->building?->name,
                'created_at' => $alert->created_at->toIso8601String(),
                'response_time' => $alert->getResponseTimeSeconds(),
                'resolution_time' => $alert->getResolutionTimeSeconds(),
                'acknowledged_by' => $alert->acknowledgedBy?->name,
                'resolved_by' => $alert->resolvedBy?->name,
            ]),
            'count' => $alerts->count(),
        ]);
    }

    /**
     * Get alert details
     */
    public function show(PanicAlert $alert): JsonResponse
    {
        $alert->load(['user', 'building', 'incident', 'acknowledgedBy', 'resolvedBy', 'responders.user']);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $alert->id,
                'user' => [
                    'id' => $alert->user_id,
                    'name' => $alert->user->name,
                    'phone' => $alert->user->phone,
                ],
                'alert_type' => $alert->alert_type,
                'type_label' => $alert->getTypeLabel(),
                'severity' => $alert->severity,
                'severity_label' => $alert->getSeverityLabel(),
                'status' => $alert->status,
                'status_label' => $alert->getStatusLabel(),
                'status_color' => $alert->getStatusColor(),
                'message' => $alert->message,
                'location' => [
                    'description' => $alert->location_description,
                    'latitude' => $alert->latitude,
                    'longitude' => $alert->longitude,
                    'accuracy' => $alert->accuracy_meters,
                    'display' => $alert->getLocationString(),
                ],
                'building' => $alert->building ? [
                    'id' => $alert->building_id,
                    'name' => $alert->building->name,
                    'address' => $alert->building->address,
                ] : null,
                'has_recording' => (bool) $alert->voice_data,
                'has_photo' => (bool) $alert->photo_data,
                'incident' => $alert->incident ? [
                    'id' => $alert->incident_id,
                    'code' => $alert->incident->incident_code,
                    'status' => $alert->incident->status,
                ] : null,
                'acknowledged_by' => $alert->acknowledgedBy ? [
                    'id' => $alert->acknowledged_by_id,
                    'name' => $alert->acknowledgedBy->name,
                ] : null,
                'acknowledged_at' => $alert->acknowledged_at?->toIso8601String(),
                'resolved_by' => $alert->resolvedBy ? [
                    'id' => $alert->resolved_by_id,
                    'name' => $alert->resolvedBy->name,
                ] : null,
                'resolved_at' => $alert->resolved_at?->toIso8601String(),
                'resolution_notes' => $alert->resolution_notes,
                'response_time_seconds' => $alert->getResponseTimeSeconds(),
                'resolution_time_seconds' => $alert->getResolutionTimeSeconds(),
                'created_at' => $alert->created_at->toIso8601String(),
                'responders' => $alert->responders->map(fn($r) => [
                    'user_id' => $r->user_id,
                    'name' => $r->user->name,
                    'notified_at' => $r->notified_at->toIso8601String(),
                    'seen_at' => $r->seen_at?->toIso8601String(),
                    'responded_at' => $r->responded_at?->toIso8601String(),
                    'response' => $r->response_type,
                    'response_label' => $r->getResponseLabel(),
                    'response_time' => $r->getResponseTimeSeconds(),
                ]),
            ],
        ]);
    }

    /**
     * Acknowledge a panic alert
     */
    public function acknowledge(PanicAlert $alert): JsonResponse
    {
        if (!$alert->canBeAcknowledged()) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن استلام هذا التنبيه في حالته الحالية',
            ], 422);
        }

        try {
            $this->panicService->acknowledge($alert, auth()->user());

            return response()->json([
                'success' => true,
                'message' => 'تم استلام التنبيه',
                'data' => [
                    'status' => $alert->fresh()->status,
                    'acknowledged_at' => $alert->acknowledged_at->toIso8601String(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Update responder status
     */
    public function respond(Request $request, PanicAlert $alert): JsonResponse
    {
        $validated = $request->validate([
            'response_type' => 'required|in:acknowledged,en_route,arrived,unavailable',
        ]);

        $user = auth()->user();

        try {
            match ($validated['response_type']) {
                'acknowledged' => $this->panicService->acknowledge($alert, $user),
                'en_route' => $this->panicService->markEnRoute($alert, $user),
                'arrived' => $this->panicService->markArrived($alert, $user),
                'unavailable' => $this->markUnavailable($alert, $user),
            };

            return response()->json([
                'success' => true,
                'message' => 'تم تحديث حالتك',
                'data' => [
                    'response_type' => $validated['response_type'],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Resolve a panic alert
     */
    public function resolve(Request $request, PanicAlert $alert): JsonResponse
    {
        $validated = $request->validate([
            'resolution_notes' => 'required|string|max:2000',
            'is_false_alarm' => 'nullable|boolean',
        ]);

        if (!$alert->canBeResolved()) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن إغلاق هذا التنبيه في حالته الحالية',
            ], 422);
        }

        try {
            $this->panicService->resolve(
                $alert,
                auth()->user(),
                $validated['resolution_notes'],
                $validated['is_false_alarm'] ?? false
            );

            return response()->json([
                'success' => true,
                'message' => 'تم إغلاق التنبيه',
                'data' => [
                    'status' => $alert->fresh()->status,
                    'resolved_at' => $alert->resolved_at->toIso8601String(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Escalate alert to full incident
     */
    public function escalate(PanicAlert $alert): JsonResponse
    {
        if ($alert->incident_id) {
            return response()->json([
                'success' => false,
                'message' => 'تم تصعيد هذا التنبيه مسبقاً',
            ], 422);
        }

        try {
            $incident = $this->panicService->escalateToIncident($alert);

            return response()->json([
                'success' => true,
                'message' => 'تم تصعيد التنبيه لحادثة طوارئ',
                'data' => [
                    'incident_id' => $incident->id,
                    'incident_code' => $incident->incident_code,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get statistics
     */
    public function stats(Request $request): JsonResponse
    {
        $period = $request->get('period', 'month');
        $stats = $this->panicService->getStats($period);

        return response()->json([
            'success' => true,
            'data' => $stats,
            'period' => $period,
        ]);
    }

    /**
     * Get voice recording
     */
    public function recording(PanicAlert $alert)
    {
        if (!$alert->voice_data) {
            return response()->json(['success' => false, 'message' => 'لا يوجد تسجيل صوتي'], 404);
        }
        return response(base64_decode($alert->voice_data), 200, [
            'Content-Type' => $alert->voice_mime ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="panic_recording_'.$alert->id.'"',
        ]);
    }

    /**
     * Get photo
     */
    public function photo(PanicAlert $alert)
    {
        if (!$alert->photo_data) {
            return response()->json(['success' => false, 'message' => 'لا توجد صورة'], 404);
        }
        return response(base64_decode($alert->photo_data), 200, [
            'Content-Type' => $alert->photo_mime ?: 'image/jpeg',
            'Content-Disposition' => 'inline; filename="panic_photo_'.$alert->id.'"',
        ]);
    }

    /**
     * Mark responder as unavailable
     */
    protected function markUnavailable(PanicAlert $alert, $user): void
    {
        $responderRecord = $alert->responders()->where('user_id', $user->id)->first();
        if ($responderRecord) {
            $responderRecord->respond('unavailable');
        }
    }
}
