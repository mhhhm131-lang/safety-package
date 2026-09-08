<?php

namespace App\Modules\Emergency\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BuildingFloor extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'building_id',
        'floor_number',
        'name',
        'zone',
        'capacity',
        'current_occupants',
        'evacuation_time_sec',
        'evacuation_order',
        'responsible_id',
        'status',
        'floor_plan_file',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'normal',
        'current_occupants' => 0,
    ];

    const STATUS_NORMAL = 'normal';
    const STATUS_EVACUATING = 'evacuating';
    const STATUS_CLEARED = 'cleared';
    const STATUS_BLOCKED = 'blocked';

    // Relationships
    public function building(): BelongsTo
    {
        return $this->belongsTo(EmergencyBuilding::class, 'building_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function exits(): HasMany
    {
        return $this->hasMany(BuildingExit::class, 'floor_id');
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(EmergencyEquipment::class, 'floor_id');
    }

    public function checkIns(): HasMany
    {
        return $this->hasMany(EvacuationCheckIn::class, 'floor_id');
    }

    // Helpers
    public function getDisplayName(): string
    {
        if ($this->name) {
            return $this->name;
        }

        if ($this->floor_number < 0) {
            return 'بدروم ' . abs($this->floor_number);
        }

        if ($this->floor_number === 0) {
            return 'الدور الأرضي';
        }

        return 'الدور ' . $this->floor_number;
    }

    public function getStatusLabel(): string
    {
        return match($this->status) {
            'normal' => 'طبيعي',
            'evacuating' => 'جاري الإخلاء',
            'cleared' => 'تم الإخلاء',
            'blocked' => 'مغلق',
            default => $this->status,
        };
    }

    public function isCleared(): bool
    {
        return $this->status === self::STATUS_CLEARED;
    }

    public function getAvailableExits()
    {
        return $this->exits()->where('status', 'available')->get();
    }
}
