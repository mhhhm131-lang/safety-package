<?php

namespace App\Modules\Project\Services;

use App\Modules\Project\Models\ExternalParty;
use App\Modules\Project\Models\ProjectContractor;
use Carbon\Carbon;

/**
 * Automated pre-qualification checks for a contractor.
 *
 * Runs 8 checks against the contractor's profile and documents,
 * then computes a trust_score (0–100) that the safety manager can
 * use to make a quick go/no-go decision before contract signing.
 *
 * Score weights (totalling 100):
 *   CHECK 1  CR active + not expired          20 pts
 *   CHECK 2  Insurance active + not expired   20 pts
 *   CHECK 3  ISO cert valid                   15 pts
 *   CHECK 4  GOSI registered                  15 pts
 *   CHECK 5  Etimad not suspended             10 pts
 *   CHECK 6  Mandatory documents uploaded     10 pts
 *   CHECK 7  No open critical incidents        5 pts
 *   CHECK 8  Activity trade coverage           5 pts
 *
 * Checks 3, 4, 5 are skipped (score = full) when the relevant channel
 * is not enabled for the tenant. This prevents penalising a small firm
 * for not having Etimad (which they would never use).
 */
class ContractorPreQualificationService
{
    public const MANDATORY_DOC_TYPES = ['cr', 'insurance'];

    /**
     * Run all checks and return a structured result.
     *
     * @return array{
     *   score: int,
     *   label: string,
     *   checks: array<string, array{passed: bool, points: int, max: int, reason: string}>
     * }
     */
    public function evaluate(ExternalParty $contractor, ?ProjectContractor $assignment = null): array
    {
        $profile  = $contractor->profile;
        $enabledChannels = \App\Modules\Project\Models\ContractorChannel::enabledTypes();

        $checks = [];
        $score  = 0;

        // ── CHECK 1: CR active ──────────────────────────────────────────
        $crCheck = $this->checkCr($profile);
        $checks['cr_active'] = $crCheck;
        $score += $crCheck['points'];

        // ── CHECK 2: Insurance active ───────────────────────────────────
        $insCheck = $this->checkInsurance($profile);
        $checks['insurance_active'] = $insCheck;
        $score += $insCheck['points'];

        // ── CHECK 3: ISO cert (skip if muqawil/etimad not enabled) ──────
        $isoCheck = $this->checkIsoCert($profile, $enabledChannels);
        $checks['iso_cert'] = $isoCheck;
        $score += $isoCheck['points'];

        // ── CHECK 4: GOSI registered ────────────────────────────────────
        $gosiCheck = $this->checkGosi($profile, $enabledChannels);
        $checks['gosi_registered'] = $gosiCheck;
        $score += $gosiCheck['points'];

        // ── CHECK 5: Etimad not suspended ───────────────────────────────
        $etimadCheck = $this->checkEtimad($profile, $enabledChannels);
        $checks['etimad_active'] = $etimadCheck;
        $score += $etimadCheck['points'];

        // ── CHECK 6: Mandatory documents uploaded ───────────────────────
        $docCheck = $this->checkMandatoryDocs($contractor);
        $checks['docs_complete'] = $docCheck;
        $score += $docCheck['points'];

        // ── CHECK 7: No open critical incidents ─────────────────────────
        $incidentCheck = $this->checkIncidents($contractor);
        $checks['no_critical_incidents'] = $incidentCheck;
        $score += $incidentCheck['points'];

        // ── CHECK 8: Activity trade coverage ────────────────────────────
        $tradeCheck = $this->checkTradeCoverage($contractor, $assignment);
        $checks['trade_coverage'] = $tradeCheck;
        $score += $tradeCheck['points'];

        // Persist score
        if ($profile) {
            $profile->trust_score              = $score;
            $profile->trust_score_computed_at  = now();
            $profile->save();
        }

        return [
            'score'  => $score,
            'label'  => $this->label($score),
            'checks' => $checks,
        ];
    }

    // ────────────────────────────────────────────────────────────────────

    private function checkCr(?object $profile): array
    {
        $max = 20;
        if (!$profile || !$profile->cr_expiry_date) {
            return $this->fail($max, 'CR expiry date not recorded');
        }
        if ($profile->cr_expiry_date->isPast()) {
            return $this->fail($max, 'CR expired on ' . $profile->cr_expiry_date->toDateString());
        }
        $days = (int) now()->diffInDays($profile->cr_expiry_date, false);
        if ($days <= 30) {
            // Half points if expiring within 30 days
            return $this->partial($max, (int)($max / 2), "CR expiring in {$days} days");
        }
        return $this->pass($max, 'CR active');
    }

