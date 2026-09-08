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

    protected $fillable = ['user_id', 'role', 'organization_unit_id', 'place_id', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

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

    public function roleName(): string
    {
        return PermissionRegistry::getRoleDisplayName($this->role);
    }

    public function uiRole(): ?string
    {
        return PermissionRegistry::uiRole($this->role);
    }
}
