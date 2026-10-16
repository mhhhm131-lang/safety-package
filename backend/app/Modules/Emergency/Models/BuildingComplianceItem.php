<?php

namespace App\Modules\Emergency\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * صف في جدول المقارنة لمبنى (المرحلة ٣١-١، قرار ٩٠): مادة من اللائحة أو بند تحت المواد ١٢ و١٣ و١٤.
 * الشكل للصنف (العنوان، نص اللائحة، ما أعدّه مسؤول السلامة)، والكلام للمبنى (الحالة، الوضع الحالي، الإجراء، المكلّف، الموعد، الملاحظات).
 * المادة التي لها بنود لا تُقيَّم بنفسها بل تُقرأ من بنودها. الجاهزية = المستوفى ÷ (القابل للتقييم − لا ينطبق هنا).
 */
class BuildingComplianceItem extends Model
{
    protected $fillable = ['building_id', 'key', 'article_no', 'item_no', 'sort', 'title', 'requirement', 'prepared', 'status',
        'current_state', 'action', 'holder_name', 'due_date', 'notes', 'source', 'edited_by_id', 'edited_by_name', 'edited_at',
        'source_path', 'fingerprint', 'synced_at'];

    protected $casts = ['source' => 'array', 'due_date' => 'date', 'edited_at' => 'datetime', 'synced_at' => 'datetime',
        'article_no' => 'integer', 'item_no' => 'integer', 'sort' => 'integer'];

    public const UNRATED = 'unrated';

    /** الحالات الخمس بعناوينها — «لم يُقيَّم» يُعدّ غير مستوفى في الجاهزية ويُكتب عدده. */
    public const STATUSES = ['unrated' => 'لم يُقيَّم', 'met' => 'مستوفى', 'in_progress' => 'قيد الإعداد', 'not_met' => 'غير مستوفى', 'na' => 'لا ينطبق هنا'];

    public const STATUS_CLASS = ['unrated' => 'text-bg-light border text-muted', 'met' => 'st-ok', 'in_progress' => 'st-wait', 'not_met' => 'text-bg-danger', 'na' => 'text-bg-secondary'];

    public const WORD_LABELS = ['status' => 'الحالة', 'current_state' => 'الوضع الحالي', 'action' => 'الإجراء', 'holder_name' => 'المكلّف', 'due_date' => 'الموعد', 'notes' => 'ملاحظات'];

    public const WORD_FIELDS = ['status', 'current_state', 'action', 'holder_name', 'due_date', 'notes'];

    /** صفوف مبنى محمّلة مرة في الطلب: [building_id => Collection<key, self>] */
    private static array $cache = [];

    public function building(): BelongsTo { return $this->belongsTo(EmergencyBuilding::class, 'building_id'); }

    public function editedBy(): BelongsTo { return $this->belongsTo(User::class, 'edited_by_id'); }

    public static function forBuilding(int $buildingId): Collection
    {
        return self::$cache[$buildingId] ??= static::where('building_id', $buildingId)->orderBy('sort')->get()->keyBy('key');
    }

    public static function flush(): void { self::$cache = []; }

    public function isItem(): bool { return $this->item_no !== null; }

    /** بنود هذه المادة في مبناها (فارغة للبند وللمادة بلا بنود). */
    public function items(): Collection
    {
        if ($this->isItem()) return collect();
        return self::forBuilding($this->building_id)->filter(fn ($r) => $r->article_no === $this->article_no && $r->isItem())->values();
    }

    public function hasItems(): bool { return !$this->isItem() && $this->items()->isNotEmpty(); }

    /** المادة التي لها بنود تُقرأ من بنودها: مستوفى ن من م (عدا «لا ينطبق»). */
    public function itemsSummary(): array
    {
        $items = $this->items();
        return ['met' => $items->where('status', 'met')->count(), 'total' => $items->count(), 'na' => $items->where('status', 'na')->count()];
    }

    /** قابل للتقييم: بند، أو مادة بلا بنود. */
    public function isRatable(): bool { return !$this->hasItems(); }

    public function statusLabel(): string { return self::STATUSES[$this->status] ?? $this->status; }

    /**
     * جاهزية المبنى من صفوفه: القابل للتقييم ٤٦ (١٣ مادة بلا بنود + ٣٣ بنداً)؛ النسبة = المستوفى ÷ (القابل − لا ينطبق)؛
     * ما لم يُقيَّم يُعدّ غير مستوفى ويُكتب عدده.
     * @return array{ratable:int, met:int, in_progress:int, not_met:int, na:int, unrated:int, percent:int}
     */
    public static function readiness(int $buildingId): array
    {
        $rows = self::forBuilding($buildingId)->filter(fn ($r) => $r->isRatable());
        $c = fn (string $s) => $rows->where('status', $s)->count();
        $na = $c('na');
        $den = $rows->count() - $na;
        return ['ratable' => $rows->count(), 'met' => $c('met'), 'in_progress' => $c('in_progress'), 'not_met' => $c('not_met'), 'na' => $na,
            'unrated' => $c(self::UNRATED), 'percent' => $den > 0 ? (int) round($c('met') / $den * 100) : 0];
    }

    public function isEdited(): bool { return $this->edited_at !== null; }

    public function changedFields(): array
    {
        $out = [];
        foreach (self::WORD_FIELDS as $f) {
            if ($this->wordValue($f) !== self::norm($this->source[$f] ?? null)) $out[] = $f;
        }
        return $out;
    }

    public function changedLabels(): array
    {
        return array_map(fn ($f) => self::WORD_LABELS[$f], $this->changedFields());
    }

    /** قيمة خانة كلام بصيغة قابلة للمقارنة (الموعد يوماً). */
    private function wordValue(string $f): ?string
    {
        if ($f === 'due_date') return $this->due_date?->toDateString();
        return self::norm($this->{$f});
    }

    /** @return array<string, array{0:?string,1:?string}> ما تغيّر فعلاً */
    public function applyWords(array $in, User $by): array
    {
        $diff = [];
        foreach (self::WORD_FIELDS as $f) {
            if (!array_key_exists($f, $in)) continue;
            if ($f === 'status' && $this->hasItems()) continue; // المادة ذات البنود تُقرأ من بنودها
            $new = $f === 'status' ? (array_key_exists((string) $in[$f], self::STATUSES) ? (string) $in[$f] : self::UNRATED) : self::norm($in[$f]);
            if ($f === 'due_date' && $new !== null) $new = substr($new, 0, 10);
            $old = $this->wordValue($f);
            if ($new !== $old) $diff[$f] = [$old, $new];
            $this->{$f} = $new;
        }
        if ($diff) $this->markEdited($by);
        return $diff;
    }

    public function restoreSource(): void
    {
        foreach (self::WORD_FIELDS as $f) $this->{$f} = $this->source[$f] ?? ($f === 'status' ? self::UNRATED : null);
        $this->markEdited(null);
    }

    public function markEdited(?User $by): void
    {
        if ($this->changedFields()) {
            $this->edited_by_id = $by?->id ?? $this->edited_by_id;
            $this->edited_by_name = $by?->name ?? $this->edited_by_name;
            $this->edited_at = now();
        } else {
            $this->edited_by_id = null;
            $this->edited_by_name = null;
            $this->edited_at = null;
        }
    }

    public static function norm($v): ?string
    {
        $lines = array_filter(array_map(fn ($l) => trim(preg_replace('/[ \t]+/u', ' ', $l)), preg_split('/\r\n|\r|\n/', (string) ($v ?? ''))), fn ($l) => $l !== '');
        $t = implode("\n", $lines);
        return $t === '' ? null : $t;
    }
}
