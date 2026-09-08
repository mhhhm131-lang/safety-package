<?php

namespace App\Modules\Risk\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * بند تحكم مهيكل مرتبط بخطر محدد أو فئة مخاطر.
 *
 * كل بند ينتمي لطور من ثلاثة:
 *   preventive  — قبل بدء العمل (يُثبَّت قبل التفعيل)
 *   operational — أثناء العمل (تذكيرات دورية)
 *   response    — عند الطوارئ (إجراءات الاستجابة)
 *
 * SmartJsaService يستعلم هذا الموديل لتوليد permit_requirements
 * تلقائياً عند إنشاء أي تصريح.
 */
class RiskControl extends Model
{
    public const PHASE_PREVENTIVE  = 'preventive';
    public const PHASE_OPERATIONAL = 'operational';
    public const PHASE_RESPONSE    = 'response';

    public const EVIDENCE_CHECK       = 'check';
    public const EVIDENCE_MEASUREMENT = 'measurement';
    public const EVIDENCE_PHOTO       = 'photo';
    public const EVIDENCE_SIGNATURE   = 'signature';
    public const EVIDENCE_DOCUMENT    = 'document';

    public const ROLE_REQUESTER       = 'requester';
    public const ROLE_SAFETY_ENGINEER = 'safety_engineer';
    public const ROLE_ISSUER          = 'issuer';
    public const ROLE_WORKER          = 'worker';
    public const ROLE_FIRE_WATCH      = 'fire_watch';
    public const ROLE_ATTENDANT       = 'attendant';
    public const ROLE_ANY             = 'any';

    protected $fillable = [
        'risk_id',
        'risk_category_id',
        'permit_type_code',
        'phase',
        'description_ar',
        'description_en',
        'evidence_type',
        'measurement_unit',
        'measurement_threshold',
        'responsible_role',
        'frequency',
        'is_mandatory',
        'sort_order',
        'standard_reference',
        'review_flag',
        'review_notes',
        'flag_count',
    ];

    protected $casts = [
        'is_mandatory' => 'boolean',
        'sort_order'   => 'integer',
        'review_flag'  => 'boolean',
        'flag_count'   => 'integer',
    ];

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    public function riskCategory(): BelongsTo
    {
        return $this->belongsTo(RiskCategory::class);
    }


    public function phaseLabel(): string
    {
        return match ($this->phase) {
            self::PHASE_PREVENTIVE  => 'استباقي',
            self::PHASE_OPERATIONAL => 'تشغيلي',
            self::PHASE_RESPONSE    => 'استجابة',
            default                 => $this->phase,
        };
    }

    public function phaseColor(): string
    {
        return match ($this->phase) {
            self::PHASE_PREVENTIVE  => '#0d6efd',
            self::PHASE_OPERATIONAL => '#fd7e14',
            self::PHASE_RESPONSE    => '#dc3545',
            default                 => '#6c757d',
        };
    }
}
