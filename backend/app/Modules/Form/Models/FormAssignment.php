<?php

namespace App\Modules\Form\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * تكليف شخص بتعبئة نموذج، بمهلة اختيارية.
 * `source` يحفظ كيف كُلّف: فرداً، أو ضمن دور، أو وحدة، أو مكان — فيُعاد الإرسال لمن تأخر بالمعيار نفسه.
 * حالة «متأخر» يضبطها الأمر المجدول `forms:check-overdue` (في OHSMS كانت الحالة معرَّفة ولا يضبطها شيء).
 */
class FormAssignment extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_PENDING   = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_OVERDUE   = 'overdue';

    public const STATUS_LABELS = [
        self::STATUS_PENDING   => 'بانتظار التعبئة',
        self::STATUS_COMPLETED => 'مكتمل',
        self::STATUS_OVERDUE   => 'متأخر',
    ];

    public const SOURCE_LABELS = [
        'user'  => 'تكليف فردي',
        'role'  => 'بالدور',
        'unit'  => 'بالوحدة',
        'place' => 'بالمكان',
    ];

    protected $fillable = [
        'form_id', 'assigned_to_id', 'status', 'due_date', 'source', 'source_value',
        'reminded_at', 'completed_at', 'assigned_by_id', 'created_at',
    ];

    protected $casts = [
        'due_date'     => 'date',
        'reminded_at'  => 'datetime',
        'completed_at' => 'datetime',
        'created_at'   => 'datetime',
    ];

    protected $attributes = ['status' => self::STATUS_PENDING, 'source' => 'user'];

    public function form(): BelongsTo       { return $this->belongsTo(FormTemplate::class, 'form_id'); }
    public function assignedTo(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to_id'); }
    public function assignedBy(): BelongsTo { return $this->belongsTo(User::class, 'assigned_by_id'); }
    public function submission(): HasOne    { return $this->hasOne(FormSubmission::class, 'assignment_id'); }

    public function getStatusLabel(): string { return self::STATUS_LABELS[$this->status] ?? $this->status; }
    public function getSourceLabel(): string { return self::SOURCE_LABELS[$this->source] ?? $this->source; }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_OVERDUE], true);
    }

    /** تأخر ولم يُعلَّم بعد (يستعمله الأمر المجدول). */
    public function isPastDue(): bool
    {
        return $this->due_date !== null
            && $this->status === self::STATUS_PENDING
            && $this->due_date->isPast();
    }
}
