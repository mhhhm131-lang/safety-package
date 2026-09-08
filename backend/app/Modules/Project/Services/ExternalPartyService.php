<?php

namespace App\Modules\Project\Services;

use App\Modules\Project\Models\ExternalParty;
use App\Modules\Project\Models\ExternalPartyDocument;
use App\Modules\Project\Models\ExternalPartyEvaluation;

/** الأطراف الخارجية (من OHSMS بلا tenant). */
class ExternalPartyService
{
    public function getList()
    {
        return ExternalParty::latest()->paginate(20);
    }

    public function create(int $userId, array $data): ExternalParty
    {
        $data['created_by_id'] = $userId;
        return ExternalParty::create($data);
    }

    public function update(ExternalParty $party, array $data): ExternalParty
    {
        $party->update($data);
        return $party->fresh();
    }

    public function getDetail(ExternalParty $party): ExternalParty
    {
        $party->load(['documents', 'evaluations', 'workers', 'profile', 'projectAssignments.project']);
        return $party;
    }

    public function addDocument(ExternalParty $party, int $userId, array $data): ExternalPartyDocument
    {
        $data['external_party_id'] = $party->id;
        $data['uploaded_by_id'] = $userId;
        return ExternalPartyDocument::create($data);
    }

    public function createEvaluation(ExternalParty $party, int $userId, array $data): ExternalPartyEvaluation
    {
        $data['external_party_id'] = $party->id;
        $data['evaluated_by_id'] = $userId;
        $data['overall_score'] = round((($data['safety_score'] ?? 0) + ($data['quality_score'] ?? 0) + ($data['compliance_score'] ?? 0)) / 3, 1);
        return ExternalPartyEvaluation::create($data);
    }

    public function linkRisk(ExternalParty $party, int $riskId): void
    {
        $party->risks()->syncWithoutDetaching([$riskId]);
    }
}
