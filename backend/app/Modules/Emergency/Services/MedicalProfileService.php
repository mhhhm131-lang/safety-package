<?php

namespace App\Modules\Emergency\Services;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyMedicalProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MedicalProfileService
{
    /**
     * Get or create a medical profile for a user
     */
    public function getOrCreate(int $userId): EmergencyMedicalProfile
    {
        return EmergencyMedicalProfile::firstOrCreate(
            ['user_id' => $userId],
            ['share_with_responders' => true, 'share_with_medical_team' => true]
        );
    }

    /**
     * Update a medical profile
     */
    public function update(EmergencyMedicalProfile $profile, array $data): EmergencyMedicalProfile
    {
        return DB::transaction(function () use ($profile, $data) {
            $profile->update($data);

            // Mark as unverified if significant medical info changed
            $significantFields = ['allergies', 'chronic_conditions', 'current_medications', 'blood_type'];
            $changedSignificant = collect($significantFields)->contains(fn($field) => isset($data[$field]));

            if ($changedSignificant && $profile->is_verified) {
                $profile->update(['is_verified' => false, 'verified_at' => null, 'verified_by_id' => null]);
            }

            Log::info("Medical profile updated", ['profile_id' => $profile->id, 'user_id' => $profile->user_id]);

            return $profile->fresh();
        });
    }

    /**
     * Get all users needing evacuation assistance in a building
     */
    public function getUsersNeedingAssistance(?int $buildingId = null): Collection
    {
        $query = EmergencyMedicalProfile::query()
            ->where('needs_evacuation_assistance', true)
            ->where('share_with_responders', true)
            ->with('user');

        // If building specified, filter by users in that building
        // This would require user-building association which may vary by implementation
        // For now, return all users needing assistance

        return $query->get()->map(fn($profile) => [
            'user_id' => $profile->user_id,
            'user_name' => $profile->user?->name,
            'mobility_level' => $profile->mobility_level,
            'mobility_label' => $profile->getMobilityLevelLabel(),
            'assistance_requirements' => $profile->assistance_requirements,
            'uses_wheelchair' => $profile->uses_wheelchair,
            'uses_cane_walker' => $profile->uses_cane_walker,
            'evacuation_instructions' => $profile->evacuation_instructions,
            'emergency_contact' => $profile->getPrimaryEmergencyContact(),
        ]);
    }

    /**
     * Get users with specific blood type (for blood donation emergencies)
     */
    public function getUsersByBloodType(string $bloodType): Collection
    {
        return EmergencyMedicalProfile::query()
            ->where('blood_type', $bloodType)
            ->where('share_with_medical_team', true)
            ->with('user')
            ->get();
    }

    /**
     * Get users with medical conditions that responders should know about
     */
    public function getCriticalMedicalInfo(): Collection
    {
        return EmergencyMedicalProfile::query()
            ->where('share_with_responders', true)
            ->where(function ($query) {
                $query->where('has_pacemaker', true)
                    ->orWhereNotNull('allergies')
                    ->orWhere('mobility_level', '!=', EmergencyMedicalProfile::MOBILITY_FULL)
                    ->orWhereNotNull('medical_notes');
            })
            ->with('user')
            ->get();
    }

    /**
     * Get medical profile for emergency responders (limited data)
     */
    public function getForResponders(int $userId): ?array
    {
        $profile = EmergencyMedicalProfile::where('user_id', $userId)
            ->where('share_with_responders', true)
            ->first();

        if (!$profile) {
            return null;
        }

        return $profile->getQuickInfoForResponders();
    }

    /**
     * Get statistics about medical profiles
     */
    public function getStats(): array
    {
        $profiles = EmergencyMedicalProfile::all();

        return [
            'total_profiles' => $profiles->count(),
            'with_allergies' => $profiles->filter(fn($p) => !empty($p->allergies))->count(),
            'with_chronic_conditions' => $profiles->filter(fn($p) => !empty($p->chronic_conditions))->count(),
            'needs_assistance' => $profiles->where('needs_evacuation_assistance', true)->count(),
            'uses_wheelchair' => $profiles->where('uses_wheelchair', true)->count(),
            'has_pacemaker' => $profiles->where('has_pacemaker', true)->count(),
            'verified' => $profiles->where('is_verified', true)->count(),
            'blood_types' => $profiles->whereNotNull('blood_type')
                ->groupBy('blood_type')
                ->map(fn($group) => $group->count()),
        ];
    }

    /**
     * Verify a medical profile
     */
    public function verify(EmergencyMedicalProfile $profile, int $verifiedById): void
    {
        $profile->verify($verifiedById);

        Log::info("Medical profile verified", [
            'profile_id' => $profile->id,
            'verified_by' => $verifiedById,
        ]);
    }

    /**
     * Mark profile as reviewed
     */
    public function markReviewed(EmergencyMedicalProfile $profile, int $reviewedById): void
    {
        $profile->markReviewed($reviewedById);
    }

    /**
     * Get profiles that need review (not reviewed in last 6 months)
     */
    public function getProfilesNeedingReview(): Collection
    {
        return EmergencyMedicalProfile::query()
            ->where(function ($query) {
                $query->whereNull('last_reviewed_at')
                    ->orWhere('last_reviewed_at', '<', now()->subMonths(6));
            })
            ->with('user')
            ->get();
    }

    /**
     * Generate emergency card data for a user
     */
    public function generateEmergencyCard(int $userId): ?array
    {
        $profile = EmergencyMedicalProfile::where('user_id', $userId)->with('user')->first();

        if (!$profile) {
            return null;
        }

        return $profile->getEmergencyCardData();
    }

    /**
     * Bulk import medical profiles
     */
    public function bulkImport(array $profiles): array
    {
        $results = ['imported' => 0, 'failed' => 0, 'errors' => []];

        foreach ($profiles as $index => $data) {
            try {
                $user = User::where('email', $data['email'] ?? null)
                    ->orWhere('id', $data['user_id'] ?? null)
                    ->first();

                if (!$user) {
                    $results['failed']++;
                    $results['errors'][] = "Row {$index}: User not found";
                    continue;
                }

                $profile = $this->getOrCreate($user->id);

                $this->update($profile, $data);

                $results['imported']++;
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = "Row {$index}: " . $e->getMessage();
            }
        }

        return $results;
    }
}
