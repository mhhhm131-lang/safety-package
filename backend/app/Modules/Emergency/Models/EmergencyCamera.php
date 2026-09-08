<?php

namespace App\Modules\Emergency\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmergencyCamera extends Model
{
    protected $fillable = [
        'building_id', 'place_id', 'name', 'camera_id', 'location',
        'stream_url', 'snapshot_url', 'type', 'status',
        'is_emergency_priority', 'has_audio', 'latitude', 'longitude', 'floor_number',
    ];

    protected $casts = [
        'is_emergency_priority' => 'boolean',
        'has_audio' => 'boolean',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
    ];

    public function place(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Governance\Models\Place::class);
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(EmergencyBuilding::class);
    }

    public function scopeOnline($query)
    {
        return $query->where('status', 'online');
    }

    public function scopePriority($query)
    {
        return $query->where('is_emergency_priority', true);
    }

    public function isOnline(): bool
    {
        return $this->status === 'online';
    }

    public function getTypeLabel(): string
    {
        return match($this->type) {
            'fixed' => 'ثابتة',
            'ptz' => 'PTZ متحركة',
            'dome' => 'قبة',
            'thermal' => 'حرارية',
            default => $this->type,
        };
    }
}