    private function checkInsurance(?object $profile): array
    {
        $max = 20;
        if (!$profile || !$profile->insurance_expiry_date) {
            return $this->fail($max, 'Insurance expiry date not recorded');
        }
        if ($profile->insurance_expiry_date->isPast()) {
            return $this->fail($max, 'Insurance expired on ' . $profile->insurance_expiry_date->toDateString());
        }
        $days = (int) now()->diffInDays($profile->insurance_expiry_date, false);
        if ($days <= 30) {
            return $this->partial($max, (int)($max / 2), "Insurance expiring in {$days} days");
        }
        return $this->pass($max, 'Insurance active');
    }

    private function checkIsoCert(?object $profile, array $channels): array
    {
        $max = 15;
        // If tenant doesn't verify ISO (neither muqawil nor etimad enabled), award full points
        $isoChannels = array_intersect($channels, ['muqawil', 'etimad']);
        if (empty($isoChannels)) {
            return $this->skipped($max, 'ISO channel not enabled');
        }
        if (!$profile || !$profile->iso_cert_number) {
            return $this->fail($max, 'ISO certificate not on record');
        }
        if ($profile->iso_cert_expiry_date && $profile->iso_cert_expiry_date->isPast()) {
            return $this->fail($max, 'ISO cert expired on ' . $profile->iso_cert_expiry_date->toDateString());
        }
        return $this->pass($max, 'ISO cert valid');
    }

    private function checkGosi(?object $profile, array $channels): array
    {
        $max = 15;
        if (!in_array('gosi', $channels)) {
            return $this->skipped($max, 'GOSI channel not enabled');
        }
        if (!$profile || !$profile->gosi_account_number) {
            return $this->fail($max, 'GOSI account number not verified');
        }
        // GOSI verification expires in 30 days
        $exp = $profile->gosi_verified_at;
        if (!$exp || $exp->addDays(30)->isPast()) {
            return $this->partial($max, (int)($max / 2), 'GOSI verification is stale (> 30 days)');
        }
        return $this->pass($max, 'GOSI registered and current');
    }

    private function checkEtimad(?object $profile, array $channels): array
    {
        $max = 10;
        if (!in_array('etimad', $channels)) {
            return $this->skipped($max, 'Etimad channel not enabled');
        }
        if (!$profile || $profile->etimad_active === null) {
            return $this->fail($max, 'Etimad status not verified');
        }
        if (!$profile->etimad_active) {
            return $this->fail($max, 'Contractor is suspended on Etimad');
        }
        return $this->pass($max, 'Active on Etimad');
    }

    private function checkMandatoryDocs(ExternalParty $contractor): array
    {
        $max      = 10;
        $uploaded = $contractor->documents()
            ->whereIn('document_type', self::MANDATORY_DOC_TYPES)
            ->pluck('document_type')
            ->unique()
            ->toArray();

        $missing = array_diff(self::MANDATORY_DOC_TYPES, $uploaded);
        if (!empty($missing)) {
            return $this->fail($max, 'Missing documents: ' . implode(', ', $missing));
        }
        return $this->pass($max, 'All mandatory documents uploaded');
    }

    private function checkIncidents(ExternalParty $contractor): array
    {
        $max = 5;
        // Check for open critical incidents linked to this contractor
        // المعهد: لا عمود severity في بلاغ الشاغل؛ «الحرج» = البلاغ العاجل المفتوح المربوط بالطرف
        $openCritical = \Illuminate\Support\Facades\DB::table('incidents')
            ->where('external_party_id', $contractor->id)
            ->where('incident_type', 'urgent')
            ->whereNotIn('status', ['closed', 'out_of_scope', 'cancelled'])
            ->count();

        if ($openCritical > 0) {
            return $this->fail($max, "{$openCritical} open critical incident(s)");
        }
        return $this->pass($max, 'No open critical incidents');
    }

    private function checkTradeCoverage(ExternalParty $contractor, ?ProjectContractor $assignment): array
    {
        $max = 5;
        // If no project assignment, skip (no activity context yet)
        if (!$assignment) {
            return $this->skipped($max, 'No project assignment — trade check skipped');
        }
        // Workers present is a basic proxy for trade coverage
        $workerCount = $contractor->workers()->count();
        if ($workerCount === 0) {
            return $this->fail($max, 'No workers assigned to contractor');
        }
        return $this->pass($max, "{$workerCount} worker(s) on record");
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function pass(int $max, string $reason): array
    {
        return ['passed' => true, 'points' => $max, 'max' => $max, 'reason' => $reason];
    }

    private function fail(int $max, string $reason): array
    {
        return ['passed' => false, 'points' => 0, 'max' => $max, 'reason' => $reason];
    }

    private function partial(int $max, int $points, string $reason): array
    {
        return ['passed' => false, 'points' => $points, 'max' => $max, 'reason' => $reason];
    }

    private function skipped(int $max, string $reason): array
    {
        return ['passed' => true, 'points' => $max, 'max' => $max, 'reason' => "Skipped: {$reason}"];
    }

    private function label(int $score): string
    {
        return match (true) {
            $score >= 80 => 'high',
            $score >= 50 => 'medium',
            default      => 'low',
        };
    }
}
