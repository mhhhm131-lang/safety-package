<?php

namespace App\Modules\Emergency\Models;

use App\Core\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmergencyMassMessage extends Model
{

    protected $fillable = [
        'incident_id',
        'title',
        'message',
        'message_type',
        'target_type',
        'target_building_id',
        'target_floor_id',
        'target_team_id',
        'target_place_id',
        'channels',
        'total_recipients',
        'delivered_count',
        'read_count',
        'responded_count',
        'sent_by_id',
        'sent_at',
    ];

    protected $casts = [
        'channels' => 'array',
        'sent_at' => 'datetime',
    ];

    protected $attributes = [
        'message_type' => 'alert',
        'target_type' => 'all',
        'total_recipients' => 0,
        'delivered_count' => 0,
        'read_count' => 0,
        'responded_count' => 0,
    ];

    // Message type constants
    const TYPE_ALERT = 'alert';
    const TYPE_UPDATE = 'update';
    const TYPE_INSTRUCTION = 'instruction';
    const TYPE_ALL_CLEAR = 'all_clear';

    // Target type constants
    const TARGET_ALL = 'all';
    const TARGET_BUILDING = 'building';
    const TARGET_FLOOR = 'floor';
    const TARGET_TEAM = 'team';
    const TARGET_PLACE = 'place';
    const TARGET_CUSTOM = 'custom';

    // Relationships
    public function place(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Governance\Models\Place::class, 'target_place_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(EmergencyIncident::class, 'incident_id');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_id');
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(EmergencyBuilding::class, 'target_building_id');
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(BuildingFloor::class, 'target_floor_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(EmergencyTeam::class, 'target_team_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(EmergencyMessageResponse::class, 'message_id');
    }

    // Scopes
    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('sent_at', '>=', now()->subHours($hours));
    }

    public function scopeForIncident($query, int $incidentId)
    {
        return $query->where('incident_id', $incidentId);
    }

    // Helpers
    public function getTypeLabel(): string
    {
        return match($this->message_type) {
            'alert' => 'تنبيه',
            'update' => 'تحديث',
            'instruction' => 'تعليمات',
            'all_clear' => 'انتهاء الخطر',
            default => $this->message_type,
        };
    }

    public function getTypeColor(): string
    {
        return match($this->message_type) {
            'alert' => 'danger',
            'update' => 'info',
            'instruction' => 'warning',
            'all_clear' => 'success',
            default => 'secondary',
        };
    }

    public function getTargetLabel(): string
    {
        return match($this->target_type) {
            'all' => 'الجميع',
            'building' => $this->building?->name ?? 'مبنى',
            'floor' => $this->floor?->getDisplayName() ?? 'طابق',
            'team' => $this->team?->name ?? 'فريق',
            'custom' => 'مخصص',
            default => $this->target_type,
        };
    }

    public function getChannelsLabels(): array
    {
        $labels = [
            'sms' => 'رسالة نصية',
            'push' => 'إشعار',
            'email' => 'بريد',
            'app' => 'التطبيق',
            'whatsapp' => 'واتساب',
            'slack' => 'Slack',
            'teams' => 'Teams',
        ];

        return array_map(fn($c) => $labels[$c] ?? $c, $this->channels ?? []);
    }

    public function getDeliveryRate(): float
    {
        if ($this->total_recipients === 0) {
            return 0;
        }
        return round(($this->delivered_count / $this->total_recipients) * 100, 1);
    }

    public function getReadRate(): float
    {
        if ($this->delivered_count === 0) {
            return 0;
        }
        return round(($this->read_count / $this->delivered_count) * 100, 1);
    }

    public function getResponseRate(): float
    {
        if ($this->delivered_count === 0) {
            return 0;
        }
        return round(($this->responded_count / $this->delivered_count) * 100, 1);
    }

    public function getStats(): array
    {
        $responses = $this->responses;

        return [
            'total_recipients' => $this->total_recipients,
            'delivered' => $this->delivered_count,
            'read' => $this->read_count,
            'responded' => $this->responded_count,
            'delivery_rate' => $this->getDeliveryRate(),
            'read_rate' => $this->getReadRate(),
            'response_rate' => $this->getResponseRate(),
            'responses_by_type' => [
                'safe' => $responses->where('response_type', 'safe')->count(),
                'need_help' => $responses->where('response_type', 'need_help')->count(),
                'evacuating' => $responses->where('response_type', 'evacuating')->count(),
                'not_present' => $responses->where('response_type', 'not_present')->count(),
            ],
        ];
    }

    public function incrementDelivered(): void
    {
        $this->increment('delivered_count');
    }

    public function incrementRead(): void
    {
        $this->increment('read_count');
    }

    public function incrementResponded(): void
    {
        $this->increment('responded_count');
    }
}
