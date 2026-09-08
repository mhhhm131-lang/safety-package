<?php

namespace App\Modules\Project\Models;

use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Risk\Models\Risk;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** المشروع (من OHSMS بلا tenant/نشاط اقتصادي) + place_id: المكان الذي يُنفَّذ فيه (المعهد). */
class Project extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return \Database\Factories\ProjectFactory::new();
    }

    protected $fillable = [
        'name',
        'name_en',
        'code',
        'description',
        'organization_unit_id',
        'place_id',
        'status',
        'start_date',
        'end_date',
        'assigned_coordinator_id',
        'created_by_id',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    protected $attributes = [
        'status' => 'planning',
    ];

    // Relationships

    public const STATUSES = ['planning' => 'تخطيط', 'active' => 'نشط', 'on_hold' => 'معلّق', 'completed' => 'مكتمل', 'cancelled' => 'ملغى'];

    public function getStatusLabel(): string { return self::STATUSES[$this->status] ?? $this->status; }

    public function place(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Governance\Models\Place::class);
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'organization_unit_id');
    }

    public function assignedCoordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_coordinator_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * Contractors attached to this project (many-to-many via project_contractors
     * pivot, which also carries the qualification status). Use contractors()
     * when you need just the parties; use projectContractors() when you need
     * the pivot rows (status, role, dates).
     *
     * NOTE: the old externalParties() hasMany relation assumed a project_id
     * column on external_parties that never existed — it always returned
     * an empty set. Callers must migrate to contractors().
     */
    public function contractors(): BelongsToMany
    {
        // Deliberate: no ->using(ProjectContractor::class) because we
        // use ProjectContractor as a standalone model (queried directly
        // via projectContractors()) rather than a Pivot subclass. The
        // withPivot columns below expose status/role without coupling.
        return $this->belongsToMany(ExternalParty::class, 'project_contractors')
            ->withPivot([
                'role',
                'qualification_status',
                'pre_approved_at',
                'post_approved_at',
                'expires_at',
                'contract_start_date',
                'contract_end_date',
            ])
            ->withTimestamps();
    }

    public function projectContractors(): HasMany
    {
        return $this->hasMany(ProjectContractor::class);
    }


    public function risks(): HasMany
    {
        return $this->hasMany(Risk::class);
    }
}
