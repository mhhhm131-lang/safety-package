<?php

namespace App\Modules\Emergency\Models;

use App\Core\Traits\HasAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * المبنى (من OHSMS بلا tenant/project). المعهد مبنى واحد تُبذره EmergencySeeder؛ الشاشات تبقى عامة كما في OHSMS.
 */
class EmergencyBuilding extends Model
{
    use HasAuditLog;

    protected $fillable = [
        'code', 'name', 'name_en', 'address', 'building_type', 'floors_count', 'basement_floors', 'total_capacity',
        'current_occupants', 'latitude', 'longitude', 'floor_plan_file', 'status', 'risk_level', 'last_audit_date',
        'next_audit_date', 'emergency_status', 'created_by_id',
    ];

    protected $casts = [
        'last_audit_date' => 'date', 'next_audit_date' => 'date', 'latitude' => 'decimal:8', 'longitude' => 'decimal:8',
    ];

    protected $attributes = [
        'status' => 'active', 'emergency_status' => 'normal', 'risk_level' => 'medium', 'floors_count' => 1,
        'basement_floors' => 0, 'current_occupants' => 0, 'building_type' => 'government',
    ];

    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';
    const STATUS_MAINTENANCE = 'under_maintenance';

    const EMERGENCY_NORMAL = 'normal';
    const EMERGENCY_ALERT = 'alert';
    const EMERGENCY_EVACUATING = 'evacuating';
    const EMERGENCY_ALL_CLEAR = 'all_clear';

    public const TYPES = [
        'office' => 'مكتبي', 'industrial' => 'صناعي', 'educational' => 'تعليمي', 'medical' => 'طبي',
        'residential' => 'سكني', 'commercial' => 'تجاري', 'government' => 'حكومي', 'other' => 'أخرى',
    ];

    /** المبنى الأول (المعهد مبنى واحد). */
    public static function main(): ?self
    {
        return static::orderBy('id')->first();
    }

    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_id'); }
    public function floors(): HasMany { return $this->hasMany(BuildingFloor::class, 'building_id')->orderBy('floor_number'); }
    public function exits(): HasMany { return $this->hasMany(BuildingExit::class, 'building_id'); }
    public function assemblyPoints(): HasMany { return $this->hasMany(AssemblyPoint::class, 'building_id'); }
    public function teams(): HasMany { return $this->hasMany(EmergencyTeam::class, 'building_id'); }
    public function contacts(): HasMany { return $this->hasMany(EmergencyContact::class, 'building_id'); }
    public function incidents(): HasMany { return $this->hasMany(EmergencyIncident::class, 'building_id'); }
    public function equipment(): HasMany { return $this->hasMany(EmergencyEquipment::class, 'building_id'); }
    public function drills(): HasMany { return $this->hasMany(EvacuationDrill::class, 'building_id'); }
    public function visitors(): HasMany { return $this->hasMany(EmergencyVisitor::class, 'building_id'); }
    public function lockdowns(): HasMany { return $this->hasMany(Lockdown::class, 'building_id'); }

    public function scopeActive($query) { return $query->where('status', self::STATUS_ACTIVE); }
    public function scopeInEmergency($query) { return $query->whereIn('emergency_status', [self::EMERGENCY_ALERT, self::EMERGENCY_EVACUATING]); }

    public function isInEmergency(): bool
    {
        return in_array($this->emergency_status, [self::EMERGENCY_ALERT, self::EMERGENCY_EVACUATING]);
    }

    public function getActiveIncident(): ?EmergencyIncident
    {
        return $this->incidents()->whereIn('status', ['active', 'contained'])->latest('triggered_at')->first();
    }

    public function activeLockdown(): ?Lockdown
    {
        return $this->lockdowns()->whereIn('state', ['active', 'partial'])->latest('initiated_at')->first();
    }

    public function getPrimaryAssemblyPoint(): ?AssemblyPoint
    {
        return $this->assemblyPoints()->where('is_primary', true)->first();
    }

    public function getTotalFloors(): int
    {
        return $this->floors_count + $this->basement_floors;
    }

    public function getTypeLabel(): string
    {
        return self::TYPES[$this->building_type] ?? 'أخرى';
    }

    public function getRiskLevelLabel(): string
    {
        return match ($this->risk_level) {
            'low' => 'منخفض', 'medium' => 'متوسط', 'high' => 'مرتفع', 'critical' => 'حرج', default => $this->risk_level,
        };
    }

    public function getEmergencyStatusLabel(): string
    {
        return match ($this->emergency_status) {
            'normal' => 'طبيعي', 'alert' => 'تنبيه', 'evacuating' => 'جاري الإخلاء', 'all_clear' => 'انتهى الخطر',
            default => $this->emergency_status,
        };
    }
}
