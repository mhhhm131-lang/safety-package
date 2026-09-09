<?php

namespace App\Modules\Permit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** بند في كتالوج تأهيل نوع تصريح (تأهيل المقاول قبل التعاقد/قبل بدء العمل، وتأهيل الوحدة الداخلية). */
class QualificationChecklistItem extends Model
{
    protected $fillable = [
        'permit_type_id', 'phase', 'description_ar', 'description_en',
        'evidence_type', 'is_mandatory', 'standard_reference', 'sort_order',
    ];

    protected $casts = ['is_mandatory' => 'boolean', 'sort_order' => 'integer'];

    public function permitType(): BelongsTo { return $this->belongsTo(PermitType::class); }
}
