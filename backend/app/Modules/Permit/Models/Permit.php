<?php

namespace App\Modules\Permit\Models;

use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Project\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * التصريح الموحّد — الفئات الخمس (تأهيل، عمل، عامل، معدة، خاص) في جدول واحد.
 *
 * كل تغيير حالة يمرّ بـ PermitService::transition حتى تُفرض آلة الحالة ويُكتب الحدث في المعاملة نفسها.
 * إضافة المعهد: `place_id` هو منطقة العمل (أحد الأماكن التسعة)، و`sub_location` الموضع الدقيق داخله.
 */
class Permit extends Model
{
    public const STATUS_DRAFT           = 'draft';
    public const STATUS_SUBMITTED       = 'submitted';
    public const STATUS_UNDER_REVIEW    = 'under_review';
    public const STATUS_SAFETY_APPROVED = 'safety_approved';
    public const STATUS_APPROVED        = 'approved';
    public const STATUS_CONDITIONAL     = 'conditional';
    public const STATUS_ACTIVE          = 'active';
    public const STATUS_COMPLETED       = 'completed';
    public const STATUS_EXPIRED         = 'expired';
    public const STATUS_CANCELLED       = 'cancelled';
    public const STATUS_REJECTED        = 'rejected';
    public const STATUS_SUSPENDED       = 'suspended';

    public const STATUS_LABELS = [
        self::STATUS_DRAFT           => 'مسودة',
        self::STATUS_SUBMITTED       => 'مقدَّم',
        self::STATUS_UNDER_REVIEW    => 'قيد المراجعة',
        self::STATUS_SAFETY_APPROVED => 'معتمد من السلامة',
        self::STATUS_APPROVED        => 'معتمد',
        self::STATUS_CONDITIONAL     => 'اعتماد مشروط',
        self::STATUS_ACTIVE          => 'نشط',
        self::STATUS_COMPLETED       => 'مكتمل',
        self::STATUS_EXPIRED         => 'منتهي الصلاحية',
        self::STATUS_CANCELLED       => 'ملغى',
        self::STATUS_REJECTED        => 'مرفوض',
        self::STATUS_SUSPENDED       => 'موقوف',
    ];

    public const SUBJECT_ORG_UNIT       = 'OrganizationUnit';
    public const SUBJECT_EXTERNAL_PARTY = 'ExternalParty';
    public const SUBJECT_WORKER         = 'Worker';
    public const SUBJECT_EQUIPMENT      = 'Equipment';
    public const SUBJECT_PROJECT        = 'Project';

    public const SCOPE_PROJECT         = 'project';
    public const SCOPE_CONTRACTOR_PRE  = 'contractor_pre';
    public const SCOPE_CONTRACTOR_POST = 'contractor_post';
    public const SCOPE_INDIVIDUAL      = 'individual';

    public const SCOPE_LABELS = [
        self::SCOPE_PROJECT         => 'تصريح المشروع',
        self::SCOPE_CONTRACTOR_PRE  => 'تأهيل المقاول (ما قبل التعاقد)',
        self::SCOPE_CONTRACTOR_POST => 'تصريح المقاول التشغيلي',
        self::SCOPE_INDIVIDUAL      => 'تصريح دخول الفرد',
    ];

    protected $fillable = [
        'permit_type_id', 'permit_category', 'scope', 'parent_permit_id', 'code', 'title', 'description',
        'project_id', 'external_party_id', 'organization_unit_id', 'place_id',
        'subject_type', 'subject_id', 'status', 'workers_count', 'equipment_count',
        'location_description', 'sub_location', 'precautions', 'additional_notes',
        'requester_name', 'requester_phone',
        'submitted_at', 'reviewed_at', 'safety_approved_at', 'safety_approved_by_id', 'approved_at',
        'starts_at', 'expires_at', 'requested_by_id', 'reviewed_by_id', 'approved_by_id',
        'metadata', 'activation_conditions', 'rejection_reason',
    ];

    protected $casts = [
        'submitted_at'          => 'datetime',
        'reviewed_at'           => 'datetime',
        'safety_approved_at'    => 'datetime',
        'approved_at'           => 'datetime',
        'starts_at'             => 'datetime',
        'expires_at'            => 'datetime',
        'metadata'              => 'array',
        'activation_conditions' => 'array',
    ];

    protected $attributes = ['status' => self::STATUS_DRAFT];

    // ── العلاقات ──

