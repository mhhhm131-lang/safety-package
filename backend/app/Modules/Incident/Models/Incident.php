<?php

namespace App\Modules\Incident\Models;

use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Risk\Models\Risk;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * بلاغ الشاغل (Incident في OHSMS) — ليس بلاغ الفحص الفني (نموذج المكان).
 * منقول بلا tenant/external_party/project/permit. إضافات المعهد موثقة في الترحيل.
 */
class Incident extends Model
{
    // لا HasAuditLog: التدقيق صريح في الخدمات، والسري بلا سجل تدقيق (حماية الهوية — OHSMS)

    public const TYPES = ['normal' => 'عادي', 'urgent' => 'عاجل', 'secret' => 'سري'];

    public const STATUSES = [
        'new', 'received', 'referred', 'ref_received', 'forwarded',
        'field_received', 'in_progress', 'resolved',
        'escalated_to_coord', 'escalated_to_manager', 'closed', 'out_of_scope',
    ];

    public const STATUS_LABELS = [
        'new' => 'جديد',
        'received' => 'وصل المركز',
        'referred' => 'محال إلى المنسق',
        'ref_received' => 'استلمه المنسق',
        'forwarded' => 'حُوّل للفني',
        'field_received' => 'استلمه الفني',
        'in_progress' => 'جارٍ',
        'resolved' => 'عولج',
        'closed' => 'مغلق',
        'escalated_to_coord' => 'صُعّد للمنسق',
        'escalated_to_manager' => 'صُعّد للجنة السلامة',
        'out_of_scope' => 'خارج النطاق',
    ];

    public const STATUS_COLORS = [
        'new' => 'primary', 'received' => 'info', 'referred' => 'info', 'ref_received' => 'info', 'forwarded' => 'info',
        'field_received' => 'warning', 'in_progress' => 'warning', 'resolved' => 'success', 'closed' => 'dark',
        'escalated_to_coord' => 'danger', 'escalated_to_manager' => 'danger', 'out_of_scope' => 'secondary',
    ];

    /** الحالات التي لم يصل فيها البلاغ إلى الفني بعد (المهلة تُقاس حتى «استلمه الفني»). */
    public const BEFORE_FIELD = ['new', 'received', 'referred', 'ref_received', 'forwarded'];

    public const TERMINAL = ['closed', 'out_of_scope'];

    protected $fillable = [
        'code', 'title', 'description', 'incident_type', 'status', 'organization_unit_id', 'place_id', 'location_text',
        'actor_id', 'reporter_name', 'reporter_phone',
        'assigned_to_id', 'assigned_by_id', 'assigned_at', 'received_by_id', 'received_at', 'referred_at', 'ref_received_at',
        'forwarded_at', 'field_received_at', 'field_opened_at', 'in_progress_at', 'resolved_at', 'escalated_at', 'closed_at',
        'executor_id', 'risk_reference_id', 'risk_id', 'incident_coordinator_id', 'incident_field_team_id',
        'corrective_action', 'preventive_action', 'resolution_summary', 'escalation_reason', 'escalation_level',
        'coord_verified_at', 'coord_verified_by_id', 'pending_closure', 'reporter_approved_closure',
        'closure_requested_at', 'handled_at', 'secret_key', 'secret_tracking_code',
        'inspection_ref', 'deadline_at', 'overdue_at',
    ];

    protected $casts = [
        'pending_closure' => 'boolean', 'reporter_approved_closure' => 'boolean',
        'assigned_at' => 'datetime', 'received_at' => 'datetime', 'referred_at' => 'datetime', 'ref_received_at' => 'datetime',
        'forwarded_at' => 'datetime', 'field_received_at' => 'datetime', 'field_opened_at' => 'datetime', 'in_progress_at' => 'datetime', 'resolved_at' => 'datetime',
        'escalated_at' => 'datetime', 'closed_at' => 'datetime', 'closure_requested_at' => 'datetime', 'handled_at' => 'datetime',
        'coord_verified_at' => 'datetime', 'escalation_level' => 'integer', 'inspection_ref' => 'array',
        'deadline_at' => 'datetime', 'overdue_at' => 'datetime',
    ];

