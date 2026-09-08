<?php

namespace App\Modules\Project\Channels;

use App\Modules\Project\Models\ContractorVerification;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Project\Models\ContractorChannel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GOSI channel — General Organization for Social Insurance.
 *
 * Verifies that the contractor is registered with GOSI and returns
 * the GOSI account number. Used to confirm that the contractor's
 * workers have social insurance coverage.
 *
 * config_json must contain: api_key, base_url
 * confidence_score = 80
 * Verification expires in 30 days (GOSI status can change frequently).
 */
class GosiChannel implements ContractorChannelInterface
{
    public function channelType(): string
    {
        return ContractorChannel::CHANNEL_GOSI;
    }

    public function isConfigured(ContractorChannel $config): bool
    {
        $cfg = $config->config_json ?? [];
        return !empty($cfg['api_key']) && !empty($cfg['base_url']);
    }

    public function enrich(ExternalParty $contractor, ContractorChannel $config): array
    {
        if (!$contractor->cr_number) {
            return [];
        }

        $cfg = $config->config_json ?? [];

        try {
            $response = Http::timeout(10)
                ->withToken($cfg['api_key'])
                ->get("{$cfg['base_url']}/establishment/verify", [
                    'cr_number' => $contractor->cr_number,
                ]);

            if (!$response->successful()) {
                Log::warning('GOSI lookup failed', [
                    'contractor_id' => $contractor->id,
                    'status'        => $response->status(),
                ]);
                return [];
            }

            $data    = $response->json();
            $profile = $contractor->profile ?? $contractor->profile()->create([]);

            $updated = [];
            $now     = now();
            $exp     = $now->copy()->addDays(30);

            $accountNumber = $data['account_number'] ?? $data['gosi_number'] ?? null;
            if ($accountNumber) {
                $profile->gosi_account_number = $accountNumber;
                $profile->gosi_verified_at    = $now;
                $updated[] = 'gosi_account_number';

                ContractorVerification::updateOrCreate(
                    [
                        'external_party_id' => $contractor->id,
                        'field_name'        => 'gosi_account_number',
                        'source_channel'    => $this->channelType(),
                    ],
                    [
                        'field_value'      => $accountNumber,
                        'confidence_score' => ContractorChannel::CONFIDENCE[$this->channelType()],
                        'verified_at'      => $now,
                        'expires_at'       => $exp,
                        'raw_response'     => json_encode($data),
                    ],
                );

                $profile->last_enriched_at     = $now;
                $profile->last_enriched_channel = $this->channelType();
                $profile->save();
            }

            return $updated;
        } catch (\Throwable $e) {
            Log::error('GOSI channel exception', [
                'contractor_id' => $contractor->id,
                'error'         => $e->getMessage(),
            ]);
            return [];
        }
    }
}