    public function type(): BelongsTo          { return $this->belongsTo(PermitType::class, 'permit_type_id'); }
    public function parent(): BelongsTo        { return $this->belongsTo(self::class, 'parent_permit_id'); }
    public function children(): HasMany        { return $this->hasMany(self::class, 'parent_permit_id'); }
    public function project(): BelongsTo       { return $this->belongsTo(Project::class); }
    public function externalParty(): BelongsTo { return $this->belongsTo(ExternalParty::class); }
    public function organizationUnit(): BelongsTo { return $this->belongsTo(OrganizationUnit::class); }
    public function place(): BelongsTo         { return $this->belongsTo(Place::class); }
    public function requestedBy(): BelongsTo   { return $this->belongsTo(User::class, 'requested_by_id'); }
    public function reviewedBy(): BelongsTo    { return $this->belongsTo(User::class, 'reviewed_by_id'); }
    public function approvedBy(): BelongsTo    { return $this->belongsTo(User::class, 'approved_by_id'); }
    public function safetyApprovedBy(): BelongsTo { return $this->belongsTo(User::class, 'safety_approved_by_id'); }

    public function requirements(): HasMany { return $this->hasMany(PermitRequirement::class); }
    public function events(): HasMany       { return $this->hasMany(PermitEvent::class)->latest('created_at'); }
    public function workers(): HasMany      { return $this->hasMany(PermitWorker::class); }
    public function deviations(): HasMany   { return $this->hasMany(PermitDeviation::class)->latest('recorded_at'); }
    public function attachments(): HasMany  { return $this->hasMany(PermitAttachment::class)->latest('created_at'); }
    public function incidents(): HasMany    { return $this->hasMany(\App\Modules\Incident\Models\Incident::class); }

    public function risks(): BelongsToMany
    {
        return $this->belongsToMany(\App\Modules\Risk\Models\Risk::class, 'permit_risks', 'permit_id', 'risk_id')
            ->withPivot(['auto_suggested', 'added_at', 'added_by_id', 'notes']);
    }

    public function trades(): BelongsToMany
    {
        return $this->belongsToMany(\App\Modules\Worker\Models\Trade::class, 'permit_trades', 'permit_id', 'trade_id');
    }

    /** موضوع التصريح (عامل أو معدة أو طرف…) — يُحمَّل عند الحاجة فقط. */
    public function subject(): ?Model
    {
        return match ($this->subject_type) {
            self::SUBJECT_WORKER         => \App\Modules\Worker\Models\Worker::find($this->subject_id),
            self::SUBJECT_EQUIPMENT      => \App\Modules\Permit\Models\Equipment::find($this->subject_id),
            self::SUBJECT_EXTERNAL_PARTY => ExternalParty::find($this->subject_id),
            self::SUBJECT_ORG_UNIT       => OrganizationUnit::find($this->subject_id),
            self::SUBJECT_PROJECT        => Project::find($this->subject_id),
            default                      => null,
        };
    }

    // ── حالات ──

    public function getStatusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getScopeLabel(): ?string
    {
        return $this->scope ? (self::SCOPE_LABELS[$this->scope] ?? $this->scope) : null;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED, self::STATUS_EXPIRED, self::STATUS_CANCELLED, self::STATUS_REJECTED,
        ], true);
    }

    /**
     * هل استوفى التصريح شروط التفعيل؟ حارس التفعيل في PermitService.
     *
     * **تصحيح لسلوك OHSMS:** يشترط اكتمال البنود الإلزامية **الاستباقية** (ما قبل البدء)
     * والبنود بلا طور (وثائق وتدريب)، ولا يشترط التشغيلية ولا بنود الاستجابة:
     * التشغيلية تُنفَّذ أثناء العمل («قياس الغاز كل ٣٠ دقيقة») وبنود الاستجابة عند الطارئ،
     * فاشتراط إتمامها قبل بدء العمل يمنع كل تصريح من التفعيل.
     * أي بند «لم يجتز» (قياس دون الحد) يمنع التفعيل في أي طور.
     */
    public function requirementsSatisfied(): bool
    {
        $open = $this->requirements()
            ->where('severity', PermitRequirement::SEVERITY_MANDATORY)
            ->whereNotIn('status', [PermitRequirement::STATUS_COMPLETED, PermitRequirement::STATUS_WAIVED])
            ->where(fn ($q) => $q
                ->whereIn('phase', ['preventive'])
                ->orWhereNull('phase')
                ->orWhere('status', PermitRequirement::STATUS_FAILED))
            ->exists();

        return !$open;
    }
}
