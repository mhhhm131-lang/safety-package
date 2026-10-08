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
        // خطة المعالج — الخطوة ١ (٢٠٢٦-١٠-٠٨): الإدارة المعالجة باسمها، والمعالج تخصصاً أو شخصاً، ومن كتبه
        'handling_unit_id', 'handling_unit_name', 'handler_specialty', 'handler_user_id', 'handler_set_by_id', 'handler_set_at',
        'branch_unit_id', 'handling_override_by_id', 'handling_override_at', 'handling_override_approved_by_id', 'handling_override_approved_at', // قرار ٨٠
    ];

    protected $casts = [
        'severity' => 'integer', 'likelihood' => 'integer', 'risk_score' => 'integer',
        'incident_count' => 'integer', 'last_incident_at' => 'datetime',
        'target_closure_date' => 'date', 'approved_at' => 'datetime', 'handler_set_at' => 'datetime',
        'handling_override_at' => 'datetime', 'handling_override_approved_at' => 'datetime',
    ];

    /** خطة المعالج: الحقول التي يكتبها مدير الإدارة المعالجة وحدها — لا غيرها */
    public const HANDLER_FIELDS = ['handler_specialty', 'handler_user_id', 'handler_set_by_id', 'handler_set_at'];

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
            // قرار ٨٠: «الفرع» كـ«الإدارة» — يتعبّأ تلقائياً عند التفعيل من رأس وحدة التفعيل
            if ($risk->risk_type === 'active' && $risk->organization_unit_id && !$risk->branch_unit_id) {
                $risk->branch_unit_id = OrganizationUnit::find($risk->organization_unit_id)?->headOf()?->id;
            }
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
    // خطة المعالج — الخطوة ١
    public function handlingUnit(): BelongsTo { return $this->belongsTo(OrganizationUnit::class, 'handling_unit_id'); }
    public function handlerUser(): BelongsTo { return $this->belongsTo(User::class, 'handler_user_id'); }
    public function handlerSetBy(): BelongsTo { return $this->belongsTo(User::class, 'handler_set_by_id'); }

    /**
     * خطة المعالج — الخطوة ٤ (٢٠٢٦-١٠-٠٨): الخاص يقرأ الإدارة المعالجة والمعالج من العام قراءةً (أصل النسخة)، لا ينسخهما.
     * فتغيير مسؤول السلامة أو مدير الإدارة المعالجة في العام يظهر في كل نسخة فوراً.
     * قرار ٨٠: نسخة الفرع قد تبدّل الإدارة المعالجة لفرعها (بعد اعتماد مدير الفرع) وتحمل معالجها الخاص — فتُقدَّم على العام.
     */
    public function generalSource(): ?self
    {
        if ($this->risk_type !== 'active') return $this;
        return $this->parent_reference_id ? $this->parentReference : null;
    }

    public function branchUnit(): BelongsTo { return $this->belongsTo(OrganizationUnit::class, 'branch_unit_id'); }
    public function handlingOverrideBy(): BelongsTo { return $this->belongsTo(User::class, 'handling_override_by_id'); }
    public function handlingOverrideApprovedBy(): BelongsTo { return $this->belongsTo(User::class, 'handling_override_approved_by_id'); }

    /** قرار ٨٠: حال تبديل الفرع للإدارة المعالجة في هذه النسخة: approved | pending | null */
    public function getHandlingOverrideStateAttribute(): ?string
    {
        if ($this->risk_type !== 'active' || !$this->handling_override_by_id || !($this->handling_unit_id || $this->handling_unit_name)) return null;
        return $this->handling_override_approved_at ? 'approved' : 'pending';
    }

    /** الإدارة المعالجة النافذة: بديل الفرع المعتمد، وإلا العام. @return array{id: ?int, name: ?string, source: string} */
    public function effectiveHandling(): array
    {
        if ($this->handling_override_state === 'approved') {
            return ['id' => $this->handling_unit_id, 'name' => $this->handling_unit_name ?: $this->handlingUnit?->name, 'source' => 'branch'];
        }
        $g = $this->generalSource();
        return ['id' => $g?->handling_unit_id, 'name' => $g ? ($g->handling_unit_name ?: $g->handlingUnit?->name) : null, 'source' => 'general'];
    }

    /** الإدارة المعالجة كما تُعرض: بديل الفرع المعتمد، وإلا الاسم المحفوظ في العام (يبقى ولو عُطّلت الوحدة) */
    public function getHandlingUnitDisplayAttribute(): ?string
    {
        return $this->effectiveHandling()['name'];
    }

    /** هل لهذه النسخة معالج خاص بفرعها (قرار ٨٠)؟ */
    public function hasOwnHandler(): bool
    {
        return $this->risk_type === 'active' && ($this->handler_user_id || $this->handler_specialty);
    }

    /** المعالج كما يُعرض: معالج نسخة الفرع إن كُتب، وإلا من العام: اسم الشخص، أو اسم التخصص، أو لا شيء */
    public function getHandlerLabelAttribute(): ?string
    {
        $g = $this->hasOwnHandler() ? $this : $this->generalSource();
        if (!$g) return null;
        if ($g->handler_user_id) return $g->handlerUser?->name;
        if ($g->handler_specialty) return \App\Core\Permissions\PermissionRegistry::ROLES[$g->handler_specialty] ?? $g->handler_specialty;
        return null;
    }

    /** من كتب المعالج (في نسخة الفرع أو في العام)، ومتى */
    public function getHandlerSetByNameAttribute(): ?string
    {
        return ($this->hasOwnHandler() ? $this : $this->generalSource())?->handlerSetBy?->name;
    }

    public function getHandlerSetAtDateAttribute(): ?string
    {
        return ($this->hasOwnHandler() ? $this : $this->generalSource())?->handler_set_at?->format('Y-m-d');
    }

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
