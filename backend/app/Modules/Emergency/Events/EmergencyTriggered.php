<?php

namespace App\Modules\Emergency\Events;

use App\Modules\Emergency\Models\EmergencyIncident;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EmergencyTriggered implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public EmergencyIncident $incident
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('building.' . $this->incident->building_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'emergency.triggered';
    }

    public function broadcastWith(): array
    {
        return [
            'incident_id' => $this->incident->id,
            'incident_code' => $this->incident->incident_code,
            'type' => $this->incident->incident_type,
            'type_label' => $this->incident->getTypeLabel(),
            'severity' => $this->incident->severity,
            'severity_label' => $this->incident->getSeverityLabel(),
            'is_drill' => $this->incident->is_drill,
            'building' => [
                'id' => $this->incident->building->id,
                'name' => $this->incident->building->name,
            ],
            'triggered_at' => $this->incident->triggered_at->toIso8601String(),
            'alert_message' => $this->incident->getAlertMessage(),
            'sound' => $this->incident->is_drill ? 'drill' : 'emergency',
        ];
    }
}
