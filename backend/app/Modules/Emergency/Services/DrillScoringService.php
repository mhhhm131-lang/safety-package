<?php

namespace App\Modules\Emergency\Services;

use App\Modules\Emergency\Models\EvacuationDrill;
use App\Modules\Emergency\Models\EmergencyEventLog;
use App\Modules\Emergency\Models\EmergencyIncident;
use Illuminate\Support\Collection;

/**
 * Drill Scoring Service - OSHA/NFPA compliant drill evaluation
 *
 * Standards:
 * - OSHA 1910.38: Emergency Action Plans
 * - NFPA 101: Life Safety Code (evacuation time benchmarks)
 * - NFPA 1600: Disaster/Emergency Management
 */
class DrillScoringService
{
    // NFPA 101 recommended evacuation times (seconds)
    const EVACUATION_BENCHMARKS = [
        'small' => 180,      // < 100 occupants
        'medium' => 300,     // 100-500 occupants
        'large' => 480,      // 500-1000 occupants
        'high_rise' => 600,  // Multi-floor
    ];

    // Scoring weights
    const WEIGHTS = [
        'evacuation_time' => 25,
        'participation_rate' => 20,
        'headcount_accuracy' => 15,
        'assembly_compliance' => 15,
        'notification_speed' => 10,
        'team_response' => 10,
        'documentation' => 5,
    ];

    /**
     * Calculate comprehensive drill score
     */
    public function scoreDrill(EvacuationDrill $drill, ?EmergencyIncident $incident = null): array
    {
        $metrics = $this->collectMetrics($drill, $incident);
        $scores = $this->calculateCategoryScores($metrics);
        $totalScore = $this->calculateTotalScore($scores);
        $grade = $this->determineGrade($totalScore);
        $compliance = $this->assessCompliance($metrics);

        return [
            'drill_id' => $drill->id,
            'building_id' => $drill->building_id,
            'drill_type' => $drill->drill_type,
            'drill_date' => $drill->scheduled_at?->toDateString(),
            'metrics' => $metrics,
            'category_scores' => $scores,
            'total_score' => round($totalScore, 1),
            'grade' => $grade,
            'compliance' => $compliance,
            'strengths' => $this->identifyStrengths($scores),
            'improvements' => $this->identifyImprovements($scores),
            'nfpa_compliance' => $this->checkNfpaCompliance($metrics),
            'osha_compliance' => $this->checkOshaCompliance($metrics),
            'recommendations' => $this->generateRecommendations($metrics, $scores),
            'trend' => $this->calculateTrend($drill),
            'calculated_at' => now()->toISOString(),
        ];
    }

