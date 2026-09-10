<?php

namespace App\Modules\Emergency\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** السجل الزمني للحالة الطارئة (من OHSMS). أنواع أُضيفت للمعهد: team_arrived, escalation, cancelled, lockdown, ics, message, panic, visitor. */
class EmergencyEventLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['incident_id', 'event_type', 'severity', 'message', 'data', 'user_id', 'logged_at'];

    protected $casts = ['data' => 'array', 'logged_at' => 'datetime'];

    protected $attributes = ['severity' => 'info'];

    const TYPE_ALARM_TRIGGERED = 'alarm_triggered';
    const TYPE_FLOOR_CLEARED = 'floor_cleared';
    const TYPE_PERSON_SAFE = 'person_safe';
    const TYPE_PERSON_MISSING = 'person_missing';
    const TYPE_PERSON_FOUND = 'person_found';
    const TYPE_HELP_REQUESTED = 'help_requested';
    const TYPE_TEAM_NOTIFIED = 'team_notified';
    const TYPE_TEAM_ARRIVED = 'team_arrived';
    const TYPE_EXTERNAL_NOTIFIED = 'external_notified';
    const TYPE_CONTAINED = 'contained';
    const TYPE_ALL_CLEAR = 'all_clear';
    const TYPE_NOTE = 'note';
    const TYPE_STATUS_CHANGE = 'status_change';
    const TYPE_ESCALATION = 'escalation';
    const TYPE_CANCELLED = 'cancelled';
    const TYPE_LOCKDOWN = 'lockdown';
    const TYPE_ICS = 'ics';
    const TYPE_MESSAGE = 'message';
    const TYPE_PANIC = 'panic';
    const TYPE_VISITOR = 'visitor';
    const TYPE_PLAN_STEP = 'plan_step'; // المرحلة ١٠-٢: خطوات خطة الاستجابة (نسخ، تم، تخطٍّ، تجاوز)

    public const LABELS = [
        'alarm_triggered' => 'تشغيل الإنذار', 'floor_cleared' => 'تم إخلاء الدور', 'person_safe' => 'شخص آمن',
        'person_missing' => 'شخص مفقود', 'person_found' => 'تم العثور على شخص', 'help_requested' => 'طلب مساعدة',
        'team_notified' => 'تنبيه الفريق', 'team_arrived' => 'وصول عضو فريق', 'external_notified' => 'إبلاغ جهة خارجية',
        'contained' => 'تمت السيطرة', 'all_clear' => 'انتهى الخطر', 'note' => 'ملاحظة', 'status_change' => 'تغيير الحالة',
        'escalation' => 'تصعيد', 'cancelled' => 'إلغاء', 'lockdown' => 'إغلاق أمني', 'ics' => 'قيادة الحادث',
        'message' => 'رسالة جماعية', 'panic' => 'تنبيه ذعر', 'visitor' => 'زائر', 'plan_step' => 'خطوة الخطة',
    ];

    public function incident(): BelongsTo { return $this->belongsTo(EmergencyIncident::class, 'incident_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function getTypeLabel(): string { return self::LABELS[$this->event_type] ?? $this->event_type; }

    public function getSeverityLabel(): string
    {
        return match ($this->severity) { 'info' => 'معلومة', 'warning' => 'تحذير', 'critical' => 'حرج', default => $this->severity };
    }

    public static function log(EmergencyIncident $incident, string $type, string $message, array $data = [], string $severity = 'info', ?int $userId = null): self
    {
        return self::create([
            'incident_id' => $incident->id,
            'event_type' => $type,
            'severity' => $severity,
            'message' => $message,
            'data' => $data ?: null,
            'user_id' => $userId ?? auth()->id(),
            'logged_at' => now(),
        ]);
    }
}
