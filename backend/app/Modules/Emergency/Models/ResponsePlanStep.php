<?php

namespace App\Modules\Emergency\Models;

use App\Models\User;
use App\Modules\Emergency\Support\RoleCards;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * خطوة في خطة استجابة مكان — متى · من · أين · كيف كما في الوثيقة، مع نافذة بالثواني وبطاقة الدور.
 * المرحلة ٣٠-٢ (قرار ٨٩): الشكل للصنف (المسار، الرقم، العنوان، متى، النافذة) والكلام للمكان (من، أين، كيف، الهاتف، «لا ينطبق هنا»).
 * `source` ما قالته الوثيقة آخر قراءة (الكلمات وبطاقات الدور)؛ `edited_at` غير فارغ = عدّله المكان فلا تمسّه المزامنة.
 * بطاقات الدور تُستخرج من «من» الحالي: من غيّر «من» إلى جهة خارجية صارت خطوته بلا بطاقة، وهذا معناها.
 */
class ResponsePlanStep extends Model
{
    protected $fillable = ['plan_id', 'step_key', 'path_key', 'path_title', 'path_declared_count', 'sort', 'label', 'title',
        'when_text', 'window_from_sec', 'window_to_sec', 'is_conditional', 'who_text', 'where_text', 'how_text', 'phone_text',
        'role_cards', 'role_card_no', 'source', 'not_applicable', 'edited_by_id', 'edited_by_name', 'edited_at'];

    protected $casts = ['role_cards' => 'array', 'is_conditional' => 'boolean', 'sort' => 'integer',
        'window_from_sec' => 'integer', 'window_to_sec' => 'integer', 'role_card_no' => 'integer',
        'path_declared_count' => 'integer', 'source' => 'array', 'not_applicable' => 'boolean', 'edited_at' => 'datetime'];

    /** خانات كلام المكان بعناوينها كما في الوثيقة. */
    public const WORD_LABELS = ['who_text' => 'من', 'where_text' => 'أين', 'how_text' => 'كيف', 'phone_text' => 'الهاتف'];

    public const WORD_FIELDS = ['who_text', 'where_text', 'how_text', 'phone_text'];

    /** ما يُحفظ في `source` مع الكلمات: بطاقات الدور كما استُخرجت من «من» الوثيقة. */
    public const SOURCE_FIELDS = ['who_text', 'where_text', 'how_text', 'phone_text', 'role_cards', 'role_card_no'];

    public function plan(): BelongsTo { return $this->belongsTo(ResponsePlan::class, 'plan_id'); }

    public function editedBy(): BelongsTo { return $this->belongsTo(User::class, 'edited_by_id'); }

    public function isLive(): bool { return in_array($this->path_key, ResponsePlan::LIVE_PATHS, true); }

    public function hasCard(): bool { return $this->role_card_no !== null; }

    public function isEdited(): bool { return $this->edited_at !== null; }

    /** الخانات التي يختلف كلامها عن الوثيقة. */
    public function changedFields(): array
    {
        $out = [];
        foreach (self::WORD_FIELDS as $f) {
            if (self::norm($this->{$f}) !== self::norm($this->source[$f] ?? null)) $out[] = $f;
        }
        return $out;
    }

    /** «في كذا»: عناوين الخانات المتغيّرة و«لا ينطبق هنا». */
    public function changedLabels(): array
    {
        $labels = array_map(fn ($f) => self::WORD_LABELS[$f], $this->changedFields());
        if ($this->not_applicable) $labels[] = 'لا ينطبق هنا';
        return $labels;
    }

    /**
     * كتابة كلام المكان من الشاشة. «من» الجديد يعيد استخراج بطاقات الدور؛ وإن عاد الصف كما في الوثيقة زالت علامة التعديل.
     * @return array<string, array{0:?string,1:?string}> ما تغيّر فعلاً [الخانة => [قبل، بعد]]
     */
    public function applyWords(array $in, bool $notApplicable, User $by): array
    {
        $diff = [];
        foreach (self::WORD_FIELDS as $f) {
            if (!array_key_exists($f, $in)) continue;
            $new = self::norm($in[$f]);
            if ($new !== self::norm($this->{$f})) $diff[$f] = [$this->{$f}, $new];
            $this->{$f} = $new;
        }
        if ($notApplicable !== (bool) $this->not_applicable) $diff['not_applicable'] = [$this->not_applicable ? 'نعم' : 'لا', $notApplicable ? 'نعم' : 'لا'];
        $this->not_applicable = $notApplicable;
        if ($diff) {
            $this->recomputeCards();
            $this->markEdited($by);
        }
        return $diff;
    }

    /** استعادة الأصل من الوثيقة بضغطة. */
    public function restoreSource(): void
    {
        foreach (self::SOURCE_FIELDS as $f) $this->{$f} = $this->source[$f] ?? null;
        $this->not_applicable = false;
        $this->markEdited(null);
    }

    /** بطاقات الدور من «من» الحالي: كما في الوثيقة إن لم يتغيّر، وإلا من النص الجديد. */
    public function recomputeCards(): void
    {
        if (self::norm($this->who_text) === self::norm($this->source['who_text'] ?? null)) {
            $this->role_cards = $this->source['role_cards'] ?? [];
            $this->role_card_no = $this->source['role_card_no'] ?? null;
            return;
        }
        $cards = $this->who_text ? RoleCards::match($this->who_text) : [];
        $this->role_cards = $cards;
        $this->role_card_no = $cards[0] ?? null;
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

    public static function norm($v): ?string
    {
        $t = trim(preg_replace('/\s+/u', ' ', (string) ($v ?? '')));
        return $t === '' ? null : $t;
    }

    /** ٣٠-٣: مبنى الخطوة (مبنى مكان خطتها) — البطاقات تُقرأ في مبناها. */
    public function buildingId(): ?int { return $this->plan?->place?->building_id; }

    /** البطاقات المطابقة كمصفوفة [رقم => بيانات البطاقة في مبناها]. */
    public function cards(): array
    {
        $b = $this->buildingId();
        $out = [];
        foreach ($this->role_cards ?? [] as $n) if ($c = RoleCards::forBuilding($b, (int) $n)) $out[(int) $n] = $c;
        return $out;
    }

    public function primaryCard(): ?array { return $this->role_card_no ? RoleCards::forBuilding($this->buildingId(), $this->role_card_no) : null; }

    /** النافذة بصيغة مقروءة: «٠–٥ ث» أو «شرطية». */
    public function windowLabel(): string
    {
        if ($this->window_from_sec === null || $this->window_to_sec === null) return $this->is_conditional ? 'شرطية' : '—';
        $fmt = function (int $s): string {
            if ($s >= 3600 && $s % 3600 === 0) return ($s / 3600).' س';
            if ($s >= 60 && $s % 60 === 0) return ($s / 60).' د';
            return $s.' ث';
        };
        return $this->window_from_sec === 0 && $this->window_to_sec === 5 ? 'الثانية الأولى (٠–٥ ث)' : $fmt($this->window_from_sec).' – '.$fmt($this->window_to_sec);
    }
}
