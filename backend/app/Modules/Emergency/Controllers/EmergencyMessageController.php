<?php

namespace App\Modules\Emergency\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Models\EmergencyMassMessage;
use App\Modules\Emergency\Services\EmergencyMessagingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmergencyMessageController extends Controller
{
    public function __construct(
        protected EmergencyMessagingService $messagingService
    ) {}

    /**
     * Send a mass message
     */
    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:200',
            'message' => 'required|string|max:2000',
            'message_type' => 'nullable|in:alert,update,instruction,all_clear',
            'target_type' => 'nullable|in:all,building,floor,team,place,custom',
            'target_place_id' => 'nullable|exists:places,id',
            'target_building_id' => 'nullable|exists:emergency_buildings,id',
            'target_floor_id' => 'nullable|exists:building_floors,id',
            'target_team_id' => 'nullable|exists:emergency_teams,id',
            'incident_id' => 'nullable|exists:emergency_incidents,id',
            'channels' => 'nullable|array',
            'channels.*' => 'in:push,app,sms,email,whatsapp,slack,teams',
        ]);

        try {
            $message = $this->messagingService->sendMassMessage($validated, auth()->user());

            return response()->json([
                'success' => true,
                'message' => 'تم إرسال الرسالة',
                'data' => [
                    'message_id' => $message->id,
                    'total_recipients' => $message->total_recipients,
                    'channels' => $message->channels,
                    'sent_at' => $message->sent_at->toIso8601String(),
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشل إرسال الرسالة: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Respond to a message
     */
    public function respond(Request $request, EmergencyMassMessage $message): JsonResponse
    {
        $validated = $request->validate([
            'response_type' => 'required|in:safe,need_help,evacuating,not_present,custom',
            'response_text' => 'nullable|string|max:500',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
        ]);

        try {
            $response = $this->messagingService->recordResponse($message, auth()->user(), $validated);

            return response()->json([
                'success' => true,
                'message' => 'تم تسجيل ردك',
                'data' => [
                    'response_type' => $response->response_type,
                    'response_label' => $response->getResponseLabel(),
                    'responded_at' => $response->responded_at->toIso8601String(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشل تسجيل الرد: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get recent messages
     */
    public function recent(Request $request): JsonResponse
    {
        $hours = $request->get('hours', 24);
        $messages = $this->messagingService->getRecentMessages((int) $hours);

        return response()->json([
            'success' => true,
            'data' => $messages->map(fn($msg) => [
                'id' => $msg->id,
                'title' => $msg->title,
                'message' => $msg->message,
                'type' => $msg->message_type,
                'type_label' => $msg->getTypeLabel(),
                'target' => $msg->getTargetLabel(),
                'channels' => $msg->getChannelsLabels(),
                'total_recipients' => $msg->total_recipients,
                'responded_count' => $msg->responded_count,
                'response_rate' => $msg->getResponseRate(),
                'incident' => $msg->incident ? [
                    'id' => $msg->incident_id,
                    'code' => $msg->incident->incident_code,
                ] : null,
                'sent_by' => $msg->sentBy->name,
                'sent_at' => $msg->sent_at->toIso8601String(),
            ]),
            'count' => $messages->count(),
        ]);
    }

    /**
     * Get message details with responses
     */
    public function show(EmergencyMassMessage $message): JsonResponse
    {
        $message->load(['incident', 'sentBy', 'responses.user', 'building', 'floor', 'team']);

        $stats = $this->messagingService->getResponseStats($message);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $message->id,
                'title' => $message->title,
                'message' => $message->message,
                'type' => $message->message_type,
                'type_label' => $message->getTypeLabel(),
                'target_type' => $message->target_type,
                'target_label' => $message->getTargetLabel(),
                'channels' => $message->channels,
                'channels_labels' => $message->getChannelsLabels(),
                'incident' => $message->incident ? [
                    'id' => $message->incident_id,
                    'code' => $message->incident->incident_code,
                    'type' => $message->incident->getTypeLabel(),
                ] : null,
                'sent_by' => [
                    'id' => $message->sent_by_id,
                    'name' => $message->sentBy->name,
                ],
                'sent_at' => $message->sent_at->toIso8601String(),
                'stats' => $stats,
                'responses' => $message->responses->map(fn($r) => [
                    'user_id' => $r->user_id,
                    'user_name' => $r->user->name,
                    'response_type' => $r->response_type,
                    'response_label' => $r->getResponseLabel(),
                    'response_color' => $r->getResponseColor(),
                    'response_text' => $r->response_text,
                    'has_location' => $r->hasLocation(),
                    'latitude' => $r->latitude,
                    'longitude' => $r->longitude,
                    'responded_at' => $r->responded_at?->toIso8601String(),
                    'response_time' => $r->getResponseTimeSeconds(),
                ]),
            ],
        ]);
    }

    /**
     * Get messages for an incident
     */
    public function incidentMessages(EmergencyIncident $incident): JsonResponse
    {
        $messages = $this->messagingService->getIncidentMessages($incident);

        return response()->json([
            'success' => true,
            'data' => $messages->map(fn($msg) => [
                'id' => $msg->id,
                'title' => $msg->title,
                'type' => $msg->message_type,
                'type_label' => $msg->getTypeLabel(),
                'total_recipients' => $msg->total_recipients,
                'responded_count' => $msg->responded_count,
                'response_rate' => $msg->getResponseRate(),
                'sent_at' => $msg->sent_at->toIso8601String(),
            ]),
            'count' => $messages->count(),
        ]);
    }

    /**
     * Send follow-up to non-responders
     */
    public function sendFollowUp(EmergencyMassMessage $message): JsonResponse
    {
        try {
            $followUp = $this->messagingService->sendFollowUp($message);

            return response()->json([
                'success' => true,
                'message' => 'تم إرسال التذكير',
                'data' => [
                    'message_id' => $followUp->id,
                    'total_recipients' => $followUp->total_recipients,
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
     * Get pending messages for current user
     */
    public function pending(): JsonResponse
    {
        $pending = $this->messagingService->getPendingResponses(auth()->user());

        return response()->json([
            'success' => true,
            'data' => $pending->map(fn($msg) => [
                'id' => $msg->id,
                'title' => $msg->title,
                'message' => $msg->message,
                'type' => $msg->message_type,
                'type_label' => $msg->getTypeLabel(),
                'sent_at' => $msg->sent_at->toIso8601String(),
                'response_options' => [
                    ['value' => 'safe', 'label' => 'أنا آمن', 'color' => 'success'],
                    ['value' => 'need_help', 'label' => 'أحتاج مساعدة', 'color' => 'danger'],
                    ['value' => 'evacuating', 'label' => 'جاري الإخلاء', 'color' => 'warning'],
                    ['value' => 'not_present', 'label' => 'لست في المبنى', 'color' => 'secondary'],
                ],
            ]),
            'count' => $pending->count(),
        ]);
    }

    /**
     * Get response statistics
     */
    public function stats(EmergencyMassMessage $message): JsonResponse
    {
        $stats = $this->messagingService->getResponseStats($message);

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}
