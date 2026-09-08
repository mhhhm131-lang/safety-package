<?php

namespace App\Modules\Emergency\Models;

use App\Core\Traits\HasAuditLog;
use App\Models\User;
use App\Modules\Governance\Models\Place;
use App\Modules\Incident\Models\Incident;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * الحالة الطارئة (من OHSMS بلا tenant). المعهد: تُربط بالمكان (place_id)، وتحكمها آلة حالة رسمية
 * active → contained → ended، any → cancelled (EmergencyStateMachine) بدل تعديل الحالة مباشرة.
 */
class EmergencyIncident extends Model
{
    use HasAuditLog;

    protected $fillable = [
        'building_id', 'place_id', 'incident_code', 'incident_type', 'severity', 'status', 'is_drill',
        'triggered_at', 'contained_at', 'ended_at', 'cancelled_at',
        'triggered_by_id', 'contained_by_id', 'ended_by_id', 'cancelled_by_id', 'cancel_reason',
        'affected_floors', 'evacuation_time_sec', 'total_evacuees', 'total_safe', 'total_injured', 'total_missing',
        'description', 'initial_report', 'final_report', 'linked_incident_id',
        'escalation_level', 'escalated_at', 'acknowledged_at', 'acknowledged_by_id', 'ics_data',
    ];

    protected $casts = [
        'is_drill' => 'boolean', 'triggered_at' => 'datetime', 'contained_at' => 'datetime', 'ended_at' => 'datetime',
        'cancelled_at' => 'datetime', 'escalated_at' => 'datetime', 'acknowledged_at' => 'datetime',
        'affected_floors' => 'array', 'ics_data' => 'array', 'escalation_level' => 'integer',
    ];

    protected $attributes = ['status' => 'active', 'severity' => 'high', 'is_drill' => false, 'escalation_level' => 1];

    const STATUS_ACTIVE = 'active';
    const STATUS_CONTAINED = 'contained';
    const STATUS_ENDED = 'ended';
    const STATUS_CANCELLED = 'cancelled';

    public const OPEN_STATUSES = ['active', 'contained'];

    const TYPE_FIRE = 'fire';
    const TYPE_EVACUATION = 'evacuation';
    const TYPE_CHEMICAL_SPILL = 'chemical_spill';
    const TYPE_MEDICAL = 'medical';
    const TYPE_EARTHQUAKE = 'earthquake';
    const TYPE_FLOOD = 'flood';
    const TYPE_SECURITY = 'security';
    const TYPE_BOMB_THREAT = 'bomb_threat';
    const TYPE_GAS_LEAK = 'gas_leak';
    const TYPE_STRUCTURAL = 'structural';
    const TYPE_DRILL = 'drill';
    const TYPE_LOCKDOWN = 'lockdown';
    const TYPE_OTHER = 'other';

    public const TYPES = [
        'fire' => 'حريق', 'evacuation' => 'إخلاء', 'chemical_spill' => 'تسرب كيميائي', 'medical' => 'طوارئ طبية',
        'earthquake' => 'زلزال', 'flood' => 'فيضان', 'security' => 'أمني', 'bomb_threat' => 'تهديد بقنبلة',
        'gas_leak' => 'تسرب غاز', 'structural' => 'انهيار', 'drill' => 'تمرين', 'lockdown' => 'إغلاق أمني', 'other' => 'أخرى',
    ];

    const SEVERITY_LOW = 'low';
    const SEVERITY_MEDIUM = 'medium';
    const SEVERITY_HIGH = 'high';
    const SEVERITY_CRITICAL = 'critical';

    public const SEVERITIES = ['low' => 'منخفض', 'medium' => 'متوسط', 'high' => 'مرتفع', 'critical' => 'حرج'];

    public const STATUS_LABELS = ['active' => 'نشطة', 'contained' => 'تمت السيطرة', 'ended' => 'انتهت', 'cancelled' => 'ملغاة'];
    public const STATUS_COLORS = ['active' => 'danger', 'contained' => 'warning', 'ended' => 'success', 'cancelled' => 'secondary'];

    public function auditLabel(): string
    {
        return $this->incident_code.' '.$this->getTypeLabel();
    }

