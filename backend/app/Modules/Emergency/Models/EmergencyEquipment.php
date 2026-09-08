<?php

namespace App\Modules\Emergency\Models;

use App\Core\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmergencyEquipment extends Model
{
    use \App\Core\Traits\HasAuditLog;

    protected $table = 'emergency_equipment';

    protected $fillable = [
        'building_id',
        'floor_id',
        'place_id',
        'equipment_type',
        'code',
        'brand',
        'model',
        'serial_number',
        'location_description',
        'latitude',
        'longitude',
        'install_date',
        'expiry_date',
        'last_inspection_date',
        'next_inspection_date',
        'inspection_frequency',
        'status',
        'notes',
        'qr_code',
    ];

    protected $casts = [
        'install_date' => 'date',
        'expiry_date' => 'date',
        'last_inspection_date' => 'date',
        'next_inspection_date' => 'date',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
    ];

    protected $attributes = [
        'status' => 'operational',
        'inspection_frequency' => 'monthly',
    ];

    const TYPE_FIRE_EXTINGUISHER = 'fire_extinguisher';
    const TYPE_FIRE_HOSE = 'fire_hose';
    const TYPE_SMOKE_DETECTOR = 'smoke_detector';
    const TYPE_HEAT_DETECTOR = 'heat_detector';
    const TYPE_ALARM_BELL = 'alarm_bell';
    const TYPE_EXIT_SIGN = 'exit_sign';
    const TYPE_EMERGENCY_LIGHT = 'emergency_light';
    const TYPE_FIRST_AID_KIT = 'first_aid_kit';
    const TYPE_AED = 'aed';
    const TYPE_FIRE_BLANKET = 'fire_blanket';
    const TYPE_SPILL_KIT = 'spill_kit';
    const TYPE_EYEWASH = 'eyewash';
    const TYPE_OTHER = 'other';

    const STATUS_OPERATIONAL = 'operational';
    const STATUS_NEEDS_SERVICE = 'needs_service';
    const STATUS_OUT_OF_SERVICE = 'out_of_service';
    const STATUS_EXPIRED = 'expired';
    const STATUS_MISSING = 'missing';

    // Relationships
    public function building(): BelongsTo
    {
        return $this->belongsTo(EmergencyBuilding::class, 'building_id');
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Governance\Models\Place::class);
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(BuildingFloor::class, 'floor_id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(EmergencyEquipmentInspection::class, 'equipment_id')->orderByDesc('inspected_at');
    }

    // Scopes
    public function scopeOperational($query)
    {
        return $query->where('status', self::STATUS_OPERATIONAL);
    }

    public function scopeNeedsInspection($query)
    {
        return $query->where('next_inspection_date', '<=', now());
    }

    public function scopeExpiringSoon($query, int $days = 30)
    {
        return $query->whereBetween('expiry_date', [now(), now()->addDays($days)]);
    }

    // Helpers
    public function isOperational(): bool
    {
        return $this->status === self::STATUS_OPERATIONAL;
    }

    public function isExpired(): bool
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }

    public function needsInspection(): bool
    {
        return $this->next_inspection_date && $this->next_inspection_date->isPast();
    }

    public function getTypeLabel(): string
    {
        return match($this->equipment_type) {
            'fire_extinguisher' => 'طفاية حريق',
            'fire_hose' => 'خرطوم حريق',
            'smoke_detector' => 'كاشف دخان',
            'heat_detector' => 'كاشف حرارة',
            'alarm_bell' => 'جرس إنذار',
            'exit_sign' => 'لافتة مخرج',
            'emergency_light' => 'إضاءة طوارئ',
            'first_aid_kit' => 'صندوق إسعاف',
            'aed' => 'جهاز صدمات',
            'fire_blanket' => 'بطانية حريق',
            'spill_kit' => 'معدات تسرب',
            'eyewash' => 'غسول عين',
            default => 'أخرى',
        };
    }

    public function getStatusLabel(): string
    {
        return match($this->status) {
            'operational' => 'يعمل',
            'needs_service' => 'يحتاج صيانة',
            'out_of_service' => 'خارج الخدمة',
            'expired' => 'منتهي الصلاحية',
            'missing' => 'مفقود',
            default => $this->status,
        };
    }

    public function getFrequencyLabel(): string
    {
        return match($this->inspection_frequency) {
            'monthly' => 'شهري',
            'quarterly' => 'ربع سنوي',
            'semi_annual' => 'نصف سنوي',
            'annual' => 'سنوي',
            default => $this->inspection_frequency,
        };
    }

    public function getLastInspection(): ?EmergencyEquipmentInspection
    {
        return $this->inspections()->first();
    }

    public function getTypeIcon(): string
    {
        return match($this->equipment_type) {
            'fire_extinguisher' => 'fire',
            'fire_hose' => 'droplet',
            'smoke_detector' => 'cloud',
            'heat_detector' => 'thermometer-high',
            'alarm_bell' => 'bell',
            'exit_sign' => 'box-arrow-right',
            'emergency_light' => 'lightbulb',
            'first_aid_kit' => 'plus-circle',
            'aed' => 'heart-pulse',
            'fire_blanket' => 'square',
            'spill_kit' => 'bucket',
            'eyewash' => 'eye',
            default => 'tools',
        };
    }
}
