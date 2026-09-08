<?php

namespace App\Modules\Project\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractorVerification extends Model
{

    protected $fillable = [
        'external_party_id',
        'field_name',
        'field_value',
        'source_channel',
        'confidence_score',
        'verified_at',
        'expires_at',
        'verified_by_id',
        'raw_response',
    ];

    protected $casts = [
        'verified_at'      => 'datetime',
        'expires_at'       => 'datetime',
        'confidence_score' => 'integer',
        'raw_response'     => 'encrypted',
    ];

    public function externalParty(): BelongsTo
    {
        return $this->belongsTo(ExternalParty::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