    public function building(): BelongsTo { return $this->belongsTo(EmergencyBuilding::class, 'building_id'); }
    public function place(): BelongsTo { return $this->belongsTo(Place::class); }
    public function triggeredBy(): BelongsTo { return $this->belongsTo(User::class, 'triggered_by_id'); }
    public function containedBy(): BelongsTo { return $this->belongsTo(User::class, 'contained_by_id'); }
    public function endedBy(): BelongsTo { return $this->belongsTo(User::class, 'ended_by_id'); }
    public function cancelledBy(): BelongsTo { return $this->belongsTo(User::class, 'cancelled_by_id'); }
    public function acknowledgedBy(): BelongsTo { return $this->belongsTo(User::class, 'acknowledged_by_id'); }
    public function linkedIncident(): BelongsTo { return $this->belongsTo(Incident::class, 'linked_incident_id'); }
    public function eventLogs(): HasMany { return $this->hasMany(EmergencyEventLog::class, 'incident_id')->orderBy('logged_at')->orderBy('id'); }
    public function checkIns(): HasMany { return $this->hasMany(EvacuationCheckIn::class, 'incident_id'); }
    public function drill(): HasOne { return $this->hasOne(EvacuationDrill::class, 'incident_id'); }
    public function notifications(): HasMany { return $this->hasMany(EmergencyNotification::class, 'incident_id'); }
    public function lockdown(): HasOne { return $this->hasOne(Lockdown::class, 'incident_id'); }
    public function afterActionReport(): HasOne { return $this->hasOne(AfterActionReport::class, 'incident_id'); }
    public function massMessages(): HasMany { return $this->hasMany(EmergencyMassMessage::class, 'incident_id'); }

    public function scopeActive($query) { return $query->where('status', self::STATUS_ACTIVE); }
    public function scopeOpen($query) { return $query->whereIn('status', self::OPEN_STATUSES); }
    public function scopeNotDrill($query) { return $query->where('is_drill', false); }

    public function isActive(): bool { return $this->status === self::STATUS_ACTIVE; }
    public function isOpen(): bool { return in_array($this->status, self::OPEN_STATUSES, true); }

    public function getTypeLabel(): string { return self::TYPES[$this->incident_type] ?? 'أخرى'; }
    public function getSeverityLabel(): string { return self::SEVERITIES[$this->severity] ?? $this->severity; }
    public function getStatusLabel(): string { return self::STATUS_LABELS[$this->status] ?? $this->status; }
    public function getStatusColor(): string { return self::STATUS_COLORS[$this->status] ?? 'secondary'; }

    public function getAlertMessage(): string
    {
        $where = $this->place?->name ?? $this->building?->name ?? '';
        if ($this->is_drill) {
            return "تمرين إخلاء — {$where}";
        }
        return "حالة طوارئ: {$this->getTypeLabel()} — {$where}";
    }

    public function getDurationSeconds(): ?int
    {
        $end = $this->ended_at ?? $this->cancelled_at ?? now();
        return (int) abs($end->diffInSeconds($this->triggered_at));
    }

    public function getDurationFormatted(): string
    {
        $seconds = $this->getDurationSeconds() ?? 0;
        return sprintf('%02d:%02d:%02d', floor($seconds / 3600), floor(($seconds % 3600) / 60), $seconds % 60);
    }

    public function getStats(): array
    {
        $checkIns = $this->checkIns();
        return [
            'total' => (clone $checkIns)->count(),
            'safe' => (clone $checkIns)->where('status', 'safe')->count(),
            'evacuating' => (clone $checkIns)->where('status', 'evacuating')->count(),
            'missing' => (clone $checkIns)->where('status', 'missing')->count(),
            'injured' => (clone $checkIns)->where('status', 'injured')->count(),
            'assisted' => (clone $checkIns)->where('status', 'assisted')->count(),
        ];
    }

    /** رمز مقروء بالترتيب: ط-0001 (كما بلاغ الشاغل ش-0001). */
    public static function generateCode(): string
    {
        $next = (int) (static::max('id') ?? 0) + 1;
        return 'ط-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    protected static function booted(): void
    {
        static::creating(function (self $incident) {
            if (empty($incident->incident_code)) $incident->incident_code = self::generateCode();
            if (empty($incident->triggered_at)) $incident->triggered_at = now();
        });
    }
}
