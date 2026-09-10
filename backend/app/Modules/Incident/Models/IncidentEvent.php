<?php

namespace App\Modules\Incident\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** الخط الزمني للبلاغ (لا يُمحى): الفعل، من حالة إلى حالة، الملاحظة، الفاعل، الوقت. */
class IncidentEvent extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['incident_id', 'action', 'from_status', 'to_status', 'note', 'actor_id'];

    protected $casts = ['created_at' => 'datetime'];

    public const ACTION_LABELS = [
        'create' => 'أُرسل البلاغ',
        'created' => 'أُرسل البلاغ',
        'receive' => 'وصل مركز السلامة',
        'received' => 'وصل مركز السلامة',
        'refer' => 'أُحيل إلى منسق السلامة',
        'ref_receive' => 'استلمه المنسق',
        'forward' => 'حُوّل إلى الفني',
        'forwarded' => 'حُوّل إلى الفني',
        'field_receive' => 'استلمه الفني',
        'assign' => 'تعيين',
        'assigned' => 'تعيين',
        'begin_work' => 'بدأ الفني المعالجة',
        'inspection_linked' => 'فُتح عليه بلاغ فحص في نموذج المكان',
        'emergency_triggered' => 'فُعّلت حالة طارئة بناءً على البلاغ',
        'resolve' => 'عولج',
        'resolved' => 'عولج',
        'request_closure' => 'طُلبت موافقة المبلّغ على الإغلاق',
        'reporter_approved' => 'وافق المبلّغ على الإغلاق',
        'reject_closure' => 'رُفض الإغلاق وأُعيد',
        'verify' => 'تحقق ميداني من شخص غير المنفّذ',
        'close' => 'أُغلق',
        'closed' => 'أُغلق',
        'close_with_note' => 'أُغلق بملاحظة من المركز (لا يحتاج فنياً)',
        'reopened' => 'أُعيد فتحه',
        'escalate_to_coord' => 'صُعّد إلى المنسق',
        'escalate_to_manager' => 'صُعّد إلى لجنة السلامة',
        'escalated' => 'تصعيد',
        'resolve_escalation' => 'تولّى المعالجة بعد التصعيد',
        'out_of_scope' => 'خارج النطاق',
        'note' => 'ملاحظة',
        'overdue' => 'تجاوز المهلة',
        'deadline_reset' => 'أُعيدت المهلة',
        'status_changed' => 'تغيير حالة',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function getActionLabelAttribute(): string
    {
        return self::ACTION_LABELS[$this->action] ?? $this->action;
    }
}
