<?php

namespace App\Modules\Project\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pivot between a Project and an ExternalParty (contractor/supplier/
 * consultant) carrying the contractor's qualification state on that
 * specific project.
 *
 * A single ExternalParty can hold different ProjectContractor rows
 * across projects with independent statuses — a contractor may be
 * post_approved on Project A and suspended on Project B.
 *
 * State transitions are enforced by ProjectContractorStateMachine and
 * every transition is written to project_contractor_events for audit.
 */
/** ربط المقاول بالمشروع بحالة تأهيل (مسبق ← لاحق) وآلة حالة ProjectContractorStateMachine. */
class ProjectContractor extends Model
{
    public const ROLES = ['main' => 'مقاول رئيسي', 'sub' => 'مقاول فرعي', 'consultant' => 'استشاري', 'supplier' => 'مورّد'];
    public const STATUSES = [
        'draft' => 'مسودة', 'pre_review' => 'مراجعة التأهيل المسبق', 'pre_approved' => 'مؤهَّل مسبقاً', 'post_review' => 'مراجعة التأهيل اللاحق',
        'post_approved' => 'مؤهَّل — جاهز للعمل', 'suspended' => 'موقوف', 'expired' => 'منتهٍ',
    ];

    public function getStatusLabel(): string { return self::STATUSES[$this->qualification_status] ?? $this->qualification_status; }
    public function getRoleLabel(): string { return self::ROLES[$this->role] ?? $this->role; }

    use HasFactory;

    public const ROLE_MAIN = 'main';
    public const ROLE_SUB = 'sub';
    public const ROLE_CONSULTANT = 'consultant';
    public const ROLE_SUPPLIER = 'supplier';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PRE_REVIEW = 'pre_review';
    public const STATUS_PRE_APPROVED = 'pre_approved';
    public const STATUS_POST_REVIEW = 'post_review';
    public const STATUS_POST_APPROVED = 'post_approved';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'project_id',
        'external_party_id',
        'role',
        'activity_scope',
        'qualification_status',
        'pre_approved_at',
        'post_approved_at',
        'expires_at',
        'contract_start_date',
        'contract_end_date',
        'notes',
        'created_by_id',
    ];

    protected $casts = [
        'pre_approved_at' => 'datetime',
        'post_approved_at' => 'datetime',
        'expires_at' => 'date',
        'contract_start_date' => 'date',
        'contract_end_date' => 'date',
    ];

    protected $attributes = [
        'role' => self::ROLE_MAIN,
        'qualification_status' => self::STATUS_DRAFT,
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function externalParty(): BelongsTo
    {
        return $this->belongsTo(ExternalParty::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProjectContractorEvent::class)->latest('created_at');
    }

    /**
     * Whether this contractor may proceed to work — pre and post stages
     * both approved, not suspended, not expired.
     */
    public function isWorkReady(): bool
    {
        return $this->qualification_status === self::STATUS_POST_APPROVED;
    }

    /**
     * Approved at the pre-contract stage (company-level docs verified)
     * but may still need post-contract worker readiness.
     */
    public function isPreApproved(): bool
    {
        return in_array($this->qualification_status, [
            self::STATUS_PRE_APPROVED,
            self::STATUS_POST_REVIEW,
            self::STATUS_POST_APPROVED,
        ], true);
    }
}
