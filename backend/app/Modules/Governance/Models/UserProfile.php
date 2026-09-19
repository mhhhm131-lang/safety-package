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

    protected $fillable = ['user_id', 'role', 'organization_unit_id', 'place_id', 'is_active', 'building_id', 'job_title',
        'pending_since', 'pending_by_id', 'pending_note', 'approved_by_id', 'approved_at', 'return_note'];

    protected $casts = ['is_active' => 'boolean', 'pending_since' => 'datetime', 'approved_at' => 'datetime'];

    // ── ٢٠-٤-ب (قرار ٥٢): قاعدة الاعتماد — ما يسجله غير مسؤول السلامة لا يعمل حتى يعتمده ──

    public function isPending(): bool
    {
        return $this->pending_since !== null;
    }

    public function pendingBy(): BelongsTo { return $this->belongsTo(User::class, 'pending_by_id'); }
    public function approvedBy(): BelongsTo { return $this->belongsTo(User::class, 'approved_by_id'); }

    /** بانتظار الاعتماد: الحساب معطَّل حتى «اعتمد» */
    public function markPending(User $by, string $note): void
    {
        $this->forceFill(['is_active' => false, 'pending_since' => now(), 'pending_by_id' => $by->id, 'pending_note' => mb_substr($note, 0, 200), 'return_note' => null])->save();
    }

    /** «اعتمد»: يعمل الحساب، وتُسجَّل الموافقة باسمه وتاريخها */
    public function approve(User $by): void
    {
        $this->forceFill(['is_active' => true, 'pending_since' => null, 'pending_note' => null, 'return_note' => null, 'approved_by_id' => $by->id, 'approved_at' => now()])->save();
    }

    /** «أعِده»: يبقى معطَّلاً، ويُحفظ السبب لمن سجّله */
    public function returnBack(?string $note): void
    {
        $this->forceFill(['is_active' => false, 'pending_since' => null, 'return_note' => $note !== null && $note !== '' ? mb_substr($note, 0, 200) : 'أُعيد بلا سبب مكتوب'])->save();
    }

    /** ٢٠-١ (قرار ٥١): كل حساب يتبع مبنى؛ بلا تحديد = الرئيسي (الملز) */
    protected static function booted(): void
    {
        static::creating(function (UserProfile $p) { $p->building_id ??= \App\Modules\Emergency\Models\EmergencyBuilding::mainOrCreate()->id; });
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Emergency\Models\EmergencyBuilding::class, 'building_id');
    }

    /** ٢٠-٢ (قرار ٥١): التغطية — الأماكن التي يخدمها صاحب الحساب (فني، أمن، طبيب)؛ للتوجيه لا لـ«مكاني» */
    public function coverage(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Place::class, 'place_coverages')->withTimestamps()->orderBy('sort');
    }

    public function covers(Place $place): bool
    {
        return $this->coverage()->where('places.id', $place->id)->exists();
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
