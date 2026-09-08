<?php

namespace App\Modules\Governance\Models;

use App\Core\Traits\HasAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * الهيكل التنظيمي (منقول من OHSMS بلا tenant ولا سياق المشاريع).
 * الشجرة: إدارة عامة ← إدارة ← قسم. `code` هو المعرّف الذي تعرفه اللوحة (ipa-depts).
 */
class OrganizationUnit extends Model
{
    use HasAuditLog;

    public const TYPES = ['company', 'region', 'branch', 'department', 'section', 'team'];

    protected $fillable = ['code', 'parent_id', 'name', 'name_en', 'unit_type', 'place_id', 'manager_id', 'manager_name', 'order', 'is_active'];

    protected $casts = ['order' => 'integer', 'is_active' => 'boolean'];

    protected $attributes = ['unit_type' => 'department', 'order' => 0, 'is_active' => true];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(UserProfile::class);
    }

    /** المسار الكامل: «نائب … > الإدارة العامة … > القسم» */
    public function getFullPath(): string
    {
        $segments = collect([$this->name]);
        $current = $this;
        while ($current->parent) {
            $current = $current->parent;
            $segments->prepend($current->name);
        }
        return $segments->implode(' > ');
    }

    /** الوحدة وكل ما تحتها — استعلام واحد لا تكراري (إصلاح N+1 في OHSMS). */
    public function descendantIds(): array
    {
        $all = static::query()->get(['id', 'parent_id']);
        $byParent = [];
        foreach ($all as $u) {
            $byParent[$u->parent_id ?? 0][] = $u->id;
        }
        $ids = [$this->id];
        $stack = [$this->id];
        while ($stack) {
            $pid = array_pop($stack);
            foreach ($byParent[$pid] ?? [] as $cid) {
                $ids[] = $cid;
                $stack[] = $cid;
            }
        }
        return $ids;
    }

    public static function descendantIdsOf(?int $unitId): array
    {
        if (!$unitId) return [];
        $unit = static::find($unitId);
        return $unit ? $unit->descendantIds() : [];
    }
}
