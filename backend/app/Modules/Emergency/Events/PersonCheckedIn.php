<?php

namespace App\Modules\Emergency\Events;

use App\Modules\Emergency\Models\EvacuationCheckIn;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PersonCheckedIn implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public EvacuationCheckIn $checkIn
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('incident.' . $this->checkIn->incident_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'person.checked_in';
    }

    public function broadcastWith(): array
    {
        return [
            'check_in_id' => $this->checkIn->id,
            'person_name' => $this->checkIn->getPersonName(),
            'person_type' => $this->checkIn->getPersonTypeLabel(),
            'status' => $this->checkIn->status,
            'status_label' => $this->checkIn->getStatusLabel(),
            'assembly_point' => $this->checkIn->assemblyPoint ? [
                'id' => $this->checkIn->assemblyPoint->id,
                'name' => $this->checkIn->assemblyPoint->name,
                'code' => $this->checkIn->assemblyPoint->code,
            ] : null,
            'checked_in_at' => $this->checkIn->checked_in_at?->toIso8601String(),
        ];
    }
}
