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

    /** الأسماء المعروضة: «فرع» (region) رأس يُعرض مطوياً في شاشة الهيكل بجانب المركز الرئيسي؛ «نائب» (branch) داخل المركز */
    public const TYPE_LABELS = ['company' => 'المدير العام', 'region' => 'فرع', 'branch' => 'نائب', 'department' => 'إدارة', 'section' => 'قسم', 'team' => 'فريق'];

    /** بكلمته (٢٠٢٦-١٠-٠٨): رؤوس شاشة الهيكل = الجذور والفروع (region) — كلٌّ يُطوى على إداراته وأقسامه */
    public function isHead(): bool
    {
        return $this->parent_id === null || $this->unit_type === 'region';
    }

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

    /** خطة المعالج — الخطوة ١: أخطار السجل العام التي هذه الوحدة إدارتها المعالجة. وحدة لها أخطار لا تُحذف، تُعطَّل. */
    public function handledRisks(): HasMany
    {
        return $this->hasMany(\App\Modules\Risk\Models\Risk::class, 'handling_unit_id')->where('risk_type', 'reference');
    }

    /**
     * الهيكل مسطّحاً بترتيب الشجرة مع عمق كل وحدة — لقوائم الاختيار («الإدارة المعالجة»).
     * @return array<int, array{id:int, name:string, depth:int}>
     */
    public static function treeOptions(bool $activeOnly = true): array
    {
        $all = static::query()->when($activeOnly, fn ($q) => $q->where('is_active', true))->orderBy('order')->orderBy('id')->get(['id', 'parent_id', 'name']);
        $byParent = [];
        foreach ($all as $u) $byParent[$u->parent_id ?? 0][] = $u;
        $ids = $all->pluck('id')->all();
        $out = [];
        $walk = function (int $parent, int $depth) use (&$walk, &$out, $byParent) {
            foreach ($byParent[$parent] ?? [] as $u) {
                $out[] = ['id' => $u->id, 'name' => $u->name, 'depth' => $depth];
                $walk($u->id, $depth + 1);
            }
        };
        $walk(0, 0);
        // وحدة أبوها معطَّل أو مفقود: تظهر في الجذر حتى لا تُخفى
        foreach ($all as $u) {
            if ($u->parent_id && !in_array($u->parent_id, $ids, true) && !collect($out)->contains('id', $u->id)) {
                $out[] = ['id' => $u->id, 'name' => $u->name, 'depth' => 0];
                $walk($u->id, 1);
            }
        }
        return $out;
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
