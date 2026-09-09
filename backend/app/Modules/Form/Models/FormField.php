<?php

namespace App\Modules\Form\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * حقل في نموذج. الأنواع السبعة من OHSMS + ثلاثة أضيفت للمعهد:
 * `acknowledge` (إقرار بالاطلاع)، `signature` (توقيع مرسوم)، `photo` (صورة).
 * `help_text` و`placeholder` كانت شاشة التعبئة في OHSMS تستعملهما بلا عمودين في القاعدة.
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
    ];

    /** الأنواع التي تحتاج قائمة خيارات. */
    public const TYPES_WITH_OPTIONS = [self::TYPE_SELECT, self::TYPE_RADIO, self::TYPE_CHECKBOX];

    /** الأنواع التي دليلها ملف يُرفع أو يُرسم. */
    public const TYPES_WITH_FILE = [self::TYPE_SIGNATURE, self::TYPE_PHOTO];

    protected $fillable = [
        'form_id', 'label', 'help_text', 'placeholder', 'field_type', 'is_required', 'order', 'options',
    ];

    protected $casts = [
        'options'     => 'array',
        'is_required' => 'boolean',
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
}