    /**
     * Collect drill metrics from incident data
     */
    protected function collectMetrics(EvacuationDrill $drill, ?EmergencyIncident $incident): array
    {
        $incident = $incident ?? $drill->incident;
        $building = $drill->building;

        $evacuationTime = 0;
        $participationRate = 0;
        $headcountAccuracy = 100;
        $assemblyCompliance = 0;
        $notificationSpeed = 0;
        $teamResponseTime = 0;

        $actualCount = 0; $accountedFor = 0; $missing = 0; $atAssembly = 0;
        if ($incident) {
            // زمن الإخلاء: من التفعيل إلى انتهاء الخطر
            $end = $incident->ended_at ?? $drill->ended_at;
            if ($incident->triggered_at && $end) {
                $evacuationTime = (int) abs($incident->triggered_at->diffInSeconds($end));
            }
            $stats = $incident->getStats();
            $expectedCount = $drill->expected_participants ?: ($building->total_capacity ?: max(1, $stats['total']));
            $actualCount = $stats['total'];
            $participationRate = min(100, ($actualCount / max(1, $expectedCount)) * 100);
            $accountedFor = $stats['safe'];
            $missing = $stats['missing'];
            if ($actualCount > 0) {
                $headcountAccuracy = ($accountedFor / $actualCount) * 100;
            }
            $atAssembly = $incident->checkIns()->where('status', 'safe')->whereNotNull('assembly_point_id')->count();
            if ($actualCount > 0) {
                $assemblyCompliance = ($atAssembly / $actualCount) * 100;
            }
            $first = $incident->eventLogs()->where('event_type', EmergencyEventLog::TYPE_TEAM_NOTIFIED)->orderBy('logged_at')->first();
            if ($first) {
                $notificationSpeed = (int) abs($incident->triggered_at->diffInSeconds($first->logged_at));
            }
            $teamLog = $incident->eventLogs()->where('event_type', EmergencyEventLog::TYPE_TEAM_ARRIVED)->orderBy('logged_at')->first();
            if ($teamLog) {
                $teamResponseTime = (int) abs($incident->triggered_at->diffInSeconds($teamLog->logged_at));
            }
        }

        return [
            'evacuation_time_seconds' => $evacuationTime,
            'evacuation_benchmark' => $this->getEvacuationBenchmark($building),
            'participation_rate' => round($participationRate, 1),
            'headcount_accuracy' => round($headcountAccuracy, 1),
            'assembly_compliance' => round($assemblyCompliance, 1),
            'notification_speed_seconds' => $notificationSpeed,
            'team_response_seconds' => $teamResponseTime,
            'expected_occupancy' => $drill->expected_participants ?? ($building->total_capacity ?? 0),
            'actual_participants' => $actualCount,
            'accounted_for' => $accountedFor,
            'missing_count' => $missing,
            'building_type' => $building->building_type ?? 'standard',
            'floor_count' => $building->getTotalFloors(),
            'has_documentation' => $drill->observations !== null || $incident?->final_report !== null,
        ];
    }

    /**
     * Calculate individual category scores (0-100)
     */
    protected function calculateCategoryScores(array $metrics): array
    {
        $scores = [];

        // Evacuation Time Score
        $benchmark = $metrics['evacuation_benchmark'];
        $actual = $metrics['evacuation_time_seconds'];
        if ($actual > 0 && $benchmark > 0) {
            $ratio = $actual / $benchmark;
            if ($ratio <= 0.8) $scores['evacuation_time'] = 100;
            elseif ($ratio <= 1.0) $scores['evacuation_time'] = 90;
            elseif ($ratio <= 1.2) $scores['evacuation_time'] = 75;
            elseif ($ratio <= 1.5) $scores['evacuation_time'] = 60;
            elseif ($ratio <= 2.0) $scores['evacuation_time'] = 40;
            else $scores['evacuation_time'] = max(0, 100 - ($ratio * 25));
        } else {
            $scores['evacuation_time'] = 50; // No data
        }

        // Participation Rate Score
        $scores['participation_rate'] = $this->scorePercentage($metrics['participation_rate'], [
            100 => 95, 90 => 85, 75 => 75, 60 => 50, 50 => 0
        ]);

        // Headcount Accuracy Score
        $scores['headcount_accuracy'] = $this->scorePercentage($metrics['headcount_accuracy'], [
            100 => 100, 98 => 95, 95 => 85, 90 => 75, 80 => 50
        ]);

        // Assembly Compliance Score
        $scores['assembly_compliance'] = $this->scorePercentage($metrics['assembly_compliance'], [
            100 => 95, 90 => 85, 75 => 70, 60 => 50
        ]);

        // Notification Speed Score (target: < 30 seconds)
        $notifSpeed = $metrics['notification_speed_seconds'];
        if ($notifSpeed === 0) $scores['notification_speed'] = 50;
        elseif ($notifSpeed <= 15) $scores['notification_speed'] = 100;
        elseif ($notifSpeed <= 30) $scores['notification_speed'] = 90;
        elseif ($notifSpeed <= 60) $scores['notification_speed'] = 75;
        elseif ($notifSpeed <= 120) $scores['notification_speed'] = 50;
        else $scores['notification_speed'] = 25;

        // Team Response Score (target: < 60 seconds)
        $teamTime = $metrics['team_response_seconds'];
        if ($teamTime === 0) $scores['team_response'] = 50;
        elseif ($teamTime <= 30) $scores['team_response'] = 100;
        elseif ($teamTime <= 60) $scores['team_response'] = 90;
        elseif ($teamTime <= 120) $scores['team_response'] = 75;
        elseif ($teamTime <= 180) $scores['team_response'] = 50;
        else $scores['team_response'] = 25;

        // Documentation Score
        $scores['documentation'] = $metrics['has_documentation'] ? 100 : 0;

        return $scores;
    }

