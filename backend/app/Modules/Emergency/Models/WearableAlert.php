<?php

namespace App\Modules\Emergency\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WearableAlert extends Model
{
    protected $fillable = [
        'wearable_id', 'alert_type', 'latitude', 'longitude', 'heart_rate',
        'additional_data', 'status', 'acknowledged_by_id', 'acknowledged_at',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'additional_data' => 'array',
        'acknowledged_at' => 'datetime',
    ];

    const TYPE_PANIC = 'panic';
    const TYPE_FALL = 'fall';
    const TYPE_HEART_RATE = 'heart_rate';
    const TYPE_LOW_BATTERY = 'low_battery';
    const TYPE_SOS = 'sos';

    public function wearable(): BelongsTo
    {
        return $this->belongsTo(EmergencyWearable::class);
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_id');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['triggered', 'acknowledged']);
    }

    public function acknowledge(int $userId): void
    {
        $this->update(['status' => 'acknowledged', 'acknowledged_by_id' => $userId, 'acknowledged_at' => now()]);
    }

    public function resolve(): void
    {
        $this->update(['status' => 'resolved']);
    }

    public function getTypeLabel(): string
    {
        return match($this->alert_type) {
            'panic' => 'زر الطوارئ',
            'fall' => 'كشف سقوط',
            'heart_rate' => 'تنبيه نبض القلب',
            'low_battery' => 'بطارية منخفضة',
            'sos' => 'SOS',
            default => $this->alert_type,
        };
    }
}
