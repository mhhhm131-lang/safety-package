<?php

namespace App\Modules\Emergency\Models;

use App\Core\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AfterActionReport extends Model
{

    protected $fillable = [
        'incident_id',
        'building_id',
        'report_number',
        'title',
        'status',
        'severity',
        'incident_start_at',
        'incident_end_at',
        'response_time_minutes',
        'evacuation_time_minutes',
        'resolution_time_minutes',
        'total_occupants',
        'evacuated_count',
        'injuries_count',
        'fatalities_count',
        'missing_count',
        'property_damage_estimate',
        'incident_description',
        'root_cause_analysis',
        'chronology',
        'immediate_actions_taken',
        'notification_effectiveness_score',
        'evacuation_effectiveness_score',
        'communication_effectiveness_score',
        'leadership_effectiveness_score',
        'equipment_effectiveness_score',
        'overall_score',
        'what_went_well',
        'what_went_wrong',
        'lessons_learned',
        'recommendations',
        'osha_reportable',
        'regulatory_notification_required',
        'regulatory_notification_sent',
        'regulatory_notification_sent_at',
        'evidence_files',
        'witness_statements',
        'prepared_by_id',
        'reviewed_by_id',
        'approved_by_id',
        'reviewed_at',
        'approved_at',
        'review_comments',
    ];

    protected $casts = [
        'incident_start_at' => 'datetime',
        'incident_end_at' => 'datetime',
        'chronology' => 'array',
        'what_went_well' => 'array',
        'what_went_wrong' => 'array',
        'lessons_learned' => 'array',
        'recommendations' => 'array',
        'evidence_files' => 'array',
        'witness_statements' => 'array',
        'osha_reportable' => 'boolean',
        'regulatory_notification_required' => 'boolean',
        'regulatory_notification_sent' => 'boolean',
        'regulatory_notification_sent_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
        'overall_score' => 'decimal:1',
    ];

    protected $attributes = [
        'status' => 'draft',
        'severity' => 'moderate',
        'total_occupants' => 0,
        'evacuated_count' => 0,
        'injuries_count' => 0,
        'fatalities_count' => 0,
        'missing_count' => 0,
    ];

    // Status constants
    const STATUS_DRAFT = 'draft';
    const STATUS_UNDER_REVIEW = 'under_review';
    const STATUS_APPROVED = 'approved';
    const STATUS_PUBLISHED = 'published';

    // Severity constants
    const SEVERITY_MINOR = 'minor';
    const SEVERITY_MODERATE = 'moderate';
    const SEVERITY_MAJOR = 'major';
    const SEVERITY_CRITICAL = 'critical';

    // Relationships
    public function incident(): BelongsTo
    {
        return $this->belongsTo(EmergencyIncident::class);
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(EmergencyBuilding::class);
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function correctiveActions(): HasMany
    {
        return $this->hasMany(AarCorrectiveAction::class, 'report_id');
    }

    // Scopes
    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function scopeApproved($query)
    {
        return $query->whereIn('status', [self::STATUS_APPROVED, self::STATUS_PUBLISHED]);
    }

    public function scopeOshaReportable($query)
    {
        return $query->where('osha_reportable', true);
    }

    public function scopeSeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    // Helpers
    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isApproved(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_PUBLISHED]);
    }

    public function calculateOverallScore(): float
    {
        $scores = array_filter([
            $this->notification_effectiveness_score,
            $this->evacuation_effectiveness_score,
            $this->communication_effectiveness_score,
            $this->leadership_effectiveness_score,
            $this->equipment_effectiveness_score,
        ]);

        if (empty($scores)) {
            return 0;
        }

        return round(array_sum($scores) / count($scores), 1);
    }

    public function getEvacuationRate(): float
    {
        if ($this->total_occupants === 0) {
            return 0;
        }

        return round(($this->evacuated_count / $this->total_occupants) * 100, 1);
    }

    public function getIncidentDuration(): ?int
    {
        if (!$this->incident_end_at) {
            return null;
        }

        return $this->incident_start_at->diffInMinutes($this->incident_end_at);
    }

    public function getStatusLabel(): string
    {
        return match($this->status) {
            'draft' => 'مسودة',
            'under_review' => 'قيد المراجعة',
            'approved' => 'معتمد',
            'published' => 'منشور',
            default => $this->status,
        };
    }

    public function getStatusColor(): string
    {
        return match($this->status) {
            'draft' => 'secondary',
            'under_review' => 'warning',
            'approved' => 'success',
            'published' => 'primary',
            default => 'secondary',
        };
    }

    public function getSeverityLabel(): string
    {
        return match($this->severity) {
            'minor' => 'طفيف',
            'moderate' => 'متوسط',
            'major' => 'كبير',
            'critical' => 'حرج',
            default => $this->severity,
        };
    }

    public function getSeverityColor(): string
    {
        return match($this->severity) {
            'minor' => 'success',
            'moderate' => 'warning',
            'major' => 'danger',
            'critical' => 'dark',
            default => 'secondary',
        };
    }

    public function getScoreLabel(float $score): string
    {
        if ($score >= 4.5) return 'ممتاز';
        if ($score >= 3.5) return 'جيد جداً';
        if ($score >= 2.5) return 'جيد';
        if ($score >= 1.5) return 'مقبول';
        return 'ضعيف';
    }

    public function getScoreColor(float $score): string
    {
        if ($score >= 4.5) return 'success';
        if ($score >= 3.5) return 'info';
        if ($score >= 2.5) return 'warning';
        if ($score >= 1.5) return 'orange';
        return 'danger';
    }

    public function submitForReview(): void
    {
        $this->update([
            'status' => self::STATUS_UNDER_REVIEW,
            'overall_score' => $this->calculateOverallScore(),
        ]);
    }

    public function approve(int $approverId, ?string $comments = null): void
    {
        $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_by_id' => $approverId,
            'approved_at' => now(),
            'review_comments' => $comments,
        ]);
    }

    public function publish(): void
    {
        $this->update(['status' => self::STATUS_PUBLISHED]);
    }

    protected static function booted(): void
    {
        static::creating(function ($report) {
            if (empty($report->report_number)) {
                $date = now()->format('Ymd');
                $random = strtoupper(Str::random(4));
                $report->report_number = "AAR-{$date}-{$random}";
            }
        });
    }
}
