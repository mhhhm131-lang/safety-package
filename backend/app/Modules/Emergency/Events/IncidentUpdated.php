<?php

namespace App\Modules\Emergency\Events;

use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Services\QrMusteringService;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IncidentUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public EmergencyIncident $incident
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('incident.' . $this->incident->id),
            new PrivateChannel('building.' . $this->incident->building_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'incident.updated';
    }

    public function broadcastWith(): array
    {
        $musteringService = app(QrMusteringService::class);
        $stats = $musteringService->getLiveStats($this->incident);

        return [
            'incident_id' => $this->incident->id,
            'status' => $this->incident->status,
            'duration_seconds' => $this->incident->getDurationSeconds(),
            'duration_formatted' => $this->incident->getDurationFormatted(),
            'stats' => $stats,
        ];
    }
}
