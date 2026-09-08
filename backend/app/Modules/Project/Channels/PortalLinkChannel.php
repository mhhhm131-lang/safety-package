<?php

namespace App\Modules\Project\Channels;

use App\Modules\Project\Models\ContractorProfile;
use App\Modules\Project\Models\ContractorVerification;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Project\Models\ContractorChannel;
use Illuminate\Support\Str;

/**
 * Portal Link channel — contractor fills their own data.
 *
 * The safety manager clicks "Send self-service link" → system generates a
 * signed token stored in contractor_profiles.portal_link_token, then
 * emails the link to the contractor. The contractor opens
 * /contractor-portal/{token} and uploads their documents directly.
 *
 * This channel's enrich() just checks whether the contractor has already
 * submitted via the portal (portal_link_used_at is set) and marks
 * documents submitted through that portal link as source_channel=portal_link.
 *
 * confidence_score = 50 (contractor self-reported, not platform-verified)
 *
 * Token generation is separated into generateToken() so the controller
 * can call it without triggering a full enrich cycle.
 */
class PortalLinkChannel implements ContractorChannelInterface
{
    public function channelType(): string
    {
        return ContractorChannel::CHANNEL_PORTAL;
    }

    public function isConfigured(ContractorChannel $config): bool
    {
        return true; // portal link needs no external credentials
    }

    /**
     * Generate (or regenerate) the self-service portal token.
     * Expires in 7 days. Existing unused token is overwritten.
     */
    public function generateToken(ExternalParty $contractor): ContractorProfile
    {
        $profile = $contractor->profile ?? $contractor->profile()->create([]);

        $profile->portal_link_token    = Str::random(48);
        $profile->portal_link_expires_at = now()->addDays(7);
        $profile->portal_link_used_at  = null;
        $profile->save();

        return $profile;
    }

    public function enrich(ExternalParty $contractor, ContractorChannel $config): array
    {
        $profile = $contractor->profile;
        if (!$profile || $profile->portal_link_used_at === null) {
            return []; // contractor hasn't submitted via portal yet
        }

        $updated = [];

        // Promote portal-submitted documents to verification records
        $portalDocs = $contractor->documents()
            ->where('source_channel', $this->channelType())
            ->whereNotNull('expiry_date')
            ->get();

        $fieldMap = [
            'cr'        => 'cr_expiry_date',
            'insurance' => 'insurance_expiry_date',
            'iso_cert'  => 'iso_cert_expiry_date',
        ];

        foreach ($portalDocs as $doc) {
            $profileField = $fieldMap[$doc->document_type] ?? null;
            if (!$profileField) {
                continue;
            }

            if ($profile->$profileField === null) {
                $profile->$profileField = $doc->expiry_date;
                $updated[] = $profileField;

                ContractorVerification::updateOrCreate(
                    [
                        'external_party_id' => $contractor->id,
                        'field_name'        => $profileField,
                        'source_channel'    => $this->channelType(),
                    ],
                    [
                        'field_value'      => $doc->expiry_date->toDateString(),
                        'confidence_score' => ContractorChannel::CONFIDENCE[$this->channelType()],
                        'verified_at'      => $profile->portal_link_used_at,
                        'expires_at'       => null,
                    ],
                );
            }
        }

        if (!empty($updated)) {
            $profile->last_enriched_at     = now();
            $profile->last_enriched_channel = $this->channelType();
            $profile->save();
        }

        return $updated;
    }
}
