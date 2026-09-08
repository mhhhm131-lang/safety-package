<?php

namespace App\Modules\Project\Models;

use App\Models\User;
use App\Modules\Risk\Models\Risk;
use App\Modules\Worker\Models\Worker;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** الطرف الخارجي: مقاول/مزود خدمة/مورد/استشاري (من OHSMS بلا tenant/نشاط اقتصادي). حسابات المقاول تُربط به عبر users.external_party_id. */
class ExternalParty extends Model
{
    public const TYPES = ['contractor' => 'مقاول', 'service_provider' => 'مزود خدمة', 'supplier' => 'مورّد', 'consultant' => 'استشاري'];
    public const STATUSES = ['active' => 'نشط', 'inactive' => 'غير نشط', 'blocked' => 'محظور', 'pending' => 'قيد التسجيل'];

    public function getTypeLabel(): string { return self::TYPES[$this->party_type] ?? $this->party_type; }
    public function getStatusLabel(): string { return self::STATUSES[$this->status] ?? $this->status; }

    public function users(): HasMany
    {
        return $this->hasMany(\App\Models\User::class, 'external_party_id');
    }

    use HasFactory;

    protected static function newFactory()
    {
        return \Database\Factories\ExternalPartyFactory::new();
    }

    protected $fillable = [
        'name',
        'name_en',
        'party_type',
        'contact_person',
        'email',
        'phone',
        'address',
        'cr_number',
        'website_url',
        'registration_url',
        'status',
        'notes',
        'created_by_id',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    // Relationships

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function risks(): BelongsToMany
    {
        return $this->belongsToMany(Risk::class, 'external_party_risks');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ExternalPartyDocument::class);
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(ExternalPartyEvaluation::class);
    }

    public function workers(): HasMany
    {
        return $this->hasMany(Worker::class);
    }

    /**
     * The party's assignments across all projects (pivot rows — carry
     * the per-project qualification status, role, dates). One party
     * can be post_approved on one project and suspended on another.
     */
    public function profile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ContractorProfile::class);
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(ContractorVerification::class);
    }

    public function projectAssignments(): HasMany
    {
        return $this->hasMany(ProjectContractor::class);
    }

    /**
     * Projects this party is attached to (shortcut that skips the pivot
     * access). Use projectAssignments() when pivot attributes matter.
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_contractors')
            ->withPivot(['role', 'qualification_status'])
            ->withTimestamps();
    }
}
