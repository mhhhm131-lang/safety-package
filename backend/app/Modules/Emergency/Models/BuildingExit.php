<?php

namespace App\Modules\Emergency\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuildingExit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'building_id',
        'floor_id',
        'code',
        'name',
        'exit_type',
        'direction',
        'width_meters',
        'capacity_per_min',
        'is_accessible',
        'leads_to_point_id',
        'latitude',
        'longitude',
        'status',
    ];

    protected $casts = [
        'is_accessible' => 'boolean',
        'width_meters' => 'decimal:2',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'created_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'available',
        'exit_type' => 'emergency',
        'is_accessible' => false,
    ];

    const TYPE_MAIN = 'main';
    const TYPE_EMERGENCY = 'emergency';
    const TYPE_FIRE_ESCAPE = 'fire_escape';
    const TYPE_SERVICE = 'service';

    const STATUS_AVAILABLE = 'available';
    const STATUS_BLOCKED = 'blocked';
    const STATUS_MAINTENANCE = 'maintenance';

    // Relationships
    public function building(): BelongsTo
    {
        return $this->belongsTo(EmergencyBuilding::class, 'building_id');
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(BuildingFloor::class, 'floor_id');
    }

    public function assemblyPoint(): BelongsTo
    {
        return $this->belongsTo(AssemblyPoint::class, 'leads_to_point_id');
    }

    // Scopes
    public function scopeAvailable($query)
    {
        return $query->where('status', self::STATUS_AVAILABLE);
    }

    // Helpers
    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE;
    }

    public function getTypeLabel(): string
    {
        return match($this->exit_type) {
            'main' => 'رئيسي',
            'emergency' => 'طوارئ',
            'fire_escape' => 'سلم حريق',
            'service' => 'خدمة',
            default => $this->exit_type,
        };
    }

    public function getStatusLabel(): string
    {
        return match($this->status) {
            'available' => 'متاح',
            'blocked' => 'مغلق',
            'maintenance' => 'صيانة',
            default => $this->status,
        };
    }
}
