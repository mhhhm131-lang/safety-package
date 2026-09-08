<?php

namespace App\Modules\Emergency\Models;

use App\Core\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PanicAlert extends Model
{

    protected $fillable = [
        'user_id',
        'building_id',
        'latitude',
        'longitude',
        'accuracy_meters',
        'location_description',
        'alert_type',
        'severity',
        'status',
        'message',
        'place_id',
        'voice_mime',
        'voice_data',
        'photo_mime',
        'photo_data',
        'acknowledged_by_id',
        'acknowledged_at',
        'resolved_by_id',
        'resolved_at',
        'resolution_notes',
        'incident_id',
    ];

    protected $hidden = ['voice_data', 'photo_data'];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'accuracy_meters' => 'decimal:2',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    protected $attributes = [
        'alert_type' => 'panic',
        'severity' => 'high',
        'status' => 'triggered',
    ];

    // Status constants
    const STATUS_TRIGGERED = 'triggered';
    const STATUS_ACKNOWLEDGED = 'acknowledged';
    const STATUS_RESPONDING = 'responding';
    const STATUS_RESOLVED = 'resolved';
    const STATUS_FALSE_ALARM = 'false_alarm';

    // Type constants
    const TYPE_PANIC = 'panic';
    const TYPE_MEDICAL = 'medical';
    const TYPE_FIRE = 'fire';
    const TYPE_SECURITY = 'security';
    const TYPE_OTHER = 'other';

    // Severity constants
    const SEVERITY_LOW = 'low';
    const SEVERITY_MEDIUM = 'medium';
    const SEVERITY_HIGH = 'high';
    const SEVERITY_CRITICAL = 'critical';

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Governance\Models\Place::class);
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(EmergencyBuilding::class, 'building_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(EmergencyIncident::class, 'incident_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    public function responders(): HasMany
    {
        return $this->hasMany(PanicAlertResponder::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_TRIGGERED, self::STATUS_ACKNOWLEDGED, self::STATUS_RESPONDING]);
    }

    public function scopeTriggered($query)
    {
        return $query->where('status', self::STATUS_TRIGGERED);
    }

    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }

    public function scopeCritical($query)
    {
        return $query->where('severity', self::SEVERITY_CRITICAL);
    }

    // Helpers
    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_TRIGGERED, self::STATUS_ACKNOWLEDGED, self::STATUS_RESPONDING]);
    }

    public function canBeAcknowledged(): bool
    {
        return $this->status === self::STATUS_TRIGGERED;
    }

    public function canBeResolved(): bool
    {
        return in_array($this->status, [self::STATUS_TRIGGERED, self::STATUS_ACKNOWLEDGED, self::STATUS_RESPONDING]);
    }

    public function getTypeLabel(): string
    {
        return match($this->alert_type) {
            'panic' => 'ذعر',
            'medical' => 'طبي',
            'fire' => 'حريق',
            'security' => 'أمني',
            'other' => 'أخرى',
            default => $this->alert_type,
        };
    }

    public function getSeverityLabel(): string
    {
        return match($this->severity) {
            'low' => 'منخفض',
            'medium' => 'متوسط',
            'high' => 'مرتفع',
            'critical' => 'حرج',
            default => $this->severity,
        };
    }

    public function getStatusLabel(): string
    {
        return match($this->status) {
            'triggered' => 'تم التفعيل',
            'acknowledged' => 'تم الاستلام',
            'responding' => 'جاري الاستجابة',
            'resolved' => 'تم الحل',
            'false_alarm' => 'إنذار كاذب',
            default => $this->status,
        };
    }

    public function getStatusColor(): string
    {
        return match($this->status) {
            'triggered' => 'red',
            'acknowledged' => 'yellow',
            'responding' => 'blue',
            'resolved' => 'green',
            'false_alarm' => 'gray',
            default => 'gray',
        };
    }

    public function getSeverityColor(): string
    {
        return match($this->severity) {
            'low' => 'green',
            'medium' => 'yellow',
            'high' => 'orange',
            'critical' => 'red',
            default => 'gray',
        };
    }

    public function getLocationString(): ?string
    {
        if ($this->location_description) {
            return $this->location_description;
        }

        if ($this->latitude && $this->longitude) {
            return "{$this->latitude}, {$this->longitude}";
        }

        if ($this->building) {
            return $this->building->name;
        }

        return null;
    }

    public function getResponseTimeSeconds(): ?int
    {
        if (!$this->acknowledged_at) {
            return null;
        }
        return $this->acknowledged_at->diffInSeconds($this->created_at);
    }

    public function getResolutionTimeSeconds(): ?int
    {
        if (!$this->resolved_at) {
            return null;
        }
        return $this->resolved_at->diffInSeconds($this->created_at);
    }

    public static function generateAlertMessage(string $type, ?EmergencyBuilding $building = null): string
    {
        $typeLabel = match($type) {
            'panic' => 'تنبيه ذعر',
            'medical' => 'طوارئ طبية',
            'fire' => 'تنبيه حريق',
            'security' => 'تهديد أمني',
            default => 'تنبيه طوارئ',
        };

        if ($building) {
            return "{$typeLabel} - {$building->name}";
        }

        return $typeLabel;
    }
}
