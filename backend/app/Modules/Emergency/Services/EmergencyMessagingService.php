<?php

namespace App\Modules\Emergency\Services;

use App\Models\User;
use App\Modules\Governance\Models\UserProfile;
use App\Core\Services\NotificationService;
use Illuminate\Support\Facades\Mail;
use App\Modules\Emergency\Models\BuildingFloor;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyEventLog;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Models\EmergencyMassMessage;
use App\Modules\Emergency\Models\EmergencyMessageResponse;
use App\Modules\Emergency\Models\EmergencyTeam;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EmergencyMessagingService
{
    public function __construct(
        protected EmergencyNotificationService $notificationService
    ) {}

    /**
     * Send a mass message to targeted recipients
     */
    public function sendMassMessage(array $data, User $sender): EmergencyMassMessage
    {
        return DB::transaction(function () use ($data, $sender) {
            // Create the message
            $message = EmergencyMassMessage::create([
                'incident_id' => $data['incident_id'] ?? null,
                'title' => $data['title'],
                'message' => $data['message'],
                'message_type' => $data['message_type'] ?? 'alert',
                'target_type' => $data['target_type'] ?? 'all',
                'target_building_id' => $data['target_building_id'] ?? null,
                'target_floor_id' => $data['target_floor_id'] ?? null,
                'target_team_id' => $data['target_team_id'] ?? null,
                'target_place_id' => $data['target_place_id'] ?? null,
                'channels' => $data['channels'] ?? ['app', 'email'],
                'sent_by_id' => $sender->id,
                'sent_at' => now(),
            ]);

            // Get recipients
            $recipients = $this->getRecipients($message);
            $message->update(['total_recipients' => $recipients->count()]);

            // Send to channels
            $this->sendToChannels($message, $recipients);

            // Log the event
            $this->logEvent($message, 'mass_message_sent', [
                'title' => $message->title,
                'recipients_count' => $recipients->count(),
                'channels' => $message->channels,
            ]);

            Log::info("Mass message sent", [
                'message_id' => $message->id,
                'recipients' => $recipients->count(),
                'channels' => $message->channels,
            ]);

            return $message;
        });
    }

    /**
     * Record a user's response to a message
     */
    public function recordResponse(EmergencyMassMessage $message, User $user, array $responseData): EmergencyMessageResponse
    {
        return DB::transaction(function () use ($message, $user, $responseData) {
            // Check if response already exists
            $existingResponse = EmergencyMessageResponse::where('message_id', $message->id)
                ->where('user_id', $user->id)
                ->first();

            if ($existingResponse) {
                // OHSMS: سجل الرد يُنشأ عند الإرسال (للتتبع) فكان الرد الفعلي لا يزيد العدّاد — الإصلاح: يُعدّ أول رد
                $firstResponse = $existingResponse->responded_at === null;
                $existingResponse->update([
                    'response_type' => $responseData['response_type'],
                    'response_text' => $responseData['response_text'] ?? null,
                    'latitude' => $responseData['latitude'] ?? null,
                    'longitude' => $responseData['longitude'] ?? null,
                    'read_at' => $existingResponse->read_at ?? now(),
                    'responded_at' => now(),
                ]);
                if ($firstResponse) {
                    $message->incrementResponded();
                    $this->logEvent($message, 'message_response_received', ['user_name' => $user->name, 'response_type' => $responseData['response_type']]);
                    if ($responseData['response_type'] === 'need_help') {
                        $this->notifyHelpRequest($message, $user, $responseData);
                    }
                }
                return $existingResponse;
            }

            // Create new response
            $response = EmergencyMessageResponse::create([
                'message_id' => $message->id,
                'user_id' => $user->id,
                'response_type' => $responseData['response_type'],
                'response_text' => $responseData['response_text'] ?? null,
                'latitude' => $responseData['latitude'] ?? null,
                'longitude' => $responseData['longitude'] ?? null,
                'delivered_at' => $responseData['delivered_at'] ?? now(),
                'read_at' => $responseData['read_at'] ?? now(),
                'responded_at' => now(),
            ]);

            // Update message counters
            $message->incrementResponded();

            // Log the event
            $this->logEvent($message, 'message_response_received', [
                'user_name' => $user->name,
                'response_type' => $responseData['response_type'],
            ]);

            // If user needs help, notify incident commander
            if ($responseData['response_type'] === 'need_help') {
                $this->notifyHelpRequest($message, $user, $responseData);
            }

            return $response;
        });
    }

    /**
     * Get response statistics for a message
     */
    public function getResponseStats(EmergencyMassMessage $message): array
    {
        return $message->getStats();
    }

    /**
     * Send follow-up to non-responders
     */
    public function sendFollowUp(EmergencyMassMessage $originalMessage): EmergencyMassMessage
    {
        $responderIds = $originalMessage->responses()->pluck('user_id');

        $nonResponders = $this->getRecipients($originalMessage)
            ->whereNotIn('id', $responderIds);

        if ($nonResponders->isEmpty()) {
            throw new \RuntimeException('جميع المستلمين قد ردوا');
        }

        // Create follow-up message
        $followUp = EmergencyMassMessage::create([
            'incident_id' => $originalMessage->incident_id,
            'title' => "[تذكير] {$originalMessage->title}",
            'message' => "لم نتلقَ ردك بعد. {$originalMessage->message}",
            'message_type' => $originalMessage->message_type,
            'target_type' => 'custom',
            'channels' => $originalMessage->channels,
            'total_recipients' => $nonResponders->count(),
            'sent_by_id' => $originalMessage->sent_by_id,
            'sent_at' => now(),
        ]);

        $this->sendToChannels($followUp, $nonResponders);

        return $followUp;
    }

    /**
     * Get recipients based on target type
     */
    protected function getRecipients(EmergencyMassMessage $message): Collection
    {
        $query = UserProfile::query()->where('is_active', true)->with('user');

        switch ($message->target_type) {
            case 'building':
            case 'floor':
                // المعهد مبنى واحد ولا يُسجَّل الطابق على الحساب: الكل
                break;

            case 'place':
                // المعهد: شاغلو المكان بحساب (place_id في ملف المستخدم)
                if ($message->target_place_id) {
                    $query->where('place_id', $message->target_place_id);
                }
                break;

            case 'team':
                if ($message->target_team_id) {
                    $teamMemberIds = EmergencyTeam::where('id', $message->target_team_id)->first()
                        ?->members()->whereNotNull('user_id')->pluck('user_id') ?? collect();
                    $query->whereIn('user_id', $teamMemberIds);
                }
                break;

            case 'all':
            default:
                break;
        }

        return $query->get()
            ->map(fn($profile) => $profile->user)
            ->filter();
    }

    /**
     * Send message through selected channels
     */
    public function sendToChannels(EmergencyMassMessage $message, Collection $recipients): void
    {
        foreach ($recipients as $user) {
            // Create response record (for tracking delivery)
            $response = EmergencyMessageResponse::firstOrCreate(
                [
                    'message_id' => $message->id,
                    'user_id' => $user->id,
                ],
                [
                    'response_type' => 'safe', // Will be updated when user responds
                    'delivered_at' => null,
                    'read_at' => null,
                    'responded_at' => null,
                ]
            );

            foreach ($message->channels as $channel) {
                try {
                    $this->sendToChannel($message, $user, $channel);
                    $response->markAsDelivered();
                } catch (\Exception $e) {
                    Log::warning("Failed to send {$channel} notification", [
                        'message_id' => $message->id,
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Send to a specific channel
     */
    protected function sendToChannel(EmergencyMassMessage $message, User $user, string $channel): void
    {
        try {
            match ($channel) {
                'push' => $this->sendPushNotification($message, $user),
                'app' => $this->sendInAppNotification($message, $user),
                'sms' => $this->sendSmsNotification($message, $user),
                'email' => $this->sendEmailNotification($message, $user),
                'whatsapp' => $this->sendWhatsAppNotification($message, $user),
                default => null,
            };
        } catch (\Exception $e) {
            Log::warning("Channel {$channel} notification failed: {$e->getMessage()}");
            throw $e;
        }
    }

    /** لا تطبيق جوال ولا FCM: الدفع يُسجَّل غير متاح (§٦: داخل النظام + بريد). */
    protected function sendPushNotification(EmergencyMassMessage $message, User $user): void
    {
        Log::info("Push channel not available (no mobile app): {$message->title}");
    }

    /** داخل النظام (صندوق الوارد) — يرسل البريد أيضاً إن كان للمستخدم بريد. */
    protected function sendInAppNotification(EmergencyMassMessage $message, User $user): void
    {
        app(NotificationService::class)->create($user->id, 'emergency.message', $message->title, $message->message,
            '/app/emergency/messages/'.$message->id);
    }

    protected function sendSmsNotification(EmergencyMassMessage $message, User $user): void
    {
        Log::info("SMS channel not available (no provider decided): {$message->title}");
    }

    /** بريد مباشر (لمن اختار القناة بلا داخل النظام). */
    protected function sendEmailNotification(EmergencyMassMessage $message, User $user): void
    {
        if (!$user->email) return;
        if (in_array('app', $message->channels ?? [])) return; // أُرسل مع قناة النظام
        Mail::raw($message->message, function ($m) use ($user, $message) {
            $m->to($user->email, $user->name)->subject('[طوارئ] '.$message->title);
        });
    }

    protected function sendWhatsAppNotification(EmergencyMassMessage $message, User $user): void
    {
        Log::info("WhatsApp channel rejected by decision §6: {$message->title}");
    }

    /**
     * Notify about help request
     */
    protected function notifyHelpRequest(EmergencyMassMessage $message, User $user, array $responseData): void
    {
        if (!$message->incident) {
            return;
        }

        EmergencyEventLog::create([
            'incident_id' => $message->incident_id,
            'event_type' => EmergencyEventLog::TYPE_HELP_REQUESTED,
            'severity' => 'critical',
            'message' => "{$user->name} يحتاج مساعدة (رد على رسالة جماعية)",
            'logged_at' => now(),
            'user_id' => $user->id,
            'data' => [
                'user_id' => $user->id,
                'message_text' => $responseData['response_text'] ?? null,
                'latitude' => $responseData['latitude'] ?? null,
                'longitude' => $responseData['longitude'] ?? null,
            ],
        ]);
    }

    /**
     * Get messages for an incident
     */
    public function getIncidentMessages(EmergencyIncident $incident): Collection
    {
        return EmergencyMassMessage::where('incident_id', $incident->id)
            ->with(['sentBy', 'responses.user'])
            ->orderByDesc('sent_at')
            ->get();
    }

    /**
     * Get recent messages for a tenant
     */
    public function getRecentMessages(int $hours = 24): Collection
    {
        return EmergencyMassMessage::query()
            ->recent($hours)
            ->with(['incident', 'sentBy'])
            ->orderByDesc('sent_at')
            ->get();
    }

    /**
     * Get pending responses for a user
     */
    public function getPendingResponses(User $user): Collection
    {
        $respondedMessageIds = EmergencyMessageResponse::where('user_id', $user->id)
            ->whereNotNull('responded_at')
            ->pluck('message_id');

        return EmergencyMassMessage::query()
            ->whereHas('responses', fn ($q) => $q->where('user_id', $user->id))
            ->where('sent_at', '>=', now()->subHours(24))
            ->whereNotIn('id', $respondedMessageIds)
            ->orderByDesc('sent_at')
            ->get();
    }

    /**
     * Log event
     */
    protected function logEvent(EmergencyMassMessage $message, string $action, array $details = []): void
    {
        if (!$message->incident_id) return;
        EmergencyEventLog::create([
            'incident_id' => $message->incident_id,
            'event_type' => EmergencyEventLog::TYPE_MESSAGE,
            'severity' => 'info',
            'message' => $this->getActionDescription($action).': '.$message->title,
            'user_id' => auth()->id(),
            'logged_at' => now(),
            'data' => array_merge($details, ['message_id' => $message->id, 'action' => $action]),
        ]);
    }

    protected function getActionDescription(string $action): string
    {
        return match($action) {
            'mass_message_sent' => 'تم إرسال رسالة جماعية',
            'message_response_received' => 'تم استلام رد على الرسالة',
            default => $action,
        };
    }
}
