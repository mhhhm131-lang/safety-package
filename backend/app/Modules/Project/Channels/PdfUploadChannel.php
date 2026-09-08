<?php

namespace App\Modules\Project\Channels;

use App\Modules\Project\Models\ContractorVerification;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Project\Models\ContractorChannel;

/**
 * PDF Upload channel — always available, no external API.
 *
 * Data comes from documents the safety manager uploads manually via the
 * ExternalPartyDocument upload form. This channel does not call any
 * external service; instead it reads existing document records and maps
 * them to profile fields (e.g. a document_type='cr' with an expiry_date
 * → cr_expiry_date on the profile).
 *
 * confidence_score = 40 (human-uploaded, not platform-verified)
 */
class PdfUploadChannel implements ContractorChannelInterface
{
    public function channelType(): string
    {
        return ContractorChannel::CHANNEL_PDF;
    }

    public function isConfigured(ContractorChannel $config): bool
    {
        return true; // PDF upload needs no credentials
    }

    public function enrich(ExternalParty $contractor, ContractorChannel $config): array
    {
        $profile  = $contractor->profile ?? $contractor->profile()->create([]);

        $updated  = [];
        $docs     = $contractor->documents()
            ->where('is_verified', true)
            ->whereNotNull('expiry_date')
            ->get();

        $fieldMap = [
            'cr'           => 'cr_expiry_date',
            'insurance'    => 'insurance_expiry_date',
            'iso_cert'     => 'iso_cert_expiry_date',
        ];

        foreach ($docs as $doc) {
            $profileField = $fieldMap[$doc->document_type] ?? null;
            if (!$profileField) {
                continue;
            }

            // Only overwrite if our value is newer or current is null
            if ($profile->$profileField === null || $doc->expiry_date->gt($profile->$profileField)) {
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
                        'verified_at'      => now(),
                        'expires_at'       => null,
                    ],
                );
            }
        }

        if (!empty($updated)) {
            $profile->last_enriched_at      = now();
            $profile->last_enriched_channel  = $this->channelType();
            $profile->save();
        }

        return $updated;
    }
}
