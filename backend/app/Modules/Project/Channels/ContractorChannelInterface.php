<?php

namespace App\Modules\Project\Channels;

use App\Modules\Project\Models\ExternalParty;
use App\Modules\Project\Models\ContractorChannel;

/**
 * Contract for all contractor data-source channels.
 *
 * Each channel knows how to:
 *  1. Check if it is usable for a given tenant config.
 *  2. Look up a contractor by CR number (or other identifier) and
 *     return a structured VerificationResult.
 *  3. Report its channel type string.
 *
 * The caller (ContractorProfileService) iterates over enabled channels
 * in priority order and calls enrich(). Each channel writes what it
 * knows; gaps are left as null. The service merges all results.
 *
 * Channels MUST be idempotent — calling enrich() twice on the same
 * contractor should not create duplicate verifications.
 */
interface ContractorChannelInterface
{
    public function channelType(): string;

    /** Returns true when the tenant has provided enough config to use this channel. */
    public function isConfigured(ContractorChannel $config): bool;

    /**
     * Enrich the contractor's profile using this channel.
     *
     * Implementations should:
     *   - Call external API / parse uploaded file / read DB
     *   - Write one ContractorVerification row per field discovered
     *   - Update ContractorProfile with verified values
     *   - Return an array of field names that were updated
     *
     * @return string[]  list of field names updated (empty if nothing found)
     */
    public function enrich(ExternalParty $contractor, ContractorChannel $config): array;
}
