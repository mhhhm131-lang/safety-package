<?php

namespace App\Modules\Risk\Models;

use App\Core\Traits\HasAuditLog;
use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Risk\Services\RiskCodeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * الخطر (منقول من OHSMS بلا tenant/project/external_party/trades/activities + place_id).
 * ثلاثة سجلات: master = كتاب المخاطر، reference = السجل العام للمعهد، active = سجل الإدارة/المكان.
 */
class Risk extends Model
{
    use HasAuditLog;

    protected $fillable = [
        'risk_type', 'parent_reference_id', 'code', 'title', 'description',
        'category_id', 'sub_category_id', 'risk_type_category_id',
        'severity', 'likelihood', 'risk_score', 'benefit', 'contact_channel', 'target_closure_date',
        'scope_type', 'organization_unit_id', 'place_id', 'place_unit_id', 'status',
        'approved_by_id', 'approved_at', 'approval_notes', 'notes', 'legal_reference',
        'created_by_id', 'assigned_coordinator_id', 'assigned_field_team_id',
        'incident_count', 'last_incident_at',
    ];

    protected $casts = [
        'severity' => 'integer', 'likelihood' => 'integer', 'risk_score' => 'integer',
        'incident_count' => 'integer', 'last_incident_at' => 'datetime',
        'target_closure_date' => 'date', 'approved_at' => 'datetime',
    ];

    protected $attributes = [
        'risk_type' => 'active', 'severity' => 1, 'likelihood' => 1, 'risk_score' => 1,
        'scope_type' => 'general', 'status' => 'draft',
    ];

    public const STATUS_LABELS = [
        'draft' => 'مسودة', 'pending_approval' => 'بانتظار الاعتماد', 'approved' => 'معتمد', 'rejected' => 'مرفوض',
        'active' => 'نشط', 'in_progress' => 'قيد المعالجة', 'escalated' => 'مصعّد', 'closed' => 'مغلق',
    ];

    /** قرار ٧٠: ما لم يُعتمد لا يعمل — المسودة وبانتظار الاعتماد والمرفوض (والمغلق) لا توجّه بلاغاً ولا تُعدّ مفعّلة */
    public const NOT_IN_EFFECT = ['draft', 'pending_approval', 'rejected', 'closed'];

    public function scopeInEffect($query)
    {
        return $query->whereNotIn('status', self::NOT_IN_EFFECT);
    }

    /**
     * قرار ٧٥ (٢٠٢٦-١٠-٠٥، بكلمته «لم أجده في العام، وجدته في مخاطر في انتظار اعتمادك»): ما لم يعتمده مسؤول السلامة مقترح —
     * لا يُعرض في السجل العام ولا يُفعَّل منه؛ مكانه «ما ينتظرك» عند صاحبه ثم عند مسؤول السلامة.
     */
    public const PROPOSED = ['draft', 'pending_approval', 'rejected'];

    public function scopeAdopted($query)
    {
        return $query->whereNotIn('status', self::PROPOSED);
    }

    /**
     * مخاطر مكان: ما كُتب عليه المكان، أو خطر وحدة تشغل هذا المكان ولم يُكتب عليه مكان.
     * التفعيل لوحدة يكتب الوحدة ولا يسأل عن المكان — فالبحث بخانة المكان وحدها كان يُخفي سجل الوحدة عن ملف مكانها
     * (كُشف على المنشور ٢٠٢٦-١٠-٠٤: ١٥١ خطراً لقسم في المكاتب الإدارية وملف المكان يقول «٠»).
     */
    public function scopeOfPlace($query, ?int $placeId)
    {
        if (!$placeId) return $query->whereRaw('1 = 0');
        return $query->where(fn ($q) => $q->where('risks.place_id', $placeId)
            ->orWhere(fn ($w) => $w->whereNull('risks.place_id')
                ->whereIn('risks.organization_unit_id', OrganizationUnit::where('place_id', $placeId)->select('id'))));
    }

    protected static function booted(): void
    {
        static::creating(function (Risk $risk) {
            $risk->risk_score = $risk->severity * $risk->likelihood;
            if (empty($risk->code) && $risk->risk_type === 'active' && $risk->parent_reference_id
                && ($parent = self::find($risk->parent_reference_id)) && $parent->code) {
                // قرار ٢٩: كود النسخة = كود الأصل/رمز الوحدة
                $unitCode = $risk->organization_unit_id ? \App\Modules\Governance\Models\OrganizationUnit::find($risk->organization_unit_id)?->code : null;
                $placeCode = $risk->place_id ? \App\Modules\Governance\Models\Place::find($risk->place_id)?->code : null;
                $risk->code = app(RiskCodeService::class)->generateActiveCode($parent, $unitCode, $placeCode);
            }
            if (empty($risk->code) && $risk->category_id) {
                $risk->code = app(RiskCodeService::class)->generateRiskCode($risk->category_id, (int) $risk->sub_category_id);
            }
        });
        static::updating(function (Risk $risk) {
            $risk->risk_score = $risk->severity * $risk->likelihood;
        });
    }

    public function parentReference(): BelongsTo { return $this->belongsTo(self::class, 'parent_reference_id'); }
    public function category(): BelongsTo { return $this->belongsTo(RiskCategory::class, 'category_id'); }
    public function subCategory(): BelongsTo { return $this->belongsTo(RiskSubCategory::class, 'sub_category_id'); }
    public function riskTypeCategory(): BelongsTo { return $this->belongsTo(RiskCause::class, 'risk_type_category_id'); }
    public function assignedCoordinator(): BelongsTo { return $this->belongsTo(User::class, 'assigned_coordinator_id'); }
    public function assignedFieldTeam(): BelongsTo { return $this->belongsTo(User::class, 'assigned_field_team_id'); }
    public function organizationUnit(): BelongsTo { return $this->belongsTo(OrganizationUnit::class, 'organization_unit_id'); }
    public function place(): BelongsTo { return $this->belongsTo(Place::class); }
    public function placeUnit(): BelongsTo { return $this->belongsTo(\App\Modules\Governance\Models\PlaceUnit::class); } // ١٨-٣ (ج)
    public function approvedBy(): BelongsTo { return $this->belongsTo(User::class, 'approved_by_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_id'); }
    public function notes(): HasMany { return $this->hasMany(RiskNote::class); }
    public function events(): HasMany { return $this->hasMany(RiskEvent::class); }
    public function phases(): HasMany { return $this->hasMany(RiskPhase::class); }
    public function controls(): HasMany { return $this->hasMany(RiskControl::class); }

    /** مصحَّح: حالات آلة الحالة الثماني فقط (OHSMS كان يعرض حالتين غير موجودة). */
    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function auditLabel(): string
    {
        return ($this->code ? $this->code.' ' : '').$this->title;
    }
}
