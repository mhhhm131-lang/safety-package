<?php

namespace App\Modules\Project\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalPartyEvaluation extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'external_party_id',
        'project_id',
        'period_from',
        'period_to',
        'safety_score',
        'quality_score',
        'compliance_score',
        'overall_score',
        'notes',
        'status',
        'evaluated_by_id',
        'approved_by_id',
    ];

    protected $casts = [
        'period_from' => 'date',
        'period_to' => 'date',
        'safety_score' => 'integer',
        'quality_score' => 'integer',
        'compliance_score' => 'integer',
        'overall_score' => 'decimal:1',
    ];

    protected $attributes = [
        'status' => 'draft',
    ];

    protected static function booted(): void
    {
        static::creating(function (ExternalPartyEvaluation $evaluation) {
            $evaluation->overall_score = round(
                ($evaluation->safety_score + $evaluation->quality_score + $evaluation->compliance_score) / 3,
                1
            );
        });

        static::updating(function (ExternalPartyEvaluation $evaluation) {
            $evaluation->overall_score = round(
                ($evaluation->safety_score + $evaluation->quality_score + $evaluation->compliance_score) / 3,
                1
            );
        });
    }

    // Relationships

    public function externalParty(): BelongsTo
    {
        return $this->belongsTo(ExternalParty::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function evaluatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluated_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }
}
