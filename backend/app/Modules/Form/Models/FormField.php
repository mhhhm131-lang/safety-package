<?php

namespace App\Modules\Form\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * حقل في نموذج. الأنواع السبعة من OHSMS + ثلاثة أضيفت للمعهد:
 * `acknowledge` (إقرار بالاطلاع)، `signature` (توقيع مرسوم)، `photo` (صورة).
 * `help_text` و`placeholder` كانت شاشة التعبئة في OHSMS تستعملهما بلا عمودين في القاعدة.
 *
 * ٢٩-١ (قرار ٨٧، وثيقة التصميم §٤-١) — ثلاثة أنواع للنماذج الثمانية غير المادية:
 *   `section`  عنوان قسم بلا إجابة.
 *   `table`    جدول: `options` = {columns: [{label, type: text|select|fixed, options}], rows: [صفوف ثابتة], can_add}؛ الإجابة صفوف JSON.
 *              يُعرَّف من الشاشة سطراً لكل عمود «الاسم | نص/اختيار/ثابت | خيار، خيار» وسطراً يبدأ بـ«+» لكل صف ثابت (الآليات × المراحل).
 *   `ack_item` بند إقرار ثلاثي (صحيح؟ أفهمه؟ مطبَّق؟) وخانة «ما الواقع» إلزامية عند أي «لا»؛ الإجابة {ok, und, app, note}.
 */
class FormField extends Model
{
    public const TYPE_TEXT        = 'text';
    public const TYPE_NUMBER      = 'number';
    public const TYPE_TEXTAREA    = 'textarea';
    public const TYPE_SELECT      = 'select';
    public const TYPE_RADIO       = 'radio';
    public const TYPE_CHECKBOX    = 'checkbox';
    public const TYPE_DATE        = 'date';
    public const TYPE_ACKNOWLEDGE = 'acknowledge';
    public const TYPE_SIGNATURE   = 'signature';
    public const TYPE_PHOTO       = 'photo';
    public const TYPE_SECTION     = 'section';
    public const TYPE_TABLE       = 'table';
    public const TYPE_ACK_ITEM    = 'ack_item';

    public const TYPE_LABELS = [
        self::TYPE_TEXT        => 'نص قصير',
        self::TYPE_NUMBER      => 'رقم',
        self::TYPE_TEXTAREA    => 'نص طويل',
        self::TYPE_SELECT      => 'قائمة منسدلة',
        self::TYPE_RADIO       => 'اختيار واحد',
        self::TYPE_CHECKBOX    => 'اختيار متعدد',
        self::TYPE_DATE        => 'تاريخ',
        self::TYPE_ACKNOWLEDGE => 'إقرار بالاطلاع',
        self::TYPE_SIGNATURE   => 'توقيع',
        self::TYPE_PHOTO       => 'صورة',
        self::TYPE_SECTION     => 'قسم (عنوان بلا إجابة)',
        self::TYPE_TABLE       => 'جدول',
        self::TYPE_ACK_ITEM    => 'بند إقرار ثلاثي',
    ];

    /** الأنواع التي تحتاج قائمة خيارات (والجدول يحتاج تعريفه). */
    public const TYPES_WITH_OPTIONS = [self::TYPE_SELECT, self::TYPE_RADIO, self::TYPE_CHECKBOX, self::TYPE_TABLE];

    /** الأنواع التي دليلها ملف يُرفع أو يُرسم. */
    public const TYPES_WITH_FILE = [self::TYPE_SIGNATURE, self::TYPE_PHOTO];

    /** الأنواع بلا إجابة (عناوين). */
    public const TYPES_WITHOUT_ANSWER = [self::TYPE_SECTION];

    /** أسئلة بند الإقرار الثلاثي بمفاتيح إجابته. */
    public const ACK_QUESTIONS = ['ok' => 'صحيح؟', 'und' => 'أفهمه؟', 'app' => 'مطبَّق؟'];
    public const ACK_YES = 'نعم';
    public const ACK_NO  = 'لا';

    /** أنواع أعمدة الجدول وأسماؤها في الشاشة. */
    public const TABLE_COLUMN_TYPES = ['text' => 'نص', 'select' => 'اختيار', 'fixed' => 'ثابت'];

    protected $fillable = [
        'form_id', 'label', 'help_text', 'placeholder', 'field_type', 'is_required', 'feeds_ack', 'order', 'options',
    ];

    protected $casts = [
        'options'     => 'array',
        'is_required' => 'boolean',
        'feeds_ack'   => 'boolean', // ٢٩-٣: يدخل في توليد الإقرار السري (٢٩-٥)
        'order'       => 'integer',
    ];

    protected $attributes = [
        'is_required' => false,
        'order'       => 0,
    ];

    public function form(): BelongsTo   { return $this->belongsTo(FormTemplate::class, 'form_id'); }
    public function answers(): HasMany  { return $this->hasMany(FormAnswer::class, 'field_id'); }

    public function getTypeLabel(): string
    {
        return self::TYPE_LABELS[$this->field_type] ?? $this->field_type;
    }

    public function needsOptions(): bool
    {
        return in_array($this->field_type, self::TYPES_WITH_OPTIONS, true);
    }

