<?php

namespace App\Modules\Permit\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سجل زمني للتصريح — يُضاف ولا يُعدَّل. الكتابة عبر PermitService وحده. */
class PermitEvent extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_LABELS = [
        'created'               => 'إنشاء التصريح',
        'status_changed'        => 'تغيير الحالة',
        'requirement_completed' => 'إتمام بند',
        'requirement_failed'    => 'بند لم يجتز',
        'requirement_reopened'  => 'إعادة فتح بند',
        'worker_assigned'       => 'إسناد عامل',
        'worker_removed'        => 'إزالة عامل',
        'risks_attached'        => 'ربط مخاطر',
        'deviation_recorded'    => 'تسجيل انحراف',
        'deviation_resolved'    => 'إغلاق انحراف',
        'attachment_uploaded'   => 'رفع مرفق',
        'evaluated'             => 'تقييم ما بعد الإغلاق',
        'cascade_failed'        => 'تعذّر التتالي',
        'note'                  => 'ملاحظة',
    ];

    protected $fillable = [
        'permit_id', 'event_type', 'from_status', 'to_status', 'changes', 'notes',
        'performed_by_id', 'signature_ip', 'signature_lat', 'signature_lng', 'created_at',
    ];

    protected $casts = [
        'changes'    => 'array',
        'created_at' => 'datetime',
    ];

    public function permit(): BelongsTo      { return $this->belongsTo(Permit::class); }
    public function performedBy(): BelongsTo { return $this->belongsTo(User::class, 'performed_by_id'); }

    public function getTypeLabel(): string
    {
        return self::TYPE_LABELS[$this->event_type] ?? $this->event_type;
    }
}
