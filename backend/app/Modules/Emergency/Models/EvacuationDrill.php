<?php

namespace App\Modules\Emergency\Models;

use App\Core\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvacuationDrill extends Model
{
    use \App\Core\Traits\HasAuditLog;

    protected $fillable = [
        'building_id',
        'place_id',
        'incident_id',
        'drill_code',
        'drill_type',
        'scenario',
        'objectives',
        'scheduled_at',
        'reminder_sent_at',
        'started_at',
        'ended_at',
        'status',
        'expected_participants',
        'actual_participants',
        'evacuation_time_sec',
        'target_time_sec',
        'result',
        'score',
        'observations',
        'issues_found',
        'improvements',
        'conducted_by_id',
        'approved_by_id',
        'report_file',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'scheduled',
        'drill_type' => 'fire',
    ];

    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_POSTPONED = 'postponed';

    const TYPE_FIRE = 'fire';
    const TYPE_EVACUATION = 'evacuation';
    const TYPE_EARTHQUAKE = 'earthquake';
    const TYPE_CHEMICAL = 'chemical';
    const TYPE_FULL_SCALE = 'full_scale';
    const TYPE_TABLETOP = 'tabletop';
    const TYPE_ANNOUNCED = 'announced';
    const TYPE_UNANNOUNCED = 'unannounced';

    const RESULT_PASS = 'pass';
    const RESULT_FAIL = 'fail';
    const RESULT_NEEDS_IMPROVEMENT = 'needs_improvement';

    // Relationships
    public function building(): BelongsTo
    {
        return $this->belongsTo(EmergencyBuilding::class, 'building_id');
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Governance\Models\Place::class);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(EmergencyIncident::class, 'incident_id');
    }

    public function conductedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conducted_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(DrillParticipant::class, 'drill_id');
    }

    // Scopes
    public function scopeScheduled($query)
    {
        return $query->where('status', self::STATUS_SCHEDULED);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeUpcoming($query)
    {
        return $query->where('status', self::STATUS_SCHEDULED)
            ->where('scheduled_at', '>=', now());
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', self::STATUS_SCHEDULED)
            ->where('scheduled_at', '<', now());
    }

    // Helpers
    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_SCHEDULED
            && $this->scheduled_at->isPast();
    }

    public function isPassed(): bool
    {
        return $this->result === self::RESULT_PASS;
    }

    public function getTypeLabel(): string
    {
        return match($this->drill_type) {
            'fire' => 'حريق',
            'evacuation' => 'إخلاء',
            'earthquake' => 'زلزال',
            'chemical' => 'كيميائي',
            'full_scale' => 'شامل',
            'tabletop' => 'نظري',
            'announced' => 'معلن',
            'unannounced' => 'مفاجئ',
            default => $this->drill_type,
        };
    }

    public function getStatusLabel(): string
    {
        return match($this->status) {
            'scheduled' => 'مجدول',
            'in_progress' => 'جاري',
            'completed' => 'مكتمل',
            'cancelled' => 'ملغي',
            'postponed' => 'مؤجل',
            default => $this->status,
        };
    }

    public function getResultLabel(): ?string
    {
        if (!$this->result) return null;

        return match($this->result) {
            'pass' => 'ناجح',
            'fail' => 'فاشل',
            'needs_improvement' => 'يحتاج تحسين',
            default => $this->result,
        };
    }

    public function getDurationMinutes(): ?int
    {
        if (!$this->started_at || !$this->ended_at) {
            return null;
        }
        return $this->ended_at->diffInMinutes($this->started_at);
    }

    public static function generateCode(): string
    {
        $prefix = 'DRL';
        $date = now()->format('ymd');
        $random = strtoupper(substr(uniqid(), -4));
        return "{$prefix}-{$date}-{$random}";
    }

    protected static function booted(): void
    {
        static::creating(function ($drill) {
            if (empty($drill->drill_code)) {
                $drill->drill_code = self::generateCode();
            }
        });
    }
}
