<?php

namespace App\Modules\Project\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit log row for ProjectContractor lifecycle events.
 *
 * Writes only — never updated, never deleted. Use the service layer
 * (ProjectContractorService::recordEvent) to create rows so the
 * payload shape stays consistent.
 */
class ProjectContractorEvent extends Model
{

    public const UPDATED_AT = null; // append-only

    protected $fillable = [
        'project_contractor_id',
        'event_type',
        'from_status',
        'to_status',
        'changes',
        'notes',
        'performed_by_id',
    ];

    protected $casts = [
        'changes' => 'array',
    ];

    public function projectContractor(): BelongsTo
    {
        return $this->belongsTo(ProjectContractor::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_id');
    }
}
