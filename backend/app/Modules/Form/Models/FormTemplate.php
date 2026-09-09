<?php

namespace App\Modules\Form\Models;

use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Risk\Models\Risk;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * قالب نموذج رقمي: توعية أو إقرار أو استبيان.
 *
 * غرض الوحدة (BACKEND.md ٥-٨): ما يُرسل إلى أشخاص ليقرؤوا أو يقرّوا أو يجيبوا.
 * **ليست نماذج الفحص العشرة** — تلك أنظمة × بنود بقراءات وجولات وتصعيد، وتبقى كما هي.
 */
class FormTemplate extends Model
{
    public const TYPE_AWARENESS             = 'awareness';
    public const TYPE_CONFIRMATION          = 'confirmation';
    public const TYPE_DECLARATION           = 'declaration';
    public const TYPE_DECLARATION_WITNESSED = 'declaration_witnessed';
    public const TYPE_SURVEY                = 'survey';
    public const TYPE_CUSTOM                = 'custom';

    public const TYPE_LABELS = [
        self::TYPE_AWARENESS             => 'توعية',
        self::TYPE_CONFIRMATION          => 'تأكيد قراءة',
        self::TYPE_DECLARATION           => 'إقرار موقَّع',
        self::TYPE_DECLARATION_WITNESSED => 'إقرار موقَّع بشاهد',
        self::TYPE_SURVEY                => 'استبيان',
        self::TYPE_CUSTOM                => 'مخصص',
    ];

    /**
     * درجة الخطر تحدد نوع النموذج المولَّد منه: كلما اشتدّ الخطر، اشتدّ الإثبات المطلوب.
     * الدرجة = الشدة × الاحتمال (١–٢٥) كما في وحدة المخاطر.
     */
    public const RISK_SCORE_TYPE_MAP = [
        5  => self::TYPE_AWARENESS,             // ≤ ٥: توعية
        9  => self::TYPE_CONFIRMATION,          // ≤ ٩: تأكيد قراءة
        14 => self::TYPE_DECLARATION,           // ≤ ١٤: إقرار موقَّع
        25 => self::TYPE_DECLARATION_WITNESSED, // ١٥ فأكثر: إقرار بشاهد
    ];

    protected $fillable = [
        'title', 'description', 'intro', 'form_type', 'source_risk_id',
        'organization_unit_id', 'place_id', 'is_active', 'created_by_id',
    ];

    protected $casts = ['is_active' => 'boolean'];

    protected $attributes = [
        'form_type' => self::TYPE_CUSTOM,
        'is_active' => true,
    ];

    public function fields(): HasMany           { return $this->hasMany(FormField::class, 'form_id')->orderBy('order'); }
    public function submissions(): HasMany      { return $this->hasMany(FormSubmission::class, 'form_id'); }
    public function assignments(): HasMany      { return $this->hasMany(FormAssignment::class, 'form_id'); }
    public function sourceRisk(): BelongsTo     { return $this->belongsTo(Risk::class, 'source_risk_id'); }
    public function organizationUnit(): BelongsTo { return $this->belongsTo(OrganizationUnit::class); }
    public function place(): BelongsTo          { return $this->belongsTo(Place::class); }
    public function createdBy(): BelongsTo      { return $this->belongsTo(User::class, 'created_by_id'); }

    public function risks(): BelongsToMany
    {
        return $this->belongsToMany(Risk::class, 'form_template_risks', 'form_template_id', 'risk_id');
    }

    public function getTypeLabel(): string
    {
        return self::TYPE_LABELS[$this->form_type] ?? $this->form_type;
    }

    /** نوع النموذج المناسب لدرجة خطر. */
    public static function typeForRiskScore(int $score): string
    {
        foreach (self::RISK_SCORE_TYPE_MAP as $max => $type) {
            if ($score <= $max) {
                return $type;
            }
        }

        return self::TYPE_DECLARATION_WITNESSED;
    }

    /** هل يلزم هذا النوع توقيعاً؟ */
    public function requiresSignature(): bool
    {
        return in_array($this->form_type, [self::TYPE_DECLARATION, self::TYPE_DECLARATION_WITNESSED], true);
    }

    /** إحصاء التكليفات: مكلَّف، مكتمل، متأخر. */
    public function assignmentStats(): array
    {
        $rows = $this->assignments()->get(['status']);

        return [
            'total'     => $rows->count(),
            'completed' => $rows->where('status', FormAssignment::STATUS_COMPLETED)->count(),
            'overdue'   => $rows->where('status', FormAssignment::STATUS_OVERDUE)->count(),
            'pending'   => $rows->where('status', FormAssignment::STATUS_PENDING)->count(),
        ];
    }
}
