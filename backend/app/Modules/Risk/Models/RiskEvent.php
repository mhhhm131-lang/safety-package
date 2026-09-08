<?php

namespace App\Modules\Risk\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit log for risk lifecycle + field-level changes.
 *
 * Legacy columns (action, note, actor_id) preserved for backward
 * compatibility. The 2026_04_17 migration added `tenant_id` for
 * multi-tenancy and a `changes` JSON diff so we can see *what*
 * changed on a risk, not just that it changed.
 */
class RiskEvent extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'risk_id',
        'action',
        'from_status',
        'to_status',
        'changes',
        'note',
        'actor_id',
    ];

    protected $casts = [
        'changes' => 'array',
    ];

    // Relationships

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function getActionLabelAttribute(): string
    {
        return [
            'created' => 'تم الإنشاء',
            'submitted' => 'تم التقديم',
            'approved' => 'تم الاعتماد',
            'rejected' => 'تم الرفض',
            'activated' => 'تم التفعيل',
            'mitigated' => 'تم التخفيف',
            'closed' => 'تم الإغلاق',
            'status_changed' => 'تغيير حالة',
            'note' => 'ملاحظة',
            'assigned' => 'تم التعيين',
            'copied_from_master' => 'نسخ من كتاب المخاطر',
        ][$this->action] ?? $this->action;
    }
}
