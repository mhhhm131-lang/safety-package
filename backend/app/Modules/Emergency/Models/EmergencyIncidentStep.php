<?php

namespace App\Modules\Emergency\Models;

use App\Models\User;
use App\Modules\Emergency\Support\RoleCards;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** خطوة من خطة الاستجابة داخل حالة طارئة حية (المرحلة ١٠-٢). */
class EmergencyIncidentStep extends Model
{
    protected $fillable = ['incident_id', 'plan_step_id', 'path_key', 'path_title', 'sort', 'label', 'title', 'when_text',
        'window_from_sec', 'window_to_sec', 'is_conditional', 'who_text', 'where_text', 'how_text', 'role_cards', 'role_card_no',
        'status', 'due_at', 'done_at', 'done_by_id', 'done_by_name', 'delta_sec', 'auto_source', 'overdue_alerted_at', 'note'];

    protected $casts = ['role_cards' => 'array', 'is_conditional' => 'boolean', 'due_at' => 'datetime', 'done_at' => 'datetime',
        'overdue_alerted_at' => 'datetime', 'sort' => 'integer', 'window_from_sec' => 'integer', 'window_to_sec' => 'integer',
        'delta_sec' => 'integer', 'role_card_no' => 'integer'];

    public const STATUS_PENDING = 'pending';
    public const STATUS_DONE = 'done';
    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_LABELS = ['pending' => 'معلّقة', 'done' => 'تمت', 'skipped' => 'تُخطّيت'];

    public const AUTO_LABELS = ['team_arrived' => 'وصول عضو الفريق الأولي', 'contained' => 'تسجيل السيطرة', 'ended' => 'انتهاء الخطر'];

    public function incident(): BelongsTo { return $this->belongsTo(EmergencyIncident::class, 'incident_id'); }
    public function planStep(): BelongsTo { return $this->belongsTo(ResponsePlanStep::class, 'plan_step_id'); }
    public function doneBy(): BelongsTo { return $this->belongsTo(User::class, 'done_by_id'); }

    public function scopePending($q) { return $q->where('status', self::STATUS_PENDING); }

    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }
    public function isDone(): bool { return $this->status === self::STATUS_DONE; }

    /** متجاوزة: معلّقة ولها موعد وقد مضى. */
    public function isOverdue(): bool
    {
        return $this->isPending() && $this->due_at !== null && $this->due_at->isPast();
    }

    public function getStatusLabel(): string { return self::STATUS_LABELS[$this->status] ?? $this->status; }

    public function primaryCard(): ?array { return $this->role_card_no ? RoleCards::get($this->role_card_no) : null; }

    public function cards(): array
    {
        $out = [];
        foreach ($this->role_cards ?? [] as $n) if ($c = RoleCards::get((int) $n)) $out[(int) $n] = $c;
        return $out;
    }

    /** الفارق مقروءاً: «تأخر ٣٧ ث» / «قبل الحد بـ ٢ ث» / «—». */
    public function deltaLabel(): string
    {
        if ($this->delta_sec === null) return '—';
        if ($this->delta_sec > 0) return 'تأخر '.self::secs($this->delta_sec);
        return 'ضمن النافذة (قبل الحد بـ '.self::secs(-$this->delta_sec).')';
    }

    public static function secs(int $s): string
    {
        if ($s >= 3600) return sprintf('%d:%02d:%02d س', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
        if ($s >= 60) return sprintf('%d:%02d د', intdiv($s, 60), $s % 60);
        return $s.' ث';
    }

    /** الثواني من التفعيل إلى الإتمام. */
    public function elapsedSec(): ?int
    {
        if (!$this->done_at) return null;
        return (int) abs($this->done_at->diffInSeconds($this->incident->triggered_at));
    }
}
