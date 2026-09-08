<?php

namespace App\Modules\Emergency\Events;

use App\Modules\Emergency\Models\EmergencyIncident;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EmergencyEnded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public EmergencyIncident $incident
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('building.' . $this->incident->building_id),
            new PrivateChannel('incident.' . $this->incident->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'emergency.ended';
    }

    public function broadcastWith(): array
    {
        return [
            'incident_id' => $this->incident->id,
            'incident_code' => $this->incident->incident_code,
            'building' => [
                'id' => $this->incident->building->id,
                'name' => $this->incident->building->name,
            ],
            'ended_at' => $this->incident->ended_at->toIso8601String(),
            'total_duration_seconds' => $this->incident->evacuation_time_sec,
            'stats' => [
                'total_evacuees' => $this->incident->total_evacuees,
                'total_safe' => $this->incident->total_safe,
                'total_injured' => $this->incident->total_injured,
                'total_missing' => $this->incident->total_missing,
            ],
            'sound' => 'all_clear',
        ];
    }
}
