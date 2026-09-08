<?php

namespace App\Modules\Project\Channels;

use App\Modules\Project\Models\ContractorVerification;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Project\Models\ContractorChannel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Etimad channel — Saudi government procurement platform.
 *
 * Primarily used by government-sector tenants. Verifies:
 *   - Entity registration status (active/suspended)
 *   - Etimad entity number
 *   - Whether contractor is eligible for government contracts
 *
 * config_json must contain: api_key, base_url
 * (stored encrypted in tenant_contractor_channels.config_json)
 *
 * confidence_score = 90 (highest — government platform)
 *
 * Graceful degradation: if the API is unreachable, logs the error
 * and returns empty array. The caller continues with other channels.
 */
class EtimadChannel implements ContractorChannelInterface
{
    public function channelType(): string
    {
        return ContractorChannel::CHANNEL_ETIMAD;
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
                ->get("{$cfg['base_url']}/entities/lookup", [
                    'cr_number' => $contractor->cr_number,
                ]);

            if (!$response->successful()) {
                Log::warning('Etimad lookup failed', [
                    'contractor_id' => $contractor->id,
                    'status'        => $response->status(),
                ]);
                return [];
            }

            $data    = $response->json();
            $profile = $contractor->profile ?? $contractor->profile()->create([]);

            $updated = [];
            $now     = now();
            $exp     = $now->copy()->addDays(90); // Etimad verifications expire in 90 days

            $fields = [
                'etimad_entity_number' => $data['entity_number'] ?? null,
                'etimad_active'        => isset($data['status']) ? ($data['status'] === 'active') : null,
            ];

            foreach ($fields as $field => $value) {
                if ($value === null) {
                    continue;
                }
                $profile->$field = $value;
                $updated[] = $field;

                ContractorVerification::updateOrCreate(
                    [
                        'external_party_id' => $contractor->id,
                        'field_name'        => $field,
                        'source_channel'    => $this->channelType(),
                    ],
                    [
                        'field_value'      => (string) $value,
                        'confidence_score' => ContractorChannel::CONFIDENCE[$this->channelType()],
                        'verified_at'      => $now,
                        'expires_at'       => $exp,
                        'raw_response'     => json_encode($data),
                    ],
                );
            }

            if (!empty($updated)) {
                $profile->etimad_verified_at    = $now;
                $profile->last_enriched_at      = $now;
                $profile->last_enriched_channel  = $this->channelType();
                $profile->save();
            }

            return $updated;
        } catch (\Throwable $e) {
            Log::error('Etimad channel exception', [
                'contractor_id' => $contractor->id,
                'error'         => $e->getMessage(),
            ]);
            return []; // graceful degradation
        }
    }
}
