<?php

namespace App\Modules\Governance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * صف في خطة سلامة مكان (المرحلة ٣٠-١): المهمة للصنف لا تُعدَّل، وبقية الخانات كلام المكان.
 * `source` ما قالته الوثيقة آخر قراءة؛ `edited_at` غير فارغ = عدّله المكان (كلاماً أو «لا ينطبق هنا») فلا تمسّه المزامنة.
 */
class SafetyPlanTask extends Model
{
    protected $fillable = ['plan_id', 'layer', 'sort', 'task_key', 'task', 'responsible', 'when_text', 'how_text',
        'frequency', 'documentation', 'source', 'not_applicable', 'edited_by_id', 'edited_by_name', 'edited_at'];

    protected $casts = ['source' => 'array', 'not_applicable' => 'boolean', 'edited_at' => 'datetime', 'sort' => 'integer'];

    /** الطبقتان كما في الوثيقة وبترتيبها. */
    public const LAYERS = [
        'proactive' => 'الطبقة الاستباقية — المنع والجاهزية',
        'operational' => 'الطبقة التشغيلية — التنفيذ المستمر',
    ];

    /** خانات كلام المكان لكل طبقة بعناوينها كما في رأس الجدول (المهمة ليست منها). */
    public const COLUMNS = [
        'proactive' => ['responsible' => 'المسؤول', 'when_text' => 'متى', 'how_text' => 'كيف'],
        'operational' => ['responsible' => 'المسؤول', 'frequency' => 'التكرار', 'documentation' => 'التوثيق'],
    ];

    /** كل خانات الكلام (تُحفظ في `source` كلها؛ الفارغة للطبقة الأخرى null). */
    public const WORD_FIELDS = ['responsible', 'when_text', 'how_text', 'frequency', 'documentation'];

    public function plan(): BelongsTo { return $this->belongsTo(SafetyPlan::class, 'plan_id'); }

    public function editedBy(): BelongsTo { return $this->belongsTo(User::class, 'edited_by_id'); }

    /** خانات هذه الطبقة بعناوينها. */
    public function columns(): array { return self::COLUMNS[$this->layer] ?? []; }

    public function isEdited(): bool { return $this->edited_at !== null; }

    /** الخانات التي يختلف كلامها عن الوثيقة. */
    public function changedFields(): array
    {
        $out = [];
        foreach (array_keys($this->columns()) as $f) {
            if (self::norm($this->{$f}) !== self::norm($this->source[$f] ?? null)) $out[] = $f;
        }
        return $out;
    }

    /** «في كذا»: عناوين الخانات المتغيّرة و«لا ينطبق هنا». */
    public function changedLabels(): array
    {
        $labels = array_map(fn ($f) => $this->columns()[$f], $this->changedFields());
        if ($this->not_applicable) $labels[] = 'لا ينطبق هنا';
        return $labels;
    }

    /**
     * كتابة كلام المكان من الشاشة: الخانات بحسب الطبقة و«لا ينطبق هنا». إن عاد الصف كما في الوثيقة زالت علامة التعديل.
     * @return array<string, array{0:?string,1:?string}> ما تغيّر فعلاً [الخانة => [قبل، بعد]]
     */
    public function applyWords(array $in, bool $notApplicable, User $by): array
    {
        $diff = [];
        foreach (array_keys($this->columns()) as $f) {
            if (!array_key_exists($f, $in)) continue;
            $new = self::norm($in[$f]);
            if ($new !== self::norm($this->{$f})) $diff[$f] = [$this->{$f}, $new];
            $this->{$f} = $new;
        }
        if ($notApplicable !== (bool) $this->not_applicable) $diff['not_applicable'] = [$this->not_applicable ? 'نعم' : 'لا', $notApplicable ? 'نعم' : 'لا'];
        $this->not_applicable = $notApplicable;
        if ($diff) $this->markEdited($by);
        return $diff;
    }

    /** استعادة الأصل من الوثيقة بضغطة. */
    public function restoreSource(): void
    {
        foreach (self::WORD_FIELDS as $f) $this->{$f} = $this->source[$f] ?? null;
        $this->not_applicable = false;
        $this->markEdited(null);
    }

    /** علامة التعديل: تبقى ما دام الكلام يخالف الوثيقة أو الصف «لا ينطبق هنا»، وتزول حين يعود كما كان. */
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

    /** نص مطبَّع: فراغات مطويّة، والفارغ null. */
    public static function norm($v): ?string
    {
        $t = trim(preg_replace('/\s+/u', ' ', (string) ($v ?? '')));
        return $t === '' ? null : $t;
    }
}
