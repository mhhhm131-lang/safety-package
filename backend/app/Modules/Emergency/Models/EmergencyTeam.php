<?php

namespace App\Modules\Emergency\Models;

use App\Core\Traits\HasAuditLog;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * فريق طوارئ (من OHSMS بلا tenant).
 *
 * المعهد: الفريق الأولي الرباعي (منسق/مسعف/منقذ/إطفائي) لكل مكان — ولكل إدارة في المكاتب الإدارية — يُشتق من ملف المكان
 * في اللوحة (وثيقة ipa-place) عبر TeamSync: source=place_profile وunit_key ومسار الجاهزية (ترشيح ← اعتماد ← إحالة).
 * هذه الفرق تُقرأ هنا ولا تُحرَّر (المصدر اللوحة). الفرق اليدوية (القيادة، الأمن، التحكم…) تُنشأ من هذه الشاشات.
 */
class EmergencyTeam extends Model
{
    use HasAuditLog;

    public $timestamps = false;

    protected $fillable = [
        'building_id', 'place_id', 'name', 'team_type', 'description', 'shift', 'is_active',
        'source', 'unit_key', 'organization_unit_id', 'readiness', 'synced_at',
    ];

    protected $casts = ['is_active' => 'boolean', 'created_at' => 'datetime', 'synced_at' => 'datetime'];

    protected $attributes = ['is_active' => true, 'shift' => 'all', 'source' => 'manual', 'readiness' => 'none'];

    const TYPE_INITIAL = 'initial'; // المعهد: الفريق الأولي
    const TYPE_COMMAND = 'command';
    const TYPE_FIRE_WARDEN = 'fire_warden';
    const TYPE_FIRST_AID = 'first_aid';
    const TYPE_EVACUATION = 'evacuation';
    const TYPE_SEARCH_RESCUE = 'search_rescue';
    const TYPE_COMMUNICATION = 'communication';
    const TYPE_SECURITY = 'security';

    public const TYPES = [
        'initial' => 'الفريق الأولي', 'command' => 'فريق القيادة', 'fire_warden' => 'مراقبو الحريق',
        'first_aid' => 'الإسعافات الأولية', 'evacuation' => 'فريق الإخلاء', 'search_rescue' => 'البحث والإنقاذ',
        'communication' => 'الاتصالات', 'security' => 'الأمن',
    ];

    /** مسار الجاهزية كما في اللوحة (unitState). */
    public const READINESS = [
        'none' => 'لم يُرشَّح', 'nominated' => 'مرشَّح — بانتظار الاعتماد', 'approved' => 'معتمد',
        'referred' => 'معتمد ومُحال للموارد البشرية',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $t) {
            if (empty($t->created_at)) $t->created_at = now();
        });
    }

    public function building(): BelongsTo { return $this->belongsTo(EmergencyBuilding::class, 'building_id'); }
    public function place(): BelongsTo { return $this->belongsTo(Place::class); }
    public function organizationUnit(): BelongsTo { return $this->belongsTo(OrganizationUnit::class); }
    public function members(): HasMany { return $this->hasMany(EmergencyTeamMember::class, 'team_id')->orderBy('id'); }

    public function scopeActive($query) { return $query->where('is_active', true); }
    public function scopeOfType($query, string $type) { return $query->where('team_type', $type); }
    public function scopeForPlace($query, ?int $placeId) { return $placeId ? $query->where('place_id', $placeId) : $query; }

    public function isDerived(): bool { return $this->source === 'place_profile'; }

    public function getLeader(): ?EmergencyTeamMember
    {
        return $this->members()->where('role', 'leader')->first();
    }

    public function getAvailableMembers()
    {
        return $this->members()->where('is_available', true)->get();
    }

    public function getMemberCount(): int
    {
        return $this->members()->count();
    }

    public function getTypeLabel(): string { return self::TYPES[$this->team_type] ?? $this->team_type; }
    public function getReadinessLabel(): string { return self::READINESS[$this->readiness] ?? $this->readiness; }

    public function getShiftLabel(): string
    {
        return match ($this->shift) {
            'morning' => 'صباحي', 'evening' => 'مسائي', 'night' => 'ليلي', 'all' => 'جميع الفترات', default => $this->shift,
        };
    }

    public function getTypeIcon(): string
    {
        return match ($this->team_type) {
            'initial' => 'people-fill', 'command' => 'megaphone', 'fire_warden' => 'fire', 'first_aid' => 'heart-pulse',
            'evacuation' => 'door-open', 'search_rescue' => 'search', 'communication' => 'broadcast', 'security' => 'shield',
            default => 'people',
        };
    }

    public function getTypeColor(): string
    {
        return match ($this->team_type) {
            'initial' => 'success', 'command' => 'primary', 'fire_warden' => 'danger', 'first_aid' => 'success',
            'evacuation' => 'warning', 'search_rescue' => 'info', 'communication' => 'secondary', 'security' => 'dark',
            default => 'secondary',
        };
    }
}