    public function isFileField(): bool
    {
        return in_array($this->field_type, self::TYPES_WITH_FILE, true);
    }

    public function isSection(): bool  { return $this->field_type === self::TYPE_SECTION; }
    public function isTable(): bool    { return $this->field_type === self::TYPE_TABLE; }
    public function isAckItem(): bool  { return $this->field_type === self::TYPE_ACK_ITEM; }

    /** هل لهذا الحقل إجابة تُحفظ وتُعرض؟ (القسم عنوان فقط) */
    public function hasAnswer(): bool
    {
        return !in_array($this->field_type, self::TYPES_WITHOUT_ANSWER, true);
    }

    /** خيارات القوائم (الاختيار الواحد والمتعدد والمنسدلة) كقائمة نصوص — للجدول تُقرأ من `tableDef()`. */
    public function choiceOptions(): array
    {
        $o = $this->options;
        if (!is_array($o) || isset($o['columns'])) return [];
        return array_values(array_map('strval', $o));
    }

    /**
     * تعريف الجدول مطبَّعاً. `min_rows` عدد الصفوف الابتدائية للجدول الحر، و`esc` زر «فتح بلاغ» على الصف (٢٩-٣ من الواجهة؛ يُرسم في ٢٩-٧).
     * @return array{columns: array<int, array{label: string, type: string, options: string[]}>, rows: string[], can_add: bool, min_rows: int, esc: bool}
     */
    public function tableDef(): array
    {
        return self::normalizeTableDef(is_array($this->options) ? $this->options : []);
    }

    /** @return array{columns: array<int, array{label: string, type: string, options: string[]}>, rows: string[], can_add: bool, min_rows: int, esc: bool} */
    public static function normalizeTableDef(array $raw): array
    {
        $columns = [];
        foreach ((array) ($raw['columns'] ?? []) as $c) {
            if (is_string($c)) $c = ['label' => $c];
            $label = trim((string) ($c['label'] ?? ''));
            if ($label === '') continue;
            $type = (string) ($c['type'] ?? 'text');
            if (!isset(self::TABLE_COLUMN_TYPES[$type])) $type = 'text';
            $options = array_values(array_filter(array_map(fn ($o) => trim((string) $o), (array) ($c['options'] ?? [])), fn ($o) => $o !== ''));
            if ($type === 'select' && $options === []) $type = 'text';
            $columns[] = ['label' => $label, 'type' => $type, 'options' => $options];
        }
        $rows = array_values(array_filter(array_map(fn ($r) => trim((string) $r), (array) ($raw['rows'] ?? [])), fn ($r) => $r !== ''));
        $canAdd = array_key_exists('can_add', $raw) ? (bool) $raw['can_add'] : $rows === [];
        $minRows = $rows !== [] ? count($rows) : max(1, min(10, (int) ($raw['min_rows'] ?? 2)));

        return ['columns' => $columns, 'rows' => $rows, 'can_add' => $canAdd, 'min_rows' => $minRows, 'esc' => (bool) ($raw['esc'] ?? false)];
    }

    /**
     * تعريف الجدول من سطور الشاشة: «الاسم | نص/اختيار/ثابت | خيار، خيار» لكل عمود، و«+ اسم الصف» لكل صف ثابت.
     * @return array{columns: array, rows: string[], can_add: bool}
     */
    public static function parseTableText(string $text): array
    {
        $typeByName = array_flip(self::TABLE_COLUMN_TYPES) + ['text' => 'text', 'select' => 'select', 'fixed' => 'fixed'];
        $columns = [];
        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (str_starts_with($line, '+')) {
                $rows[] = trim(substr($line, 1));
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $type = $typeByName[$parts[1] ?? 'نص'] ?? 'text';
            $opts = isset($parts[2]) ? preg_split('/[،,]/u', $parts[2]) : [];
            $columns[] = ['label' => $parts[0], 'type' => $type, 'options' => $opts];
        }

        return self::normalizeTableDef(['columns' => $columns, 'rows' => $rows]);
    }

    /** عكس `parseTableText`: تعريف الجدول سطوراً تُعرض في مربع الخيارات. */
    public static function tableToText(array $def): string
    {
        $def = self::normalizeTableDef($def);
        $lines = [];
        foreach ($def['columns'] as $c) {
            $line = $c['label'].' | '.self::TABLE_COLUMN_TYPES[$c['type']];
            if ($c['type'] === 'select') $line .= ' | '.implode('، ', $c['options']);
            $lines[] = $line;
        }
        foreach ($def['rows'] as $r) $lines[] = '+ '.$r;

        return implode("\n", $lines);
    }

    /** الخيارات كما تُعرض في شارة الحقل بصفحة النموذج. */
    public function optionsSummary(): ?string
    {
        if ($this->isTable()) {
            $def = $this->tableDef();
            if ($def['columns'] === []) return null;
            $s = 'الأعمدة: '.implode(' · ', array_column($def['columns'], 'label'));
            if ($def['rows']) $s .= ' — الصفوف: '.implode(' · ', $def['rows']);
            return $s;
        }
        $o = $this->choiceOptions();

        return $o ? implode(' · ', $o) : null;
    }
}
