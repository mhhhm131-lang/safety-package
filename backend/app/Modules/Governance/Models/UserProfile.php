<?php

namespace App\Modules\Governance\Models;

use App\Core\Permissions\PermissionRegistry;
use App\Core\Traits\HasAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ملف المستخدم: الدور (مفتاح من PermissionRegistry) والوحدة التنظيمية والمكان والتفعيل.
 * كما في OHSMS: الدور يُحسم من هنا نصاً. لا جداول roles/user_roles (غير مستخدمة عندهم).
 */
class UserProfile extends Model
{
    use HasAuditLog;

    protected $fillable = ['user_id', 'role', 'organization_unit_id', 'place_id', 'is_active', 'building_id'];

    protected $casts = ['is_active' => 'boolean'];

    /** ٢٠-١ (قرار ٥١): كل حساب يتبع مبنى؛ بلا تحديد = الرئيسي (الملز) */
    protected static function booted(): void
    {
        static::creating(function (UserProfile $p) { $p->building_id ??= \App\Modules\Emergency\Models\EmergencyBuilding::mainOrCreate()->id; });
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Emergency\Models\EmergencyBuilding::class, 'building_id');
    }

    /** ٢٠-١: مبنى الشخص = مبنى حسابه، وإلا مبنى مكانه */
    public function myBuilding(): ?\App\Modules\Emergency\Models\EmergencyBuilding
    {
        return $this->building ?? $this->myPlace()?->building;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    /** ١٩-٦ (قرار ٤٩): مكان الشخص = مكان حسابه، وإلا مكان إدارته؛ null لمن لا مكان له (مسؤول السلامة، المناوب، القيادات). */
    public function myPlace(): ?Place
    {
        return $this->place ?? $this->organizationUnit?->place;
    }

    public function roleName(): string
    {
        return PermissionRegistry::getRoleDisplayName($this->role);
    }

    public function uiRole(): ?string
    {
        return PermissionRegistry::uiRole($this->role);
    }
}
