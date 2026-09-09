<?php

namespace App\Modules\Permit\Models;

use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Worker\Models\Trade;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * «هذا الخطر (أو فئته) يستلزم تصريحاً من هذا النوع» — يقود الاقتراح في معالج الإنشاء.
 * يُملأ أحد الحقلين: `risk_id` أو `risk_category_id`. المعتاد فئة؛ والخطر المحدد للاستثناءات.
 */
class RiskRequiredPermitType extends Model
{
    public const COND_ALWAYS         = 'always';
    public const COND_SEVERITY_GE_2  = 'severity_ge_2';
    public const COND_SEVERITY_GE_3  = 'severity_ge_3';
    public const COND_SEVERITY_GE_4  = 'severity_ge_4';
    public const COND_SEVERITY_GE_5  = 'severity_ge_5';
    public const COND_REQUIRES_TRADE = 'requires_trade';

    protected $fillable = [
        'risk_id', 'risk_category_id', 'permit_type_id', 'is_mandatory', 'triggering_condition', 'trade_id', 'notes',
    ];

    protected $casts = ['is_mandatory' => 'boolean'];

    protected $attributes = ['is_mandatory' => true, 'triggering_condition' => self::COND_ALWAYS];

    public function risk(): BelongsTo         { return $this->belongsTo(Risk::class); }
    public function riskCategory(): BelongsTo { return $this->belongsTo(RiskCategory::class, 'risk_category_id'); }
    public function permitType(): BelongsTo   { return $this->belongsTo(PermitType::class); }
    public function trade(): BelongsTo        { return $this->belongsTo(Trade::class); }
}
