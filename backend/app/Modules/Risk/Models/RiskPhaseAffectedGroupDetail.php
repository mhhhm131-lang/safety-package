<?php

namespace App\Modules\Risk\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rich per-group impact data for a single risk phase — mirrors
 * RiskAffectedGroupDetail but scoped to the phase, so the same affected
 * group can carry different impact/scope/description across proactive,
 * operational, and response phases of the same risk.
 */
class RiskPhaseAffectedGroupDetail extends Model
{
    public $timestamps = false;

    protected $table = 'risk_phase_affected_group_details';

    protected $fillable = [
        'risk_phase_id',
        'affected_group_id',
        'impact',
        'rep_scope',
        'impact_description',
        'details',
        'cascading_effects',
    ];

    public function phase(): BelongsTo
    {
        return $this->belongsTo(RiskPhase::class, 'risk_phase_id');
    }

    public function affectedGroup(): BelongsTo
    {
        return $this->belongsTo(AffectedGroup::class);
    }

    public function getImpactColorAttribute(): string
    {
        return match($this->impact) {
            'critical' => 'danger',
            'high'     => 'warning',
            'medium'   => 'info',
            'low'      => 'success',
            default    => 'secondary',
        };
    }
}
