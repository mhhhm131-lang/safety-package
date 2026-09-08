<?php

namespace App\Modules\Emergency\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AarCorrectiveAction extends Model
{
    protected $fillable = [
        'report_id',
        'title',
        'description',
        'priority',
        'category',
        'status',
        'assigned_to_id',
        'due_date',
        'completed_date',
        'completion_notes',
        'estimated_cost',
        'actual_cost',
    ];

    protected $casts = [
        'due_date' => 'date',
        'completed_date' => 'date',
    ];

    protected $attributes = [
        'priority' => 'medium',
        'category' => 'other',
        'status' => 'open',
    ];

    // Priority constants
    const PRIORITY_LOW = 'low';
    const PRIORITY_MEDIUM = 'medium';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_CRITICAL = 'critical';

    // Category constants
    const CATEGORY_TRAINING = 'training';
    const CATEGORY_EQUIPMENT = 'equipment';
    const CATEGORY_PROCEDURE = 'procedure';
    const CATEGORY_COMMUNICATION = 'communication';
    const CATEGORY_INFRASTRUCTURE = 'infrastructure';
    const CATEGORY_STAFFING = 'staffing';
    const CATEGORY_DOCUMENTATION = 'documentation';
    const CATEGORY_OTHER = 'other';

    // Status constants
    const STATUS_OPEN = 'open';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    // Relationships
    public function report(): BelongsTo
    {
        return $this->belongsTo(AfterActionReport::class, 'report_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    // Scopes
    public function scopeOpen($query)
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_IN_PROGRESS]);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeOverdue($query)
    {
        return $query->open()
            ->whereNotNull('due_date')
            ->where('due_date', '<', now());
    }

    public function scopePriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    // Helpers
    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_IN_PROGRESS]);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_date && $this->due_date->isPast();
    }

    public function getDaysUntilDue(): ?int
    {
        if (!$this->due_date || !$this->isOpen()) {
            return null;
        }

        return now()->startOfDay()->diffInDays($this->due_date, false);
    }

    public function getPriorityLabel(): string
    {
        return match($this->priority) {
            'low' => 'منخفضة',
            'medium' => 'متوسطة',
            'high' => 'عالية',
            'critical' => 'حرجة',
            default => $this->priority,
        };
    }

    public function getPriorityColor(): string
    {
        return match($this->priority) {
            'low' => 'secondary',
            'medium' => 'warning',
            'high' => 'danger',
            'critical' => 'dark',
            default => 'secondary',
        };
    }

    public function getCategoryLabel(): string
    {
        return match($this->category) {
            'training' => 'تدريب',
            'equipment' => 'معدات',
            'procedure' => 'إجراءات',
            'communication' => 'اتصالات',
            'infrastructure' => 'بنية تحتية',
            'staffing' => 'كوادر',
            'documentation' => 'توثيق',
            'other' => 'أخرى',
            default => $this->category,
        };
    }

    public function getStatusLabel(): string
    {
        return match($this->status) {
            'open' => 'مفتوح',
            'in_progress' => 'قيد التنفيذ',
            'completed' => 'مكتمل',
            'cancelled' => 'ملغي',
            default => $this->status,
        };
    }

    public function getStatusColor(): string
    {
        return match($this->status) {
            'open' => 'secondary',
            'in_progress' => 'warning',
            'completed' => 'success',
            'cancelled' => 'danger',
            default => 'secondary',
        };
    }

    public function startWork(): void
    {
        $this->update(['status' => self::STATUS_IN_PROGRESS]);
    }

    public function complete(?string $notes = null, ?int $actualCost = null): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_date' => now(),
            'completion_notes' => $notes,
            'actual_cost' => $actualCost ?? $this->actual_cost,
        ]);
    }

    public function cancel(): void
    {
        $this->update(['status' => self::STATUS_CANCELLED]);
    }
}
