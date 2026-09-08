<?php

namespace App\Modules\Emergency\Models;

use App\Core\Traits\HasAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * الإغلاق الأمني — جدول بدل الكاش (الإصلاح المقرر في BACKEND.md ٥-٣؛ LockdownService في OHSMS كان يحفظ حالته في Cache ٢٤ ساعة).
 * results: ما فُعل فعلاً في الأنظمة (أبواب/مصاعد/شاشات) — deferred حتى المرحلة ٥ (إنترنت الأشياء).
 */
class Lockdown extends Model
{
    use HasAuditLog;

    protected $fillable = [
        'building_id', 'incident_id', 'level', 'state', 'zones', 'options', 'results', 'reason',
        'initiated_by_id', 'initiated_at', 'lifted_by_id', 'lifted_at', 'lift_reason',
    ];

    protected $casts = [
        'zones' => 'array', 'options' => 'array', 'results' => 'array',
        'initiated_at' => 'datetime', 'lifted_at' => 'datetime',
    ];

    protected $attributes = ['state' => 'active'];

    public const LEVELS = [
        'soft' => 'تأمين المحيط', 'modified' => 'إغلاق جزئي', 'full' => 'إغلاق كامل', 'shelter' => 'احتماء في المكان', 'zone' => 'إغلاق منطقة',
    ];

    public const STATES = ['active' => 'ساري', 'partial' => 'جزئي', 'lifted' => 'مرفوع'];

    public function auditLabel(): string
    {
        return ($this->building?->name ?? '').' '.$this->getLevelLabel();
    }

    public function building(): BelongsTo { return $this->belongsTo(EmergencyBuilding::class, 'building_id'); }
    public function incident(): BelongsTo { return $this->belongsTo(EmergencyIncident::class, 'incident_id'); }
    public function initiatedBy(): BelongsTo { return $this->belongsTo(User::class, 'initiated_by_id'); }
    public function liftedBy(): BelongsTo { return $this->belongsTo(User::class, 'lifted_by_id'); }

    public function isActive(): bool { return in_array($this->state, ['active', 'partial'], true); }
    public function getLevelLabel(): string { return self::LEVELS[$this->level] ?? $this->level; }
    public function getStateLabel(): string { return self::STATES[$this->state] ?? $this->state; }
}
