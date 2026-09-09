<?php

namespace App\Modules\Permit\Models;

use App\Modules\Governance\Models\Place;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * «نوع تصريح (أ) لا يتزامن مع نوع تصريح (ب)» — عامة أو مقيَّدة بمكان.
 * يقرؤها PermitConflictService عند الاعتماد والتفعيل. (في OHSMS كانت بمنطقة العمل؛ عندنا بالمكان.)
 */
class PermitTypeConflictRule extends Model
{
    public const SEVERITY_BLOCK = 'block';
    public const SEVERITY_WARN  = 'warn';

    public const SEVERITY_LABELS = ['block' => 'مانع', 'warn' => 'تنبيه'];

    protected $fillable = ['permit_type_a_id', 'permit_type_b_id', 'place_id', 'severity', 'reason', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected $attributes = ['severity' => self::SEVERITY_BLOCK, 'is_active' => true];

    public function permitTypeA(): BelongsTo { return $this->belongsTo(PermitType::class, 'permit_type_a_id'); }
    public function permitTypeB(): BelongsTo { return $this->belongsTo(PermitType::class, 'permit_type_b_id'); }
    public function place(): BelongsTo       { return $this->belongsTo(Place::class); }

    public function getSeverityLabel(): string
    {
        return self::SEVERITY_LABELS[$this->severity] ?? $this->severity;
    }
}
