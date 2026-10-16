<?php

namespace App\Modules\Emergency\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * بطاقة دور في مبناها (المرحلة ٣٠-٣، قرار ٨٩): الرقم والفئة والملف للصنف؛ الاسم والوصف والمهام والشاغل وهاتفه كلام المبنى.
 * `source` ما قاله الملف آخر قراءة؛ `edited_at` غير فارغ = عدّله المبنى فلا تمسّه المزامنة.
 */
class BuildingRoleCard extends Model
{
    protected $fillable = ['building_id', 'card_no', 'category', 'name', 'description', 'reports_to', 'basis',
        'tasks_proactive', 'tasks_operational', 'tasks_response', 'holder_name', 'holder_phone', 'source', 'not_applicable',
        'edited_by_id', 'edited_by_name', 'edited_at', 'source_path', 'fingerprint', 'synced_at'];

    protected $casts = ['source' => 'array', 'not_applicable' => 'boolean', 'edited_at' => 'datetime', 'synced_at' => 'datetime', 'card_no' => 'integer'];

    /** خانات كلام المبنى بعناوينها. */
    /** بكلمته «موافق» (٢٠٢٦-١٠-١٦): «اسم الدور» (ما هذه البطاقة) · «الوصف» (أين يُمارَس أو ما حدّه) · «المكلّف» (من يقوم به في هذا المبنى اليوم) — «الشاغل» محجوزة لشاغل المبنى الذي يبلّغ */
    public const WORD_LABELS = [
        'name' => 'اسم الدور', 'description' => 'الوصف',
        'tasks_proactive' => 'المهام الاستباقية', 'tasks_operational' => 'المهام التشغيلية', 'tasks_response' => 'مهام الاستجابة',
        'holder_name' => 'المكلّف', 'holder_phone' => 'الهاتف',
    ];

    public const WORD_FIELDS = ['name', 'description', 'tasks_proactive', 'tasks_operational', 'tasks_response', 'holder_name', 'holder_phone'];

    /** قوائم المهام الثلاث كما في الملف. */
    public const TASK_FIELDS = ['tasks_proactive' => '◆ استباقية', 'tasks_operational' => '● تشغيلية', 'tasks_response' => '▲ استجابة'];

    /** بطاقات مبنى محمّلة مرة في الطلب الواحد: [building_id => Collection<card_no, self>] */
    private static array $cache = [];

    public function building(): BelongsTo { return $this->belongsTo(EmergencyBuilding::class, 'building_id'); }

    public function editedBy(): BelongsTo { return $this->belongsTo(User::class, 'edited_by_id'); }

    /** بطاقات المبنى بأرقامها (من الذاكرة بعد أول تحميل). */
    public static function forBuilding(int $buildingId): Collection
    {
        return self::$cache[$buildingId] ??= static::where('building_id', $buildingId)->get()->keyBy('card_no');
    }

    public static function flush(): void { self::$cache = []; }

    /** سطور حقل مهام: سطر لكل مهمة بلا فراغات. */
    public function lines(string $field): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($this->{$field} ?? ''))), fn ($l) => $l !== ''));
    }

    public function isEdited(): bool { return $this->edited_at !== null; }

    public function changedFields(): array
    {
        $out = [];
        foreach (self::WORD_FIELDS as $f) {
            if (self::norm($this->{$f}) !== self::norm($this->source[$f] ?? null)) $out[] = $f;
        }
        return $out;
    }

    public function changedLabels(): array
    {
        $labels = array_map(fn ($f) => self::WORD_LABELS[$f], $this->changedFields());
        if ($this->not_applicable) $labels[] = 'لا ينطبق هنا';
        return $labels;
    }

    /** @return array<string, array{0:?string,1:?string}> ما تغيّر فعلاً */
    public function applyWords(array $in, bool $notApplicable, User $by): array
    {
        $diff = [];
        foreach (self::WORD_FIELDS as $f) {
            if (!array_key_exists($f, $in)) continue;
            $new = self::norm($in[$f]);
            if ($f === 'name' && $new === null) continue; // الاسم لا يكون فارغاً
            if ($new !== self::norm($this->{$f})) $diff[$f] = [$this->{$f}, $new];
            $this->{$f} = $new;
        }
        if ($notApplicable !== (bool) $this->not_applicable) $diff['not_applicable'] = [$this->not_applicable ? 'نعم' : 'لا', $notApplicable ? 'نعم' : 'لا'];
        $this->not_applicable = $notApplicable;
        if ($diff) $this->markEdited($by);
        return $diff;
    }

    public function restoreSource(): void
    {
        foreach (self::WORD_FIELDS as $f) $this->{$f} = $this->source[$f] ?? null;
        $this->not_applicable = false;
        $this->markEdited(null);
    }

    public function markEdited(?User $by): void
    {
        if ($this->changedFields() || $this->not_applicable) {
            $this->edited_by_id = $by?->id ?? $this->edited_by_id;
            $this->edited_by_name = $by?->name ?? $this->edited_by_name;
            $this->edited_at = now();
        } else {
            $this->edited_by_id = null;
            $this->edited_by_name = null;
            $this->edited_at = null;
        }
    }

    /** نص مطبَّع: كل سطر بلا فراغات زائدة، السطور الفارغة تُحذف، والفارغ null. */
    public static function norm($v): ?string
    {
        $lines = array_filter(array_map(fn ($l) => trim(preg_replace('/[ \t]+/u', ' ', $l)), preg_split('/\r\n|\r|\n/', (string) ($v ?? ''))), fn ($l) => $l !== '');
        $t = implode("\n", $lines);
        return $t === '' ? null : $t;
    }
}
