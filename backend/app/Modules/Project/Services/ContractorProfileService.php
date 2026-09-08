<?php

namespace App\Modules\Project\Services;

use App\Modules\Project\Channels\EtimadChannel;
use App\Modules\Project\Channels\GosiChannel;
use App\Modules\Project\Channels\PdfUploadChannel;
use App\Modules\Project\Channels\PortalLinkChannel;
use App\Modules\Project\Models\ContractorChannel;
use App\Modules\Project\Models\ContractorProfile;
use App\Modules\Project\Models\ExternalParty;
use Illuminate\Support\Facades\DB;

/** ملف المقاول وقنوات التحقق (من OHSMS: صف قناة واحد لكل نوع بدل صف لكل مستأجر). */
class ContractorProfileService
{
    private array $registry;

    public function __construct(PdfUploadChannel $pdf, PortalLinkChannel $portal, EtimadChannel $etimad, GosiChannel $gosi)
    {
        $this->registry = [
            ContractorChannel::CHANNEL_PDF => $pdf,
            ContractorChannel::CHANNEL_PORTAL => $portal,
            ContractorChannel::CHANNEL_ETIMAD => $etimad,
            ContractorChannel::CHANNEL_GOSI => $gosi,
        ];
    }

    /** يمرّ على القنوات المفعّلة بترتيب الأولوية ويعيد عدد الحقول المحدَّثة. */
    public function enrich(ExternalParty $contractor): int
    {
        $totalUpdated = 0;
        foreach (ContractorChannel::enabledByType() as $type => $config) {
            $channel = $this->registry[$type] ?? null;
            if (!$channel || !$channel->isConfigured($config)) {
                continue;
            }
            $totalUpdated += count($channel->enrich($contractor, $config));
        }
        return $totalUpdated;
    }

    public function getOrCreateProfile(ExternalParty $contractor): ContractorProfile
    {
        return $contractor->profile ?? $contractor->profile()->create([]);
    }

    public function configureChannels(array $channelSettings, int $userId): void
    {
        DB::transaction(function () use ($channelSettings, $userId) {
            foreach ($channelSettings as $type => $settings) {
                if (!in_array($type, ContractorChannel::ALL_TYPES, true)) {
                    continue;
                }
                $attributes = [
                    'enabled' => $settings['enabled'] ?? false,
                    'priority' => $settings['priority'] ?? 50,
                    'created_by_id' => $userId,
                ];
                if (array_key_exists('config_json', $settings)) {
                    $attributes['config_json'] = $settings['config_json'];
                }
                ContractorChannel::updateOrCreate(['channel_type' => $type], $attributes);
            }
        });
    }

    public function getChannelConfig(): array
    {
        $existing = ContractorChannel::all()->keyBy('channel_type');
        $result = [];
        foreach (ContractorChannel::ALL_TYPES as $type) {
            $row = $existing[$type] ?? null;
            $result[$type] = [
                'channel_type' => $type,
                'label' => ContractorChannel::LABELS[$type] ?? $type,
                'enabled' => (bool) ($row?->enabled ?? false),
                'priority' => (int) ($row?->priority ?? 50),
                'config_json' => $row?->config_json,
                'is_configured' => $row ? $row->isConfigured() : in_array($type, [ContractorChannel::CHANNEL_PDF, ContractorChannel::CHANNEL_PORTAL], true),
            ];
        }
        return $result;
    }

    public function generatePortalToken(ExternalParty $contractor): ContractorProfile
    {
        return $this->registry[ContractorChannel::CHANNEL_PORTAL]->generateToken($contractor);
    }
}
