<?php

namespace App\Modules\Emergency\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmergencyMessageResponse extends Model
{
    protected $fillable = [
        'message_id',
        'user_id',
        'response_type',
        'response_text',
        'latitude',
        'longitude',
        'delivered_at',
        'read_at',
        'responded_at',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    // Response type constants
    const TYPE_SAFE = 'safe';
    const TYPE_NEED_HELP = 'need_help';
    const TYPE_EVACUATING = 'evacuating';
    const TYPE_NOT_PRESENT = 'not_present';
    const TYPE_CUSTOM = 'custom';

    // Relationships
    public function message(): BelongsTo
    {
        return $this->belongsTo(EmergencyMassMessage::class, 'message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Scopes
    public function scopeSafe($query)
    {
        return $query->where('response_type', self::TYPE_SAFE);
    }

    public function scopeNeedHelp($query)
    {
        return $query->where('response_type', self::TYPE_NEED_HELP);
    }

    public function scopeEvacuating($query)
    {
        return $query->where('response_type', self::TYPE_EVACUATING);
    }

    public function scopeNotPresent($query)
    {
        return $query->where('response_type', self::TYPE_NOT_PRESENT);
    }

    // Helpers
    public function getResponseLabel(): string
    {
        return match($this->response_type) {
            'safe' => 'آمن',
            'need_help' => 'يحتاج مساعدة',
            'evacuating' => 'جاري الإخلاء',
            'not_present' => 'ليس في المبنى',
            'custom' => 'رد مخصص',
            default => $this->response_type,
        };
    }

    public function getResponseColor(): string
    {
        return match($this->response_type) {
            'safe' => 'success',
            'need_help' => 'danger',
            'evacuating' => 'warning',
            'not_present' => 'secondary',
            'custom' => 'info',
            default => 'secondary',
        };
    }

    public function getResponseIcon(): string
    {
        return match($this->response_type) {
            'safe' => 'bi-check-circle-fill',
            'need_help' => 'bi-exclamation-triangle-fill',
            'evacuating' => 'bi-arrow-right-circle-fill',
            'not_present' => 'bi-geo-alt-fill',
            'custom' => 'bi-chat-text-fill',
            default => 'bi-question-circle',
        };
    }

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function getLocationString(): ?string
    {
        if (!$this->hasLocation()) {
            return null;
        }
        return "{$this->latitude}, {$this->longitude}";
    }

    public function markAsDelivered(): void
    {
        if (!$this->delivered_at) {
            $this->update(['delivered_at' => now()]);
            $this->message?->incrementDelivered();
        }
    }

    public function markAsRead(): void
    {
        if (!$this->read_at) {
            $this->update(['read_at' => now()]);
            $this->message?->incrementRead();
        }
    }

    public function getResponseTimeSeconds(): ?int
    {
        if (!$this->responded_at || !$this->delivered_at) {
            return null;
        }
        return $this->responded_at->diffInSeconds($this->delivered_at);
    }
}
