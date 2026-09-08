<?php

namespace App\Modules\Project\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractorProfile extends Model
{

    protected $fillable = [
        'external_party_id',
        'cr_expiry_date',
        'cr_verified_at',
        'insurance_policy_number',
        'insurance_provider',
        'insurance_expiry_date',
        'insurance_verified_at',
        'iso_cert_number',
        'iso_cert_expiry_date',
        'iso_cert_verified_at',
        'gosi_account_number',
        'gosi_verified_at',
        'etimad_entity_number',
        'etimad_verified_at',
        'etimad_active',
        'muqawil_classification',
        'muqawil_verified_at',
        'trust_score',
        'trust_score_computed_at',
        'portal_link_token',
        'portal_link_expires_at',
        'portal_link_used_at',
        'last_enriched_at',
        'last_enriched_channel',
    ];

    protected $casts = [
        'cr_expiry_date'           => 'date',
        'cr_verified_at'           => 'datetime',
        'insurance_expiry_date'    => 'date',
        'insurance_verified_at'    => 'datetime',
        'iso_cert_expiry_date'     => 'date',
        'iso_cert_verified_at'     => 'datetime',
        'gosi_verified_at'         => 'datetime',
        'etimad_verified_at'       => 'datetime',
        'etimad_active'            => 'boolean',
        'muqawil_verified_at'      => 'datetime',
        'trust_score_computed_at'  => 'datetime',
        'portal_link_expires_at'   => 'datetime',
        'portal_link_used_at'      => 'datetime',
        'last_enriched_at'         => 'datetime',
    ];

    public function externalParty(): BelongsTo
    {
        return $this->belongsTo(ExternalParty::class);
    }

    public function isCrActive(): bool
    {
        return $this->cr_expiry_date === null || $this->cr_expiry_date->isFuture();
    }

    public function isInsuranceActive(): bool
    {
        return $this->insurance_expiry_date === null || $this->insurance_expiry_date->isFuture();
    }

    public function isIsoValid(): bool
    {
        return $this->iso_cert_number !== null
            && ($this->iso_cert_expiry_date === null || $this->iso_cert_expiry_date->isFuture());
    }

    public function isPortalLinkActive(): bool
    {
        return $this->portal_link_token !== null
            && $this->portal_link_expires_at !== null
            && $this->portal_link_expires_at->isFuture()
            && $this->portal_link_used_at === null;
    }

    public function getTrustLabel(): string
    {
        return match (true) {
            $this->trust_score >= 80 => 'high',
            $this->trust_score >= 50 => 'medium',
            $this->trust_score !== null => 'low',
            default => 'unknown',
        };
    }
}