    protected function scorePercentage(float $value, array $thresholds): float
    {
        foreach ($thresholds as $score => $threshold) {
            if ($value >= $threshold) {
                return $score;
            }
        }
        return 0;
    }

    /**
     * Calculate weighted total score
     */
    protected function calculateTotalScore(array $scores): float
    {
        $total = 0;
        $weightSum = 0;

        foreach (self::WEIGHTS as $category => $weight) {
            if (isset($scores[$category])) {
                $total += $scores[$category] * ($weight / 100);
                $weightSum += $weight;
            }
        }

        return $weightSum > 0 ? ($total / $weightSum) * 100 : 0;
    }

    /**
     * Determine letter grade
     */
    protected function determineGrade(float $score): array
    {
        if ($score >= 95) return ['grade' => 'A+', 'label' => 'Exceptional', 'color' => 'green'];
        if ($score >= 90) return ['grade' => 'A', 'label' => 'Excellent', 'color' => 'green'];
        if ($score >= 85) return ['grade' => 'A-', 'label' => 'Very Good', 'color' => 'green'];
        if ($score >= 80) return ['grade' => 'B+', 'label' => 'Good', 'color' => 'blue'];
        if ($score >= 75) return ['grade' => 'B', 'label' => 'Satisfactory', 'color' => 'blue'];
        if ($score >= 70) return ['grade' => 'B-', 'label' => 'Acceptable', 'color' => 'blue'];
        if ($score >= 65) return ['grade' => 'C+', 'label' => 'Needs Improvement', 'color' => 'yellow'];
        if ($score >= 60) return ['grade' => 'C', 'label' => 'Below Standard', 'color' => 'yellow'];
        if ($score >= 55) return ['grade' => 'C-', 'label' => 'Poor', 'color' => 'orange'];
        if ($score >= 50) return ['grade' => 'D', 'label' => 'Failing', 'color' => 'red'];
        return ['grade' => 'F', 'label' => 'Critical', 'color' => 'red'];
    }

    /**
     * Assess regulatory compliance
     */
    protected function assessCompliance(array $metrics): array
    {
        return [
            'osha_compliant' => $this->checkOshaCompliance($metrics)['compliant'],
            'nfpa_compliant' => $this->checkNfpaCompliance($metrics)['compliant'],
            'overall' => $this->checkOshaCompliance($metrics)['compliant']
                && $this->checkNfpaCompliance($metrics)['compliant'],
        ];
    }

    /**
     * Check OSHA 1910.38 compliance
     */
    protected function checkOshaCompliance(array $metrics): array
    {
        $violations = [];

        // OSHA requires annual evacuation drills
        // OSHA requires all employees know evacuation routes
        // OSHA requires designated assembly areas

        if ($metrics['participation_rate'] < 75) {
            $violations[] = 'Insufficient participation rate (OSHA requires all employees)';
        }

        if ($metrics['headcount_accuracy'] < 90) {
            $violations[] = 'Inadequate accountability system (OSHA 1910.38(c))';
        }

        if ($metrics['assembly_compliance'] < 70) {
            $violations[] = 'Assembly point compliance below standard (OSHA 1910.38(c)(4))';
        }

        return [
            'compliant' => empty($violations),
            'violations' => $violations,
            'standard' => 'OSHA 1910.38 - Emergency Action Plans',
        ];
    }

