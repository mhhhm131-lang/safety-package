<?php

namespace App\Modules\Emergency\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * عضو فريق (من OHSMS). المعهد: العضو قد يكون بلا حساب (الفريق الأولي ميداني بلا عمل رقمي — ٤-٣ ب)،
 * فالاسم إلزامي والحساب اختياري، وrole_key أحد الأدوار الأربعة في ملف المكان.
 */
class EmergencyTeamMember extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'team_id', 'user_id', 'name', 'role', 'role_key', 'specialization', 'department', 'phone', 'phone_emergency',
        'is_backup', 'is_available', 'certifications', 'trained_at', 'trainer', 'training_expires_at', 'notes',
    ];

    protected $casts = [
        'is_backup' => 'boolean', 'is_available' => 'boolean', 'certifications' => 'array',
        'trained_at' => 'date', 'training_expires_at' => 'date', 'created_at' => 'datetime',
    ];

    protected $attributes = ['role' => 'member', 'is_backup' => false, 'is_available' => true];

    const ROLE_LEADER = 'leader';
    const ROLE_DEPUTY = 'deputy';
    const ROLE_MEMBER = 'member';

    /** الأدوار الأربعة في ملف المكان بترتيبها هناك (TEAM في dashboard.html). */
    public const ROLE_KEYS = ['coordinator' => 'المنسق', 'medic' => 'المسعف', 'rescuer' => 'المنقذ', 'firefighter' => 'الإطفائي'];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if (empty($m->created_at)) $m->created_at = now();
            if (empty($m->name) && $m->user_id) $m->name = User::where('id', $m->user_id)->value('name') ?? '';
        });
    }

    public function team(): BelongsTo { return $this->belongsTo(EmergencyTeam::class, 'team_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function displayName(): string
    {
        return $this->name ?: ($this->user?->name ?? '—');
    }

    public function isTrainingValid(): bool
    {
        if ($this->training_expires_at === null) return true;
        return $this->training_expires_at->isFuture();
    }

    public function isTrainingExpiringSoon(int $days = 30): bool
    {
        if ($this->training_expires_at === null) return false;
        return $this->training_expires_at->isBetween(now(), now()->addDays($days));
    }

    public function getRoleLabel(): string
    {
        return match ($this->role) { 'leader' => 'قائد', 'deputy' => 'نائب', 'member' => 'عضو', default => $this->role };
    }

    public function getRoleKeyLabel(): ?string
    {
        return $this->role_key ? (self::ROLE_KEYS[$this->role_key] ?? $this->role_key) : null;
    }

    public function getDisplayPhone(): ?string
    {
        return $this->phone ?? $this->phone_emergency;
    }
}
