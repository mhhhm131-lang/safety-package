<?php

namespace App\Modules\Worker\Models;

use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Project\Models\Project;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;

/**
 * عامل مقاول/مشروع (لا موظف معهد — قرار ٢٠٢٦-٠٩-٠٧). area النصي صار place_id (مكان العمل في المعهد).
 * دورة الحياة في WorkerService؛ الحالات نصوص.
 */
class Worker extends Model
{
    public const STATUSES = [
        'draft' => 'مسودة', 'submitted' => 'مقدَّم', 'induction' => 'تعريف', 'training' => 'تدريب', 'approved' => 'معتمد',
        'work_authorized' => 'مصرّح بالعمل', 'role_authorized' => 'مصرّح بالدور', 'blocked' => 'محظور', 'suspended' => 'موقوف',
    ];

    public function getStatusLabel(): string { return self::STATUSES[$this->status] ?? $this->status; }

    use HasFactory;

    protected static function newFactory()
    {
        return \Database\Factories\WorkerFactory::new();
    }

    protected $fillable = [
        'external_party_id',
        'project_id',
        'organization_unit_id',
        'full_name',
        'full_name_en',
        'national_id',
        'phone',
        'trade_id',
        'status',
        'blocked_reason',
        'medical_expiry',
        'iqama_expiry',
        'pin_hash',
        'place_id',
        'joined_date',
        'created_by_id',
    ];

    protected $casts = [
        'medical_expiry' => 'date',
        'iqama_expiry' => 'date',
        'joined_date' => 'date',
    ];

    protected $attributes = [
        'status' => 'draft',
    ];

    protected $hidden = [
        'pin_hash',
    ];

    // Relationships

    public function externalParty(): BelongsTo
    {
        return $this->belongsTo(ExternalParty::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Governance\Models\Place::class);
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(WorkerStatusEvent::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(WorkerDocument::class);
    }

    public function trainingRecords(): HasMany
    {
        return $this->hasMany(WorkerTrainingRecord::class);
    }

    // Methods

    public function setPin(string $pin): void
    {
        $this->pin_hash = Hash::make($pin);
    }

    public function checkPin(string $pin): bool
    {
        return Hash::check($pin, $this->pin_hash);
    }

    // Accessors

    public function getIsAuthorizedAttribute(): bool
    {
        return in_array($this->status, ['work_authorized', 'role_authorized', 'approved']);
    }

    public function getIsBlockedAttribute(): bool
    {
        return in_array($this->status, ['blocked', 'suspended']);
    }
}