    /**
     * Check NFPA compliance
     */
    protected function checkNfpaCompliance(array $metrics): array
    {
        $violations = [];
        $benchmark = $metrics['evacuation_benchmark'];
        $actual = $metrics['evacuation_time_seconds'];

        // NFPA 101 requires evacuation within code-specified time
        if ($actual > 0 && $actual > $benchmark * 1.5) {
            $violations[] = sprintf(
                'Evacuation time (%ds) exceeds NFPA 101 benchmark (%ds) by >50%%',
                $actual,
                $benchmark
            );
        }

        // NFPA 1600 requires documented drills
        if (!$metrics['has_documentation']) {
            $violations[] = 'Drill not documented (NFPA 1600 Section 6.8)';
        }

        return [
            'compliant' => empty($violations),
            'violations' => $violations,
            'standard' => 'NFPA 101/1600 - Life Safety & Emergency Management',
        ];
    }

    /**
     * Identify top performing areas
     */
    protected function identifyStrengths(array $scores): array
    {
        $strengths = [];
        foreach ($scores as $category => $score) {
            if ($score >= 85) {
                $strengths[] = [
                    'category' => $this->getCategoryLabel($category),
                    'score' => $score,
                ];
            }
        }
        usort($strengths, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($strengths, 0, 3);
    }

    /**
     * Identify areas needing improvement
     */
    protected function identifyImprovements(array $scores): array
    {
        $improvements = [];
        foreach ($scores as $category => $score) {
            if ($score < 70) {
                $improvements[] = [
                    'category' => $this->getCategoryLabel($category),
                    'score' => $score,
                    'priority' => $score < 50 ? 'high' : 'medium',
                ];
            }
        }
        usort($improvements, fn($a, $b) => $a['score'] <=> $b['score']);
        return $improvements;
    }

    /**
     * Generate actionable recommendations
     */
    protected function generateRecommendations(array $metrics, array $scores): array
    {
        $recommendations = [];

        if ($scores['evacuation_time'] < 75) {
            $recommendations[] = [
                'priority' => 'high',
                'category' => 'Evacuation Time',
                'action' => 'Review and optimize evacuation routes. Consider additional training on exit locations.',
                'target' => sprintf('Reduce evacuation time to under %d seconds', $metrics['evacuation_benchmark']),
            ];
        }

        if ($scores['participation_rate'] < 80) {
            $recommendations[] = [
                'priority' => 'high',
                'category' => 'Participation',
                'action' => 'Ensure all employees receive drill notifications. Schedule drills during peak occupancy.',
                'target' => 'Achieve 95%+ participation in next drill',
            ];
        }

        if ($scores['headcount_accuracy'] < 85) {
            $recommendations[] = [
                'priority' => 'high',
                'category' => 'Accountability',
                'action' => 'Implement QR check-in system or improve floor warden headcount process.',
                'target' => '100% accountability within 5 minutes of evacuation',
            ];
        }

        if ($scores['assembly_compliance'] < 80) {
            $recommendations[] = [
                'priority' => 'medium',
                'category' => 'Assembly Points',
                'action' => 'Re-mark assembly points, update signage, include in orientation training.',
                'target' => '90%+ personnel at designated assembly points',
            ];
        }

        if ($scores['notification_speed'] < 75) {
            $recommendations[] = [
                'priority' => 'medium',
                'category' => 'Alert Speed',
                'action' => 'Test notification systems monthly. Consider redundant alert channels.',
                'target' => 'First notification within 15 seconds of alarm activation',
            ];
        }

        if ($scores['team_response'] < 75) {
            $recommendations[] = [
                'priority' => 'medium',
                'category' => 'Team Response',
                'action' => 'Conduct tabletop exercises with emergency team. Review role assignments.',
                'target' => 'Emergency team deployed within 60 seconds',
            ];
        }

        if (!$metrics['has_documentation']) {
            $recommendations[] = [
                'priority' => 'low',
                'category' => 'Documentation',
                'action' => 'Complete drill report with observations, timing data, and lessons learned.',
                'target' => 'Documented drill reports for all exercises',
            ];
        }

        return $recommendations;
    }

    /**
     * Calculate performance trend vs previous drills
     */
    protected function calculateTrend(EvacuationDrill $drill): array
    {
        $previousDrills = EvacuationDrill::where('building_id', $drill->building_id)
            ->where('id', '!=', $drill->id)
            ->where('status', 'completed')
            ->orderBy('scheduled_at', 'desc')
            ->limit(5)
            ->get();

        if ($previousDrills->isEmpty()) {
            return [
                'available' => false,
                'message' => 'No previous drill data for comparison',
            ];
        }

        // Calculate average previous score (simplified)
        $currentDuration = $drill->evacuation_time_sec ? round($drill->evacuation_time_sec / 60, 1) : 0;
        $previousAvg = $previousDrills->avg(fn ($d) => $d->evacuation_time_sec ? $d->evacuation_time_sec / 60 : null) ?? 0;

        if ($previousAvg === 0 || $currentDuration === 0) {
            return [
                'available' => false,
                'message' => 'Insufficient timing data',
            ];
        }

        $change = (($previousAvg - $currentDuration) / $previousAvg) * 100;

        return [
            'available' => true,
            'direction' => $change > 0 ? 'improving' : ($change < 0 ? 'declining' : 'stable'),
            'change_percent' => round(abs($change), 1),
            'previous_drills' => $previousDrills->count(),
            'previous_avg_duration' => round($previousAvg, 1),
            'current_duration' => $currentDuration,
        ];
    }

    protected function getEvacuationBenchmark($building): int
    {
        $occupancy = $building->total_capacity ?? 100;
        $floors = $building->getTotalFloors();

        if ($floors > 3) {
            return self::EVACUATION_BENCHMARKS['high_rise'];
        }
        if ($occupancy > 500) {
            return self::EVACUATION_BENCHMARKS['large'];
        }
        if ($occupancy > 100) {
            return self::EVACUATION_BENCHMARKS['medium'];
        }
        return self::EVACUATION_BENCHMARKS['small'];
    }

    protected function getCategoryLabel(string $category): string
    {
        $labels = [
            'evacuation_time' => 'Evacuation Time',
            'participation_rate' => 'Participation Rate',
            'headcount_accuracy' => 'Headcount Accuracy',
            'assembly_compliance' => 'Assembly Point Compliance',
            'notification_speed' => 'Notification Speed',
            'team_response' => 'Team Response Time',
            'documentation' => 'Documentation',
        ];
        return $labels[$category] ?? ucwords(str_replace('_', ' ', $category));
    }

    /**
     * Score multiple drills for comparison
     */
    public function scoreBatch(Collection $drills): array
    {
        return $drills->map(fn($drill) => $this->scoreDrill($drill))->all();
    }

    /**
     * Generate building drill history report
     */
    public function getBuildingDrillHistory(int $buildingId, int $limit = 12): array
    {
        $drills = EvacuationDrill::where('building_id', $buildingId)
            ->where('status', 'completed')
            ->orderBy('scheduled_at', 'desc')
            ->limit($limit)
            ->get();

        return [
            'building_id' => $buildingId,
            'total_drills' => $drills->count(),
            'drills' => $drills->map(fn($drill) => [
                'id' => $drill->id,
                'date' => $drill->scheduled_at?->toDateString(),
                'type' => $drill->drill_type,
                'score' => $this->scoreDrill($drill),
            ])->all(),
            'average_score' => $drills->isNotEmpty()
                ? round($drills->map(fn($d) => $this->scoreDrill($d)['total_score'])->avg(), 1)
                : null,
        ];
    }
}
