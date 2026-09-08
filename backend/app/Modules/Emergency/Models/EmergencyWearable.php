<?php

namespace App\Modules\Emergency\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmergencyWearable extends Model
{
    protected $fillable = [
        'user_id', 'device_id', 'device_type', 'device_model', 'push_token',
        'is_active', 'panic_enabled', 'fall_detection_enabled', 'heart_rate_alert_enabled',
        'last_heartbeat_at', 'last_latitude', 'last_longitude', 'last_heart_rate', 'battery_level',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'panic_enabled' => 'boolean',
        'fall_detection_enabled' => 'boolean',
        'heart_rate_alert_enabled' => 'boolean',
        'last_heartbeat_at' => 'datetime',
        'last_latitude' => 'decimal:8',
        'last_longitude' => 'decimal:8',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(WearableAlert::class, 'wearable_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function isStale(int $minutes = 30): bool
    {
        return !$this->last_heartbeat_at || $this->last_heartbeat_at->diffInMinutes(now()) > $minutes;
    }

    public function updateHeartbeat(array $data): void
    {
        $this->update(array_merge($data, ['last_heartbeat_at' => now()]));
    }
}