    protected $attributes = ['status' => 'new', 'pending_closure' => false, 'reporter_approved_closure' => false];

    protected static function booted(): void
    {
        static::creating(function (Incident $i) {
            if (empty($i->code)) {
                $next = (int) (static::max('id') ?? 0) + 1;
                $i->code = 'ش-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
            }
        });
    }

    public function auditLabel(): string
    {
        return $this->code.' '.$this->title;
    }

    // ── العلاقات ──
    public function organizationUnit(): BelongsTo { return $this->belongsTo(OrganizationUnit::class); }
    public function place(): BelongsTo { return $this->belongsTo(Place::class); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }
    public function assignedTo(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to_id'); }
    public function assignedBy(): BelongsTo { return $this->belongsTo(User::class, 'assigned_by_id'); }
    public function receivedBy(): BelongsTo { return $this->belongsTo(User::class, 'received_by_id'); }
    public function executor(): BelongsTo { return $this->belongsTo(User::class, 'executor_id'); }
    public function riskReference(): BelongsTo { return $this->belongsTo(Risk::class, 'risk_reference_id'); }
    /** الخطر الذي هذا البلاغ حالة منه (فعلي للمكان إن وُجد وإلا المرجعي). مصدر التوجيه والإجراءات. */
    public function risk(): BelongsTo { return $this->belongsTo(Risk::class, 'risk_id'); }
    public function incidentCoordinator(): BelongsTo { return $this->belongsTo(User::class, 'incident_coordinator_id'); }
    public function incidentFieldTeam(): BelongsTo { return $this->belongsTo(User::class, 'incident_field_team_id'); }
    public function coordVerifiedBy(): BelongsTo { return $this->belongsTo(User::class, 'coord_verified_by_id'); }
    public function risks(): BelongsToMany { return $this->belongsToMany(Risk::class, 'incident_risks'); }
    public function attachments(): HasMany { return $this->hasMany(IncidentAttachment::class); }
    public function events(): HasMany { return $this->hasMany(IncidentEvent::class); }
    public function notes(): HasMany { return $this->hasMany(IncidentEvent::class)->where('action', 'note')->latest(); }

    // ── مساعدات ──
    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->incident_type] ?? $this->incident_type;
    }

    public function isSecret(): bool
    {
        return $this->incident_type === 'secret';
    }

    /**
     * قرار المستخدم (٢٠٢٦-٠٩-١٣): «العادي لا يُغلق إلا بموافقتك» — للمبلّغ بحساب أو برمز التتبع سواء.
     * السري لا يمنح حق المطالبة، فيُغلق بتحقق شخص غير المنفّذ.
     */
    public function needsReporterApproval(): bool
    {
        return in_array($this->incident_type, ['normal', 'urgent'], true) && ($this->actor_id !== null || $this->secret_tracking_code);
    }

    /** هل تجاوز المهلة (البند ج) ولم يصل الفني بعد؟ */
    public function isOverdue(): bool
    {
        return $this->deadline_at && in_array($this->status, self::BEFORE_FIELD, true) && $this->deadline_at->isPast();
    }

    /** آخر ملاحظة من المركز (إحالة أو ملاحظة) لعرضها في شريط النموذج. */
    public function latestCenterNote(): string
    {
        $e = $this->events()->whereIn('action', ['assign', 'note'])->whereNotNull('actor_id')->latest('id')->first();
        return $e?->note ?? '';
    }

    /** اسم المبلّغ للعرض: بحساب، أو بلا حساب، أو مخفي (سري). */
    public function reporterDisplay(): string
    {
        if ($this->isSecret()) return 'مخفي (بلاغ سري)';
        if ($this->actor) return $this->actor->name;
        return $this->reporter_name ?: 'شاغل بلا اسم';
    }
}
